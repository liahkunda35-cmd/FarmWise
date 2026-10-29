<?php

/**
 * Safe cancellation / reversal rules for inventory, sales, and finance.
 * Never silently erases history — uses compensating records + reversals audit table.
 */
class ReversalService
{
    /**
     * Whether a hard delete is allowed. Returns error message or null if OK.
     */
    public static function validateDelete(string $table, array $row): ?string
    {
        $id = (int) ($row['id'] ?? 0);
        return match ($table) {
            'stock_movements' => 'Stock movements cannot be deleted. Record a compensating movement (or use Reverse) to keep an audit trail.',
            'payments' => 'Payments cannot be deleted. Use a refund/reversal so cash and ledger stay consistent.',
            'dispatches' => 'Dispatches cannot be deleted after stock has left storage. Create a return/spoilage adjustment on the storage lot if produce was returned.',
            'transactions' => self::txnDeleteMessage($row),
            'invoices' => self::invoiceDeleteMessage($row),
            'sales_orders' => self::salesOrderDeleteMessage($row),
            'fertilizer_applications', 'pest_treatments', 'livestock_feed' => self::usageDeleteMessage($table, $id),
            'harvests' => self::harvestDeleteMessage($id),
            'storage_lots' => self::storageLotDeleteMessage($id),
            'purchase_orders' => self::poDeleteMessage($row),
            default => null,
        };
    }

    private static function txnDeleteMessage(array $row): ?string
    {
        $ref = (string) ($row['reference'] ?? '');
        if ($ref !== '' && (str_starts_with($ref, 'payments#') || str_starts_with($ref, 'reversal:'))) {
            return 'Ledger entries linked to payments or reversals cannot be deleted. Post a compensating transaction instead.';
        }
        return 'Posted financial transactions should not be deleted. Void via a compensating entry to preserve history.';
    }

    private static function invoiceDeleteMessage(array $row): ?string
    {
        $paid = (float) db()->value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?', [$row['id']]);
        if ($paid > 0) {
            return 'This invoice has payments. Refund or reverse payments before removing the invoice.';
        }
        if (in_array((string) ($row['status'] ?? ''), ['paid', 'partial'], true)) {
            return 'Paid or partially paid invoices cannot be deleted. Mark void only after clearing payments.';
        }
        return null;
    }

    private static function salesOrderDeleteMessage(array $row): ?string
    {
        $id = (int) $row['id'];
        $inv = db()->first('SELECT id, status FROM invoices WHERE sales_order_id = ?', [$id]);
        if ($inv) {
            return 'This sales order has an invoice. Cancel the order (status=cancelled) instead of deleting, or void the unpaid invoice first.';
        }
        $disp = db()->first('SELECT id FROM dispatches WHERE sales_order_id = ?', [$id]);
        if ($disp) {
            return 'This sales order has dispatches. Cancel rather than delete so dispatch history remains.';
        }
        return null;
    }

    private static function usageDeleteMessage(string $table, int $id): ?string
    {
        $ref = $table . '#' . $id;
        $move = db()->first('SELECT id FROM stock_movements WHERE reference = ?', [$ref]);
        if ($move) {
            return 'This usage already deducted inventory. Reverse the stock movement first; do not delete the usage record.';
        }
        return null;
    }

    private static function harvestDeleteMessage(int $id): ?string
    {
        $lot = db()->first('SELECT id, quantity, spoilage FROM storage_lots WHERE harvest_id = ?', [$id]);
        if (!$lot) {
            return null;
        }
        $disp = db()->first('SELECT id FROM dispatches WHERE storage_lot_id = ?', [$lot['id']]);
        if ($disp) {
            return 'Harvest has storage that was dispatched. Deletion is blocked to protect stock history.';
        }
        if ((float) ($lot['quantity'] ?? 0) > 0 || (float) ($lot['spoilage'] ?? 0) > 0) {
            return 'Harvest is linked to a storage lot. Remove or empty the lot first, or keep the harvest for traceability.';
        }
        return null;
    }

    private static function storageLotDeleteMessage(int $id): ?string
    {
        $disp = db()->first('SELECT id FROM dispatches WHERE storage_lot_id = ?', [$id]);
        if ($disp) {
            return 'Storage lot has dispatches and cannot be deleted.';
        }
        $so = db()->first('SELECT id FROM sales_orders WHERE storage_lot_id = ? AND status != ?', [$id, 'cancelled']);
        if ($so) {
            return 'Storage lot is linked to an active sales order.';
        }
        return null;
    }

    private static function poDeleteMessage(array $row): ?string
    {
        if ((string) ($row['status'] ?? '') === 'received') {
            return 'Received purchase orders cannot be deleted (stock may already be in inventory). Cancel or adjust stock with a compensating movement.';
        }
        return null;
    }

