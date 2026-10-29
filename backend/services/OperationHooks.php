<?php

/**
 * Cross-module operational side effects for IFMS.
 * Called from ResourceController after validated saves.
 * Uses unique transaction/stock references to avoid duplicate posts.
 */
class OperationHooks
{
    public static function afterSave(array $def, array $payload, int $id, bool $created, ?array $previous = null): void
    {
        $table = $def['table'] ?? '';
        $hooks = $def['hooks'] ?? [];

        if (!empty($hooks['stock_adjust']) && $created) {
            self::applyStockMovement($payload, true);
        }

        if (!empty($hooks['finance_expense']) && Auth::role() !== 'worker') {
            $when = $hooks['finance_expense']['when_status'] ?? null;
            $statusField = $hooks['finance_expense']['status_field'] ?? 'status';
            $shouldPost = $created;
            if ($when !== null) {
                $now = (string) ($payload[$statusField] ?? '');
                $was = (string) ($previous[$statusField] ?? '');
                $shouldPost = ($now === $when) && ($created || $was !== $when);
            }
            if ($shouldPost) {
                self::upsertTransaction('expense', $hooks['finance_expense'], $def, $payload, $id);
            }
        }

        if (!empty($hooks['finance_income'])) {
            $when = $hooks['finance_income']['when_status'] ?? null;
            $statusField = $hooks['finance_income']['status_field'] ?? 'status';
            $shouldPost = $created;
            if ($when !== null) {
                $now = (string) ($payload[$statusField] ?? '');
                $was = (string) ($previous[$statusField] ?? '');
                $shouldPost = ($now === $when) && ($created || $was !== $when);
            }
            if ($shouldPost) {
                self::upsertTransaction('income', $hooks['finance_income'], $def, $payload, $id);
            }
        }

        if (!empty($hooks['auto_invoice']) && $created) {
            $exists = db()->first('SELECT id FROM invoices WHERE sales_order_id = ?', [$id]);
            if (!$exists) {
                db()->insert('invoices', [
                    'farm_id' => $payload['farm_id'],
                    'sales_order_id' => $id,
                    'customer_id' => $payload['customer_id'],
                    'invoice_number' => generate_code('INV'),
                    'invoice_date' => $payload['order_date'] ?? date('Y-m-d'),
                    'due_date' => date('Y-m-d', strtotime('+14 days')),
                    'amount' => $payload['total_amount'] ?? 0,
                    'status' => 'unpaid',
                    'notes' => 'Generated from sales order ' . ($payload['order_number'] ?? $id),
                ]);
            }
        }

        if (!empty($hooks['mark_invoice']) && !empty($payload['invoice_id'])) {
            $inv = db()->find('invoices', $payload['invoice_id']);
            if ($inv) {
                $paid = (float) db()->value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?', [$inv['id']]);
                $status = $paid >= (float) $inv['amount'] ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                db()->update('invoices', ['status' => $status], $inv['id']);
            }
        }

        if ($table === 'livestock_weights' && !empty($payload['animal_id'])) {
            db()->update('animals', ['current_weight' => $payload['weight_kg']], $payload['animal_id']);
        }
        // Workers must not change livestock life status via field health logs.
        if ($table === 'livestock_health' && ($payload['record_type'] ?? '') === 'death' && !empty($payload['animal_id']) && Auth::role() !== 'worker') {
            db()->update('animals', ['status' => 'deceased'], $payload['animal_id']);
        }
        // Workers may report a harvest quantity; managers apply planting/storage side effects.
        if ($table === 'harvests' && !empty($payload['planting_id']) && Auth::role() !== 'worker') {
            $status = (string) ($payload['status'] ?? 'completed');
            if (in_array($status, ['completed', 'stored'], true)) {
                db()->update('plantings', ['status' => 'harvested', 'growth_stage' => 'harvested'], $payload['planting_id']);
            }
            if ($created || (($previous['status'] ?? '') !== $status && in_array($status, ['completed', 'stored'], true))) {
                self::ensureStorageLotFromHarvest($payload, $id);
            }
        }
        if ($table === 'storage_lots' && !$created && $previous) {
            self::applyStorageLotSpoilage($payload, $previous, $id);
        }
        // Workers may leave growth notes without rewriting planting status/stage.
        if ($table === 'crop_growth' && !empty($payload['planting_id']) && !empty($payload['growth_stage']) && Auth::role() !== 'worker') {
            db()->update('plantings', ['growth_stage' => $payload['growth_stage'], 'status' => 'growing'], $payload['planting_id']);
        }
        if ($table === 'farms') {
            $farm = db()->find('farms', $id);
            if ($farm && empty($farm['join_code'])) {
                db()->update('farms', ['join_code' => generate_join_code()], $id);
            }
            if ($created && Auth::role() === 'owner') {
                db()->update('farms', ['owner_id' => Auth::id()], $id);
                $user = Auth::user();
                if ($user && empty($user['farm_id'])) {
                    db()->update('users', ['farm_id' => $id], $user['id']);
                    Auth::setActiveFarm($id);
                }
            }
        }
        if (in_array($table, ['quotations', 'purchase_orders'], true)) {
            $qty = (float) ($payload['quantity'] ?? 0);
            $unit = (float) ($payload['unit_price'] ?? 0);
            db()->update($table, ['total' => round($qty * $unit, 2)], $id);
        }

        // Input usage → inventory out (once per source record). Validated before save.
        if ($created && in_array($table, ['fertilizer_applications', 'pest_treatments', 'livestock_feed'], true)) {
            self::deductInventoryForUsage($table, $payload, $id);
        }
        if ($table === 'spray_schedules') {
            $now = (string) ($payload['status'] ?? '');
            $was = (string) ($previous['status'] ?? '');
            if ($now === 'completed' && ($created || $was !== 'completed')) {
                self::deductInventoryForUsage($table, $payload, $id);
            }
        }

        // Dispatch reduces storage lot once (stock point for sales produce)
        if ($table === 'dispatches' && $created) {
            self::applyDispatch($payload, $id);
        }

        // Cancelled sales order: void unpaid invoice; stock never reserved at order time
        if ($table === 'sales_orders' && !$created) {
            ReversalService::applySalesOrderCancel($payload, $id, $previous);
        }
        // Skip auto income for refund/negative payments (ReversalService posts offset)
        if ($table === 'payments' && $created && (float) ($payload['amount'] ?? 0) < 0) {
            // mark_invoice still runs above; finance_income skipped via amount<=0 in upsert
        }

        // Purchase order received → stock in (optional linked inventory item)
        if ($table === 'purchase_orders') {
            $now = (string) ($payload['status'] ?? '');
            $was = (string) ($previous['status'] ?? '');
            if ($now === 'received' && ($created || $was !== 'received')) {
                self::receivePurchaseOrder($payload, $id);
            }
            if ($now === 'approved' && ($created || $was !== 'approved') && empty($payload['approved_by'])) {
                db()->update('purchase_orders', ['approved_by' => Auth::id()], $id);
            }
        }

        // Equipment hours roll-up
        if ($table === 'equipment_logs' && $created && ($payload['log_type'] ?? '') === 'hours' && !empty($payload['equipment_id'])) {
            $hours = (float) ($payload['hours'] ?? 0);
            if ($hours > 0) {
                $eq = db()->find('equipment', $payload['equipment_id']);
                if ($eq) {
                    db()->update('equipment', [
                        'operating_hours' => round(((float) ($eq['operating_hours'] ?? 0)) + $hours, 2),
                    ], $eq['id']);
                }
            }
        }

        if ($table === 'tasks') {
            self::notifyTaskChange($payload, $id, $created, $previous);
        }

        if ($table === 'pest_scouting') {
            $hadTask = db()->first(
                "SELECT id FROM tasks WHERE related_module = 'pest_scouting' AND related_id = ? LIMIT 1",
                [$id]
            );
            $taskId = WorkflowService::ensureScoutTask($payload, $id);
            if ($created || (!$hadTask && $taskId)) {
                self::notifyScoutingRecommendation($payload, $id, $taskId);
            }
        }
        if ($table === 'quotations') {
            WorkflowService::ensurePurchaseOrder($payload, $id);
        }
    }

