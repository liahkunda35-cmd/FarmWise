<?php

class DocumentService
{
    public static function isDocument(string $module): bool
    {
        return in_array($module, ['invoices', 'quotations', 'purchase_orders', 'payroll'], true);
    }

    public static function payload(string $module, array $row): array
    {
        $farm = !empty($row['farm_id']) ? db()->find('farms', $row['farm_id']) : null;
        $doc = [
            'module' => $module,
            'title' => ['invoices' => 'Invoice', 'quotations' => 'Quotation', 'purchase_orders' => 'Purchase Order', 'payroll' => 'Pay Slip'][$module] ?? 'Document',
            'number' => $row['invoice_number'] ?? $row['quote_number'] ?? $row['po_number'] ?? ($module === 'payroll' ? ('PS-' . $row['id']) : ('#' . $row['id'])),
            'farm' => $farm,
            'party_label' => $module === 'invoices' ? 'Bill to' : ($module === 'payroll' ? 'Employee' : 'Supplier'),
            'party' => null,
            'issue_date' => $row['invoice_date'] ?? $row['quote_date'] ?? $row['order_date'] ?? $row['paid_date'] ?? $row['period_end'] ?? null,
            'due_date' => $row['due_date'] ?? $row['valid_until'] ?? $row['period_end'] ?? null,
            'status' => $row['status'] ?? '',
            'payment_status' => $row['payment_status'] ?? '',
            'notes' => $row['notes'] ?? $row['calculation_note'] ?? '',
            'items' => [],
            'subtotal' => 0.0,
            'total' => 0.0,
            'payments' => [],
            'paid' => 0.0,
            'balance' => 0.0,
            'period_start' => $row['period_start'] ?? null,
            'period_end' => $row['period_end'] ?? null,
            'days_worked' => $row['days_worked'] ?? null,
            'deductions' => $row['deductions'] ?? null,
            'gross_pay' => $row['gross_pay'] ?? null,
            'net_pay' => $row['net_pay'] ?? null,
        ];

        if ($module === 'payroll') {
            $employee = db()->find('employees', $row['employee_id'] ?? 0);
            $doc['party'] = [
                'name' => $employee['name'] ?? '—',
                'contact_person' => $employee['role_title'] ?? '',
                'phone' => $employee['phone'] ?? '',
                'email' => $employee['email'] ?? '',
                'address' => '',
            ];
            $days = (float) ($row['days_worked'] ?? 0);
            $gross = (float) ($row['gross_pay'] ?? 0);
            $deductions = (float) ($row['deductions'] ?? 0);
            $net = (float) ($row['net_pay'] ?? ($gross - $deductions));
            $rate = $days > 0 ? round($gross / $days, 2) : (float) ($employee['daily_rate'] ?? 0);
            $doc['items'][] = [
                'description' => 'Wages ' . dater($row['period_start'] ?? null) . ' – ' . dater($row['period_end'] ?? null)
                    . ($days > 0 ? ' · ' . $days . ' day(s)' : ''),
                'quantity' => $days > 0 ? $days : 1,
                'unit_price' => $rate,
                'total' => $gross,
            ];
            if ($deductions > 0) {
                $doc['items'][] = [
                    'description' => 'Deductions',
                    'quantity' => 1,
                    'unit_price' => -$deductions,
                    'total' => -$deductions,
                ];
            }
            $doc['subtotal'] = $gross;
            $doc['total'] = $net;
            $doc['paid'] = ((string) ($row['status'] ?? '') === 'paid') ? $net : 0.0;
            $doc['balance'] = round($net - $doc['paid'], 2);
            return $doc;
        }

        if ($module === 'invoices') {
            $doc['party'] = db()->find('customers', $row['customer_id'] ?? 0);
            $order = !empty($row['sales_order_id']) ? db()->find('sales_orders', $row['sales_order_id']) : null;
            $qty = $order ? (float) $order['quantity'] : 1;
            $unit = $order ? (float) $order['unit_price'] : (float) $row['amount'];
            $lineTotal = round($qty * $unit, 2);
            $doc['items'][] = [
                'description' => $order['crop_name'] ?? ('Invoice ' . $doc['number']),
                'quantity' => $qty,
                'unit_price' => $unit,
                'total' => $lineTotal,
            ];
            $doc['subtotal'] = $lineTotal;
            $doc['total'] = $lineTotal;
            $doc['payments'] = db()->select('SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date, id', [$row['id']]);
            foreach ($doc['payments'] as $payment) {
                $doc['paid'] += (float) $payment['amount'];
            }
            $doc['balance'] = round($doc['total'] - $doc['paid'], 2);
        } else {
            $doc['party'] = db()->find('suppliers', $row['supplier_id'] ?? 0);
            $qty = (float) ($row['quantity'] ?? 0);
            $unit = (float) ($row['unit_price'] ?? 0);
            $lineTotal = round($qty * $unit, 2);
            $doc['items'][] = [
                'description' => $row['item_name'] ?? $doc['title'],
                'quantity' => $qty,
                'unit_price' => $unit,
                'total' => $lineTotal,
            ];
            $doc['subtotal'] = $lineTotal;
            $doc['total'] = $lineTotal;
        }

        return $doc;
    }