    /**
     * Cancel sales order: void unpaid invoice; block if paid without refund.
     * @return string|null error
     */
    public static function validateSalesOrderCancel(array $payload, ?array $previous): ?string
    {
        $now = (string) ($payload['status'] ?? '');
        $was = (string) ($previous['status'] ?? '');
        if ($now !== 'cancelled' || $was === 'cancelled') {
            return null;
        }
        $id = (int) ($previous['id'] ?? $payload['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $inv = db()->first('SELECT * FROM invoices WHERE sales_order_id = ?', [$id]);
        if (!$inv) {
            return null;
        }
        $paid = (float) db()->value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?', [$inv['id']]);
        if ($paid > 0.0001) {
            return 'Cannot cancel: invoice has payments totaling ' . $paid . '. Record a refund/reversal first.';
        }
        return null;
    }

    /** Apply cancel side effects (void unpaid invoice). Idempotent. */
    public static function applySalesOrderCancel(array $payload, int $soId, ?array $previous): void
    {
        $now = (string) ($payload['status'] ?? '');
        $was = (string) ($previous['status'] ?? '');
        if ($now !== 'cancelled' || $was === 'cancelled') {
            return;
        }
        $ref = 'reversal:so_cancel#' . $soId;
        if (self::alreadyRecorded($ref)) {
            return;
        }
        $inv = db()->first('SELECT * FROM invoices WHERE sales_order_id = ?', [$soId]);
        if ($inv) {
            $paid = (float) db()->value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?', [$inv['id']]);
            if ($paid <= 0.0001 && (string) ($inv['status'] ?? '') !== 'void') {
                db()->update('invoices', [
                    'status' => 'void',
                    'notes' => trim(($inv['notes'] ?? '') . "\nVoided on sales order cancel #" . $soId),
                ], $inv['id']);
            }
        }
        self::record([
            'farm_id' => (int) ($payload['farm_id'] ?? $previous['farm_id'] ?? 0),
            'module' => 'sales_orders',
            'record_id' => $soId,
            'action' => 'cancel',
            'reason' => 'Sales order cancelled',
            'reference' => $ref,
            'related_reference' => $inv ? ('invoices#' . $inv['id']) : null,
        ]);
    }

    /**
     * Reverse a stock movement with a compensating opposite movement.
     * @return array{ok:bool,error?:string,movement_id?:int}
     */
    public static function reverseStockMovement(int $movementId, string $reason): array
    {
        $row = db()->find('stock_movements', $movementId);
        if (!$row) {
            return ['ok' => false, 'error' => 'Stock movement not found.'];
        }
        if (!Auth::canAccessRecord($row)) {
            return ['ok' => false, 'error' => 'Access denied.'];
        }
        $origRef = (string) ($row['reference'] ?? ('stock_movements#' . $movementId));
        $revRef = 'reversal:stock#' . $movementId;
        if (db()->first('SELECT id FROM stock_movements WHERE reference = ?', [$revRef])) {
            return ['ok' => false, 'error' => 'This movement was already reversed.'];
        }
        if (self::alreadyRecorded($revRef)) {
            return ['ok' => false, 'error' => 'This movement was already reversed.'];
        }
        $type = (string) ($row['movement_type'] ?? '');
        $compType = $type === 'in' ? 'out' : ($type === 'out' ? 'in' : 'adjustment');
        $qty = (float) ($row['quantity'] ?? 0);
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'Nothing to reverse.'];
        }
        if ($compType === 'out') {
            $item = db()->find('inventory_items', $row['inventory_item_id']);
            if (!$item || (float) $item['quantity'] < $qty) {
                return ['ok' => false, 'error' => 'Insufficient stock to reverse an inbound movement.'];
            }
        }
        $payload = [
            'farm_id' => $row['farm_id'],
            'inventory_item_id' => $row['inventory_item_id'],
            'movement_type' => $compType === 'adjustment' ? 'adjustment' : $compType,
            'quantity' => $compType === 'adjustment' ? max(0, (float) (db()->find('inventory_items', $row['inventory_item_id'])['quantity'] ?? 0) - ($type === 'in' ? $qty : -$qty)) : $qty,
            'movement_date' => date('Y-m-d'),
            'reference' => $revRef,
            'notes' => 'Reversal of movement #' . $movementId . ($reason !== '' ? (': ' . $reason) : ''),
            'created_by' => Auth::id(),
        ];
        if ($compType === 'adjustment') {
            $item = db()->find('inventory_items', $row['inventory_item_id']);
            $cur = (float) ($item['quantity'] ?? 0);
            $payload['quantity'] = $type === 'in' ? max(0, $cur - $qty) : ($cur + $qty);
            $payload['movement_type'] = 'adjustment';
        }
        try {
            db()->beginTransaction();
            $newId = (int) db()->insert('stock_movements', $payload);
            $def = module_def('stock_movements');
            OperationHooks::afterSave($def, $payload, $newId, true, null);
            self::record([
                'farm_id' => (int) $row['farm_id'],
                'module' => 'stock_movements',
                'record_id' => $movementId,
                'action' => 'reverse',
                'reason' => $reason !== '' ? $reason : 'Stock movement reversed',
                'reference' => $revRef,
                'related_reference' => $origRef,
            ]);
            db()->commit();
            return ['ok' => true, 'movement_id' => $newId];
        } catch (Throwable $e) {
            db()->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Refund a payment: negative payment + reverse income ledger (idempotent).
     * @return array{ok:bool,error?:string,payment_id?:int}
     */
    public static function refundPayment(int $paymentId, string $reason): array
    {
        $pay = db()->find('payments', $paymentId);
        if (!$pay) {
            return ['ok' => false, 'error' => 'Payment not found.'];
        }
        if (!Auth::canAccessRecord($pay)) {
            return ['ok' => false, 'error' => 'Access denied.'];
        }
        $amount = (float) ($pay['amount'] ?? 0);
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'Only positive payments can be refunded.'];
        }
        $revRef = 'reversal:payment#' . $paymentId;
        if (db()->first('SELECT id FROM payments WHERE reference = ?', [$revRef])) {
            return ['ok' => false, 'error' => 'This payment was already refunded.'];
        }
        if (self::alreadyRecorded($revRef)) {
            return ['ok' => false, 'error' => 'This payment was already refunded.'];
        }
        $payload = [
            'farm_id' => $pay['farm_id'],
            'invoice_id' => $pay['invoice_id'],
            'customer_id' => $pay['customer_id'],
            'payment_date' => date('Y-m-d'),
            'amount' => -1 * $amount,
            'method' => $pay['method'] ?? 'refund',
            'reference' => $revRef,
            'notes' => 'Refund of payment #' . $paymentId . ($reason !== '' ? (': ' . $reason) : ''),
        ];
        try {
            db()->beginTransaction();
            $newId = (int) db()->insert('payments', $payload);
            // Reverse income ledger (expense offset) with unique reference — do not re-run finance_income on negative
            $txnRef = 'reversal:payments#' . $paymentId;
            if (!db()->first('SELECT id FROM transactions WHERE reference = ?', [$txnRef])) {
                db()->insert('transactions', [
                    'farm_id' => $pay['farm_id'],
                    'txn_date' => date('Y-m-d'),
                    'type' => 'expense',
                    'category' => 'sales_refund',
                    'description' => 'Refund of payment #' . $paymentId,
                    'amount' => $amount,
                    'reference' => $txnRef,
                    'related_module' => 'payments',
                    'related_id' => $newId,
                ]);
            }
            $def = module_def('payments');
            // mark_invoice only — skip income hook for negative by calling mark path
            if (!empty($pay['invoice_id'])) {
                $inv = db()->find('invoices', $pay['invoice_id']);
                if ($inv) {
                    $paid = (float) db()->value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?', [$inv['id']]);
                    $status = $paid >= (float) $inv['amount'] - 0.01 ? 'paid' : ($paid > 0.01 ? 'partial' : 'unpaid');
                    if ((string) ($inv['status'] ?? '') !== 'void') {
                        db()->update('invoices', ['status' => $status], $inv['id']);
                    }
                }
            }
            self::record([
                'farm_id' => (int) $pay['farm_id'],
                'module' => 'payments',
                'record_id' => $paymentId,
                'action' => 'refund',
                'reason' => $reason !== '' ? $reason : 'Payment refunded',
                'reference' => $revRef,
                'related_reference' => 'payments#' . $paymentId,
            ]);
            unset($def);
            db()->commit();
            return ['ok' => true, 'payment_id' => $newId];
        } catch (Throwable $e) {
            db()->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function alreadyRecorded(string $reference): bool
    {
        try {
            return (bool) db()->first('SELECT id FROM reversals WHERE reference = ?', [$reference]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @param array{farm_id:int,module:string,record_id:int,action:string,reason:string,reference:string,related_reference?:?string} $data */
    public static function record(array $data): void
    {
        try {
            db()->insert('reversals', [
                'farm_id' => $data['farm_id'] ?: null,
                'module' => $data['module'],
                'record_id' => $data['record_id'],
                'action' => $data['action'],
                'reason' => $data['reason'],
                'reference' => $data['reference'],
                'related_reference' => $data['related_reference'] ?? null,
                'created_by' => Auth::id(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // Table may not exist until migrate — still allow business action
        }
        try {
            Audit::log($data['action'], $data['module'], $data['record_id'], $data['reason'] . ' [' . $data['reference'] . ']');
        } catch (Throwable $e) {
        }
    }
}