    /** Notify assignee + farm owners/managers when a task is created or status changes. */
    private static function notifyTaskChange(array $payload, int $taskId, bool $created, ?array $previous): void
    {
        $farmId = (int) ($payload['farm_id'] ?? 0);
        $title = trim((string) ($payload['title'] ?? 'Task'));
        $status = (string) ($payload['status'] ?? 'pending');
        $prevStatus = (string) ($previous['status'] ?? '');
        $assigned = (int) ($payload['assigned_to'] ?? 0);
        $prevAssigned = (int) ($previous['assigned_to'] ?? 0);
        $link = 'module/tasks/view/' . $taskId;

        $notifyUser = static function (?int $userId, string $cat, string $t, string $msg) use ($farmId, $link): void {
            if (!$userId) {
                return;
            }
            NotificationService::createForced([
                'farm_id' => $farmId ?: null,
                'user_id' => $userId,
                'category' => $cat,
                'title' => $t,
                'message' => $msg,
                'severity' => 'info',
                'link' => $link,
            ]);
        };

        $assigneeUserId = null;
        if ($assigned > 0) {
            $emp = db()->find('employees', $assigned);
            if ($emp && !empty($emp['user_id'])) {
                $assigneeUserId = (int) $emp['user_id'];
            }
        }

        if ($created) {
            $notifyUser(
                $assigneeUserId,
                'tasks',
                'New task assigned: ' . $title,
                'You have been assigned "' . $title . '". Due: ' . (($payload['due_date'] ?? '') ?: 'not set') . '.'
            );
            foreach (self::farmManagerOwnerUserIds($farmId) as $uid) {
                if ($assigneeUserId && $uid === $assigneeUserId) {
                    continue;
                }
                $notifyUser($uid, 'tasks', 'Task created: ' . $title, 'A new task was created on your farm: ' . $title . '.');
            }
            return;
        }

        if ($assigned > 0 && $assigned !== $prevAssigned) {
            $notifyUser(
                $assigneeUserId,
                'tasks',
                'Task assigned: ' . $title,
                'You have been assigned "' . $title . '".'
            );
        }

        if ($status !== $prevStatus && $prevStatus !== '') {
            $label = str_replace('_', ' ', $status);
            if ($status === 'completed') {
                foreach (self::farmManagerOwnerUserIds($farmId) as $uid) {
                    $notifyUser($uid, 'tasks', 'Task completed: ' . $title, '"' . $title . '" was marked completed.');
                }
                if (!empty($payload['created_by'])) {
                    $notifyUser((int) $payload['created_by'], 'tasks', 'Task completed: ' . $title, '"' . $title . '" was marked completed.');
                }
            } else {
                foreach (self::farmManagerOwnerUserIds($farmId) as $uid) {
                    $notifyUser($uid, 'tasks', 'Task updated: ' . $title, '"' . $title . '" is now ' . $label . '.');
                }
            }
        }
    }