    public static function pdfBytes(array $doc): string
    {
        $farm = $doc['farm'] ?? [];
        $party = $doc['party'] ?? [];
        $lines = [
            ['style' => 'title', 'text' => ($farm['name'] ?? config('name')) . ' — ' . $doc['title'] . ' ' . $doc['number']],
            ['style' => 'muted', 'text' => trim(($farm['location'] ?? '') . ' ' . ($farm['phone'] ?? '') . ' ' . ($farm['email'] ?? ''))],
            ['style' => 'spacer', 'text' => ''],
            ['style' => 'heading', 'text' => $doc['party_label'] . ': ' . ($party['name'] ?? '—')],
            ['style' => 'body', 'text' => trim(($party['contact_person'] ?? '') . ' ' . ($party['phone'] ?? '') . ' ' . ($party['email'] ?? ''))],
            ['style' => 'body', 'text' => 'Issue date: ' . dater($doc['issue_date']) . '    Due / valid: ' . dater($doc['due_date']) . '    Status: ' . $doc['status']],
            ['style' => 'spacer', 'text' => ''],
            ['style' => 'heading', 'text' => 'Item  |  Qty  |  Unit price  |  Total'],
        ];
        foreach ($doc['items'] as $item) {
            $lines[] = ['style' => 'body', 'text' => $item['description'] . '  |  ' . qty($item['quantity']) . '  |  ' . money($item['unit_price']) . '  |  ' . money($item['total'])];
        }
        $lines[] = ['style' => 'heading', 'text' => 'Total: ' . money($doc['total']) . ' ' . app_currency()];
        if ($doc['module'] === 'invoices') {
            $lines[] = ['style' => 'body', 'text' => 'Paid: ' . money($doc['paid']) . '    Balance: ' . money($doc['balance'])];
        }
        if ($doc['notes']) {
            $lines[] = ['style' => 'muted', 'text' => 'Notes: ' . $doc['notes']];
        }
        return Pdf::fromLines($doc['title'] . ' ' . $doc['number'], $lines);
    }

    public static function filename(array $doc): string
    {
        $safe = preg_replace('/[^A-Za-z0-9\-]+/', '-', $doc['number']) ?: 'document';
        return strtolower($doc['module']) . '-' . $safe . '.pdf';
    }

    public static function email(array $doc, string $to, string $message = ''): array
    {
        $pdf = self::pdfBytes($doc);
        $name = self::filename($doc);
        $subject = $doc['title'] . ' ' . $doc['number'] . ' from ' . ($doc['farm']['name'] ?? config('name'));
        $body = trim($message) !== '' ? $message : ('Please find attached ' . $doc['title'] . ' ' . $doc['number'] . '.');
        $dir = dirname(__DIR__, 2) . '/storage/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . $name;
        file_put_contents($path, $pdf);
        try {
            $result = Mailer::send($to, $subject, $body, $path, $name);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        db()->insert('outbound_messages', [
            'farm_id' => $doc['farm']['id'] ?? Auth::farmId(),
            'user_id' => Auth::id(),
            'channel' => 'email',
            'recipient' => $to,
            'subject' => $subject,
            'body' => $body,
            'status' => !empty($result['ok']) ? 'sent' : 'failed',
            'error' => $result['error'] ?? null,
            'sent_at' => !empty($result['ok']) ? date('Y-m-d H:i:s') : null,
        ]);
        return $result;
    }
}