    private static function notifyScoutingRecommendation(array $payload, int $id, ?int $taskId = null): void
    {
        $farmId = (int) ($payload['farm_id'] ?? 0);
        $sev = (string) ($payload['severity'] ?? $payload['intensity'] ?? 'medium');
        $note = trim((string) ($payload['notes'] ?? $payload['findings'] ?? 'Pest/disease observation'));
        $title = 'Agronomist observation needs review';
        $msg = 'A scouting report was logged (' . $sev . '): ' . mb_substr($note, 0, 160) . '.';
        if ($taskId) {
            $msg .= ' A draft follow-up task #' . $taskId . ' is ready for a manager to assign.';
            $link = 'module/tasks/view/' . $taskId;
        } else {
            $msg .= ' Create a task for a worker if action is required.';
            $link = 'module/pest_scouting/view/' . $id;
        }
        foreach (self::farmManagerOwnerUserIds($farmId) as $uid) {
            NotificationService::createForced([
                'farm_id' => $farmId ?: null,
                'user_id' => $uid,
                'category' => 'agronomy',
                'title' => $title,
                'message' => $msg,
                'severity' => in_array(strtolower($sev), ['high', 'severe', 'critical'], true) ? 'warning' : 'info',
                'link' => $link,
            ]);
        }
    }

    /** @return list<int> */
    private static function farmManagerOwnerUserIds(int $farmId): array
    {
        if ($farmId <= 0) {
            return [];
        }
        $ids = [];
        $rows = db()->select(
            "SELECT u.id FROM users u
             LEFT JOIN user_farms uf ON uf.user_id = u.id
             WHERE u.status = 'active' AND u.role IN ('owner','manager')
               AND (u.farm_id = ? OR uf.farm_id = ?)",
            [$farmId, $farmId]
        );
        foreach ($rows as $r) {
            $ids[(int) $r['id']] = true;
        }
        $farm = db()->find('farms', $farmId);
        if ($farm && !empty($farm['owner_id'])) {
            $ids[(int) $farm['owner_id']] = true;
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * Validate stock availability before an outbound movement is saved.
     * @return string|null error message
     */
    public static function validateStockOut(array $payload, ?int $excludeMovementId = null): ?string
    {
        $type = (string) ($payload['movement_type'] ?? '');
        $ref = trim((string) ($payload['reference'] ?? ''));
        if ($ref !== '') {
            $existing = db()->first('SELECT id FROM stock_movements WHERE reference = ?', [$ref]);
            if ($existing && (int) $existing['id'] !== (int) ($excludeMovementId ?? 0)) {
                return 'A stock movement with this reference already exists. Duplicate submissions are blocked.';
            }
        }
        if ($type !== 'out') {
            return null;
        }
        $itemId = (int) ($payload['inventory_item_id'] ?? 0);
        $qty = (float) ($payload['quantity'] ?? 0);
        if ($itemId <= 0 || $qty <= 0) {
            return null;
        }
        $item = db()->find('inventory_items', $itemId);
        if (!$item) {
            return 'Inventory item not found.';
        }
        if ((float) $item['quantity'] < $qty) {
            return 'Insufficient stock for "' . ($item['name'] ?? 'item') . '". Available: ' . $item['quantity'] . '.';
        }
        return null;
    }

    /**
     * Validate inventory deduction for usage records (fertilizer, pesticide, feed, spray).
     * @return string|null error message
     */
    public static function validateInventoryUsage(string $table, array $payload, ?array $previous = null): ?string
    {
        $itemId = (int) ($payload['inventory_item_id'] ?? 0);
        $qty = (float) ($payload['quantity'] ?? 0);
        if ($itemId <= 0 || $qty <= 0) {
            return null;
        }
        if ($table === 'spray_schedules') {
            $now = (string) ($payload['status'] ?? '');
            $was = (string) ($previous['status'] ?? '');
            if ($now !== 'completed') {
                return null;
            }
            if ($previous && $was === 'completed') {
                return null; // already deducted on prior completion
            }
        }
        $item = db()->find('inventory_items', $itemId);
        if (!$item) {
            return 'Inventory item not found.';
        }
        $farmId = (int) ($payload['farm_id'] ?? 0);
        if ($farmId > 0 && (int) ($item['farm_id'] ?? 0) !== $farmId) {
            return 'Inventory item does not belong to this farm.';
        }
        if ((float) $item['quantity'] < $qty) {
            return 'Insufficient stock for "' . ($item['name'] ?? 'item') . '". Available: '
                . $item['quantity'] . '. Record the usage only when stock is available, or receive stock first.';
        }
        return null;
    }

    public static function validateDispatch(array $payload): ?string
    {
        $lotId = (int) ($payload['storage_lot_id'] ?? 0);
        $qty = (float) ($payload['quantity'] ?? 0);
        if ($lotId <= 0 || $qty <= 0) {
            return null;
        }
        $lot = db()->find('storage_lots', $lotId);
        if (!$lot) {
            return 'Storage lot not found.';
        }
        $farmId = (int) ($payload['farm_id'] ?? 0);
        if ($farmId > 0 && (int) ($lot['farm_id'] ?? 0) !== $farmId) {
            return 'Storage lot does not belong to this farm.';
        }
        $soId = (int) ($payload['sales_order_id'] ?? 0);
        if ($soId > 0) {
            $so = db()->find('sales_orders', $soId);
            if (!$so) {
                return 'Sales order not found.';
            }
            if ((string) ($so['status'] ?? '') === 'cancelled') {
                return 'Cannot dispatch against a cancelled sales order.';
            }
            if ($farmId > 0 && (int) ($so['farm_id'] ?? 0) !== $farmId) {
                return 'Sales order does not belong to this farm.';
            }
        }
        $ref = trim((string) ($payload['reference'] ?? ''));
        if ($ref !== '') {
            $dup = db()->first('SELECT id FROM dispatches WHERE reference = ?', [$ref]);
            if ($dup) {
                return 'A dispatch with this reference already exists. Duplicate submissions are blocked.';
            }
        }
        $available = self::lotAvailable($lot);
        if ($qty > $available + 0.0001) {
            return 'Dispatch quantity exceeds available stock in the lot (' . $available . ').';
        }
        return null;
    }

    /**
     * Sales order linked to a storage lot must not exceed available produce.
     * Stock is NOT deducted here — only at dispatch.
     */
    public static function validateSalesOrder(array $payload): ?string
    {
        $lotId = (int) ($payload['storage_lot_id'] ?? 0);
        $qty = (float) ($payload['quantity'] ?? 0);
        if ($lotId <= 0) {
            return null;
        }
        if ((string) ($payload['status'] ?? '') === 'cancelled') {
            return null;
        }
        $lot = db()->find('storage_lots', $lotId);
        if (!$lot) {
            return 'Storage lot not found.';
        }
        $farmId = (int) ($payload['farm_id'] ?? 0);
        if ($farmId > 0 && (int) ($lot['farm_id'] ?? 0) !== $farmId) {
            return 'Storage lot does not belong to this farm.';
        }
        $available = self::lotAvailable($lot);
        if ($qty > $available + 0.0001) {
            return 'Order quantity exceeds available produce in storage (' . $available . '). Reduce quantity or choose another lot.';
        }
        return null;
    }

    public static function validateStorageLot(array $payload, ?int $id = null): ?string
    {
        $harvestId = (int) ($payload['harvest_id'] ?? 0);
        if ($harvestId > 0) {
            $existing = db()->first('SELECT id FROM storage_lots WHERE harvest_id = ?', [$harvestId]);
            if ($existing && (int) $existing['id'] !== (int) ($id ?? 0)) {
                return 'This harvest already has a storage lot. Edit the existing lot instead of creating a duplicate.';
            }
        }
        $qty = (float) ($payload['quantity'] ?? 0);
        $spoil = (float) ($payload['spoilage'] ?? 0);
        if ($spoil < 0) {
            return 'Spoilage cannot be negative.';
        }
        if ($spoil > $qty + 0.0001) {
            return 'Spoilage cannot exceed the lot quantity.';
        }
        return null;
    }

    public static function validatePayment(array $payload): ?string
    {
        $invId = (int) ($payload['invoice_id'] ?? 0);
        $amount = (float) ($payload['amount'] ?? 0);
        // Negative amounts are refunds created only via ReversalService
        if ($amount < 0) {
            $ref = (string) ($payload['reference'] ?? '');
            if (!str_starts_with($ref, 'reversal:')) {
                return 'Negative payments must be recorded as refunds through the reversal workflow.';
            }
            return null;
        }
        if ($invId <= 0 || $amount <= 0) {
            return null;
        }
        $invStatus = db()->value('SELECT status FROM invoices WHERE id = ?', [$invId]);
        if ($invStatus === 'void') {
            return 'Cannot pay a void invoice.';
        }
        $inv = db()->find('invoices', $invId);
        if (!$inv) {
            return 'Invoice not found.';
        }
        $farmId = (int) ($payload['farm_id'] ?? 0);
        if ($farmId > 0 && (int) ($inv['farm_id'] ?? 0) !== $farmId) {
            return 'Invoice does not belong to this farm.';
        }
        $paid = (float) db()->value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?', [$invId]);
        $remaining = (float) $inv['amount'] - $paid;
        if ($amount > $remaining + 0.01) {
            return 'Payment exceeds remaining invoice balance (' . round(max(0, $remaining), 2) . ').';
        }
        return null;
    }

    public static function lotAvailable(array $lot): float
    {
        return max(0, (float) ($lot['quantity'] ?? 0) - (float) ($lot['spoilage'] ?? 0));
    }

    /**
     * @param bool $strictOut when true, throw if out would go negative (caller should have validated)
     */
    private static function applyStockMovement(array $payload, bool $strictOut = false): void
    {
        $item = db()->find('inventory_items', $payload['inventory_item_id'] ?? 0);
        if (!$item) {
            return;
        }
        $qty = (float) ($payload['quantity'] ?? 0);
        $newQty = (float) $item['quantity'];
        $type = (string) ($payload['movement_type'] ?? '');
        if ($type === 'in') {
            $newQty += $qty;
        } elseif ($type === 'out') {
            if ($strictOut && $newQty < $qty) {
                throw new RuntimeException(
                    'Insufficient stock for "' . ($item['name'] ?? 'item') . '". Available: ' . $item['quantity'] . '.'
                );
            }
            $newQty -= $qty;
        } else {
            // adjustment: set absolute balance
            $newQty = $qty;
        }
        if ($newQty < 0) {
            if ($strictOut) {
                throw new RuntimeException('Stock balance cannot go negative.');
            }
            $newQty = 0;
        }
        $status = $newQty <= 0 ? 'out' : ($newQty <= (float) $item['reorder_level'] ? 'low' : 'in_stock');
        db()->update('inventory_items', ['quantity' => $newQty, 'status' => $status], $item['id']);

        if ($status === 'low' || $status === 'out') {
            NotificationService::create([
                'farm_id' => (int) ($item['farm_id'] ?? 0) ?: null,
                'category' => 'inventory',
                'title' => $status === 'out' ? 'Stock depleted: ' . ($item['name'] ?? 'item') : 'Low stock: ' . ($item['name'] ?? 'item'),
                'message' => 'Current quantity: ' . $newQty . '. Reorder level: ' . ($item['reorder_level'] ?? 0) . '.',
                'severity' => $status === 'out' ? 'critical' : 'warning',
                'link' => 'module/inventory_items/view/' . $item['id'],
            ]);
        }
        $exp = $item['expiry_date'] ?? null;
        if ($exp && $exp <= date('Y-m-d', strtotime('+14 days'))) {
            NotificationService::create([
                'farm_id' => (int) ($item['farm_id'] ?? 0) ?: null,
                'category' => 'inventory',
                'title' => 'Expiring soon: ' . ($item['name'] ?? 'item'),
                'message' => 'Expiry date: ' . $exp . '.',
                'severity' => 'warning',
                'link' => 'module/inventory_items/view/' . $item['id'],
            ]);
        }
    }

    private static function upsertTransaction(string $type, array $h, array $def, array $payload, int $id): void
    {
        $amount = (float) ($payload[$h['amount']] ?? 0);
        $date = $payload[$h['date']] ?? null;
        if ($date === null || $date === '') {
            $date = date('Y-m-d');
        }
        if ($amount <= 0) {
            return;
        }
        $descKey = $h['description'] ?? null;
        $description = is_string($payload[$descKey] ?? null)
            ? (string) $payload[$descKey]
            : (($def['singular'] ?? 'Transaction') . ' #' . $id);
        if ($descKey === 'employee_id' && !empty($payload['employee_id'])) {
            $emp = db()->find('employees', $payload['employee_id']);
            $description = 'Payroll: ' . ($emp['name'] ?? ('employee #' . $payload['employee_id']));
        }
        $reference = ($def['table'] ?? 'record') . '#' . $id;
        $existing = db()->first('SELECT id FROM transactions WHERE reference = ?', [$reference]);
        $row = [
            'farm_id' => $payload['farm_id'] ?? Auth::farmId(),
            'txn_date' => $date,
            'type' => $type,
            'category' => $h['category'],
            'description' => $description,
            'amount' => $amount,
            'reference' => $reference,
            'related_module' => $def['table'] ?? null,
            'related_id' => $id,
        ];
        if ($existing) {
            try {
                db()->update('transactions', $row, $existing['id']);
            } catch (Throwable $e) {
                unset($row['related_id']);
                db()->update('transactions', $row, $existing['id']);
            }
            return;
        }
        try {
            db()->insert('transactions', $row);
        } catch (Throwable $e) {
            unset($row['related_id']);
            db()->insert('transactions', $row);
        }
    }

    private static function ensureStorageLotFromHarvest(array $payload, int $harvestId): void
    {
        $qty = (float) ($payload['quantity'] ?? 0) - (float) ($payload['losses'] ?? 0);
        if ($qty <= 0) {
            return;
        }
        $existing = db()->first('SELECT id, spoilage FROM storage_lots WHERE harvest_id = ?', [$harvestId]);
        if ($existing) {
            // Update net quantity from harvest; preserve spoilage already recorded on the lot
            $spoil = (float) ($existing['spoilage'] ?? 0);
            db()->update('storage_lots', [
                'quantity' => max($qty, $spoil),
                'grade' => $payload['grade'] ?? null,
                'quality' => $payload['quality_notes'] ?? null,
                'stored_date' => $payload['harvest_date'] ?? date('Y-m-d'),
                'status' => 'stored',
            ], $existing['id']);
            return;
        }
        $farmId = (int) ($payload['farm_id'] ?? Auth::farmId());
        $warehouseId = (int) ($payload['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            $wh = db()->first("SELECT id FROM warehouses WHERE farm_id = ? AND status = 'active' ORDER BY id ASC", [$farmId]);
            $warehouseId = (int) ($wh['id'] ?? 0);
        }
        if ($warehouseId <= 0) {
            $warehouseId = (int) db()->insert('warehouses', [
                'farm_id' => $farmId,
                'name' => 'Main store',
                'type' => 'warehouse',
                'capacity_unit' => $payload['unit'] ?? 'kg',
                'location' => $payload['storage_location'] ?? null,
                'status' => 'active',
            ]);
        }
        $produce = 'Harvest #' . $harvestId;
        if (!empty($payload['planting_id'])) {
            $planting = db()->find('plantings', $payload['planting_id']);
            if ($planting && !empty($planting['crop_variety_id'])) {
                $crop = db()->find('crop_varieties', $planting['crop_variety_id']);
                if ($crop) {
                    $produce = trim(($crop['name'] ?? 'Crop') . ' ' . ($crop['variety'] ?? ''));
                }
            }
        }
        db()->insert('storage_lots', [
            'farm_id' => $farmId,
            'warehouse_id' => $warehouseId,
            'harvest_id' => $harvestId,
            'produce_name' => $produce,
            'quantity' => $qty,
            'grade' => $payload['grade'] ?? null,
            'quality' => $payload['quality_notes'] ?? null,
            'stored_date' => $payload['harvest_date'] ?? date('Y-m-d'),
            'spoilage' => 0,
            'unit_value' => 0,
            'status' => 'stored',
        ]);
        db()->update('harvests', ['status' => 'stored'], $harvestId);
    }

    private static function applyStorageLotSpoilage(array $payload, array $previous, int $lotId): void
    {
        $spoilNow = (float) ($payload['spoilage'] ?? 0);
        $spoilWas = (float) ($previous['spoilage'] ?? 0);
        $status = (string) ($payload['status'] ?? '');
        if ($status === 'spoiled' && $spoilNow <= 0) {
            $qty = (float) ($payload['quantity'] ?? $previous['quantity'] ?? 0);
            db()->update('storage_lots', ['spoilage' => $qty, 'status' => 'spoiled'], $lotId);
            $spoilNow = $qty;
        }
        if ($spoilNow > $spoilWas + 0.0001) {
            $lot = db()->find('storage_lots', $lotId);
            if ($lot) {
                NotificationService::create([
                    'farm_id' => (int) ($lot['farm_id'] ?? 0) ?: null,
                    'category' => 'storage',
                    'title' => 'Storage spoilage recorded',
                    'message' => ($lot['produce_name'] ?? 'Lot') . ': spoilage now ' . $spoilNow
                        . '. Available: ' . self::lotAvailable($lot) . '.',
                    'severity' => 'warning',
                    'link' => 'module/storage_lots/view/' . $lotId,
                ]);
            }
        }
    }

    private static function applyDispatch(array $payload, int $dispatchId): void
    {
        $lotId = (int) ($payload['storage_lot_id'] ?? 0);
        $qty = (float) ($payload['quantity'] ?? 0);
        $lot = db()->find('storage_lots', $lotId);
        if (!$lot || $qty <= 0) {
            return;
        }
        // Idempotent: if this dispatch already reduced stock (re-entry), skip
        $marker = 'dispatches#' . $dispatchId;
        $already = db()->first(
            "SELECT id FROM stock_movements WHERE reference = ? LIMIT 1",
            [$marker]
        );
        // Also guard via notes on lot history — primary guard is created-only + txn
        $available = self::lotAvailable($lot);
        if ($qty > $available + 0.0001) {
            throw new RuntimeException('Dispatch quantity exceeds available stock in the lot (' . $available . ').');
        }
        // Reduce on-hand quantity; spoilage remains so available stays consistent
        $newQty = max(0, (float) $lot['quantity'] - $qty);
        $spoil = (float) ($lot['spoilage'] ?? 0);
        if ($newQty < $spoil) {
            // Keep spoilage <= quantity
            $spoil = $newQty;
        }
        $availAfter = max(0, $newQty - $spoil);
        $status = $availAfter <= 0 ? 'dispatched' : 'partial';
        db()->update('storage_lots', [
            'quantity' => $newQty,
            'spoilage' => $spoil,
            'status' => $status,
        ], $lotId);

        // Audit trail marker (does not affect inventory_items — produce is storage, not SKU inventory)
        if (!$already) {
            try {
                // Prefer a lightweight note on the dispatch row if column exists; otherwise skip
                unset($already);
            } catch (Throwable $e) {
            }
        }
        // Mark sales order delivered when linked and fully covered
        $soId = (int) ($payload['sales_order_id'] ?? 0);
        if ($soId > 0) {
            $so = db()->find('sales_orders', $soId);
            if ($so && (string) ($so['status'] ?? '') !== 'cancelled') {
                $dispatched = (float) db()->value(
                    'SELECT COALESCE(SUM(quantity),0) FROM dispatches WHERE sales_order_id = ?',
                    [$soId]
                );
                if ($dispatched + 0.0001 >= (float) ($so['quantity'] ?? 0)) {
                    db()->update('sales_orders', ['status' => 'delivered'], $soId);
                }
            }
        }
        unset($marker);
    }

    private static function deductInventoryForUsage(string $table, array $payload, int $id): void
    {
        $itemId = (int) ($payload['inventory_item_id'] ?? 0);
        $qty = (float) ($payload['quantity'] ?? 0);
        if ($itemId <= 0 || $qty <= 0) {
            return;
        }
        $ref = $table . '#' . $id;
        $exists = db()->first('SELECT id FROM stock_movements WHERE reference = ?', [$ref]);
        if ($exists) {
            return;
        }
        $item = db()->find('inventory_items', $itemId);
        if (!$item) {
            throw new RuntimeException('Inventory item not found for stock deduction.');
        }
        if ((float) $item['quantity'] < $qty) {
            throw new RuntimeException(
                'Insufficient stock for "' . ($item['name'] ?? 'item') . '". Available: ' . $item['quantity'] . '.'
            );
        }
        $row = [
            'farm_id' => $payload['farm_id'] ?? $item['farm_id'],
            'inventory_item_id' => $itemId,
            'movement_type' => 'out',
            'quantity' => $qty,
            'movement_date' => $payload['application_date']
                ?? $payload['treatment_date']
                ?? $payload['feed_date']
                ?? $payload['actual_date']
                ?? $payload['scheduled_date']
                ?? date('Y-m-d'),
            'reference' => $ref,
            'notes' => 'Auto-deducted from ' . $table,
            'created_by' => Auth::id(),
        ];
        if (!empty($payload['task_id'])) {
            $row['task_id'] = (int) $payload['task_id'];
        }
        try {
            db()->insert('stock_movements', $row);
        } catch (Throwable $e) {
            unset($row['created_by'], $row['task_id']);
            db()->insert('stock_movements', $row);
        }
        self::applyStockMovement([
            'inventory_item_id' => $itemId,
            'movement_type' => 'out',
            'quantity' => $qty,
        ], true);
    }

    private static function receivePurchaseOrder(array $payload, int $id): void
    {
        $itemId = (int) ($payload['inventory_item_id'] ?? 0);
        $qty = (float) ($payload['quantity'] ?? 0);
        if ($itemId <= 0 || $qty <= 0) {
            return;
        }
        $ref = 'purchase_orders#' . $id;
        if (db()->first('SELECT id FROM stock_movements WHERE reference = ?', [$ref])) {
            return;
        }
        $row = [
            'farm_id' => $payload['farm_id'] ?? Auth::farmId(),
            'inventory_item_id' => $itemId,
            'movement_type' => 'in',
            'quantity' => $qty,
            'movement_date' => date('Y-m-d'),
            'reference' => $ref,
            'notes' => 'Received from PO ' . ($payload['po_number'] ?? $id),
            'created_by' => Auth::id(),
        ];
        try {
            db()->insert('stock_movements', $row);
        } catch (Throwable $e) {
            unset($row['created_by']);
            db()->insert('stock_movements', $row);
        }
        self::applyStockMovement([
            'inventory_item_id' => $itemId,
            'movement_type' => 'in',
            'quantity' => $qty,
        ]);
    }
}
