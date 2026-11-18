<?php
declare(strict_types=1);

require_once __DIR__ . '/invoice_payment_totals.php';

function revenue_by_item_data(PDO $pdo): array
{
    $asOfDate = $pdo->query('SELECT CURDATE()')->fetchColumn();
    if (!is_string($asOfDate) || $asOfDate === '') {
        throw new RuntimeException('The current database date is unavailable.');
    }

    $query = $pdo->query("SELECT ii.product_id,ii.invoice_id,ii.quantity,ii.line_total,
            i.total invoice_total,i.currency_code,i.kes_exchange_rate,
            COALESCE(payment_totals.paid,0) paid_amount,
            COALESCE(item_totals.item_subtotal,0) item_subtotal,
            p.name,p.category,p.billing_cycle
        FROM invoice_items ii
        JOIN invoices i ON i.id=ii.invoice_id
        JOIN products p ON p.id=ii.product_id
        LEFT JOIN " . invoice_payment_totals_subquery() . " payment_totals
            ON payment_totals.invoice_id=i.id
        LEFT JOIN (SELECT invoice_id,SUM(line_total) item_subtotal FROM invoice_items GROUP BY invoice_id) item_totals
            ON item_totals.invoice_id=i.id
        WHERE i.status NOT IN ('draft','cancelled')
          AND i.total>0
          AND COALESCE(item_totals.item_subtotal,0)>0
          AND COALESCE(payment_totals.paid,0)>0
        ORDER BY p.category,p.name,p.id,i.id,ii.id");

    $items = [];
    foreach ($query->fetchAll() as $row) {
        $productId = (int) $row['product_id'];
        $invoiceTotal = (float) $row['invoice_total'];
        $paidAmount = min($invoiceTotal, (float) $row['paid_amount']);
        $currency = strtoupper((string) $row['currency_code']);
        $rate = $currency === 'KES' ? 1.0 : (float) ($row['kes_exchange_rate'] ?? 0);
        if ($currency !== 'KES' && $rate <= 0) {
            throw new RuntimeException('Collected foreign-currency revenue cannot be converted to KES because an invoice exchange rate is missing.');
        }

        $collected = ((float) $row['line_total'] * ($paidAmount / (float) $row['item_subtotal'])) * $rate;
        if ($collected <= 0) {
            continue;
        }

        if (!isset($items[$productId])) {
            $items[$productId] = [
                'name' => (string) $row['name'],
                'category' => (string) $row['category'],
                'billing' => match (strtolower((string) $row['billing_cycle'])) {
                    'yearly' => 'Yearly',
                    'monthly' => 'Monthly',
                    default => 'One-time',
                },
                'invoices' => 0,
                'qty' => 0.0,
                'collected' => 0.0,
                '_invoice_ids' => [],
            ];
        }
        $items[$productId]['collected'] += $collected;
        $items[$productId]['qty'] += (float) $row['quantity'];
        $items[$productId]['_invoice_ids'][(int) $row['invoice_id']] = true;
    }

    foreach ($items as &$item) {
        $item['invoices'] = count($item['_invoice_ids']);
        $item['qty'] = round($item['qty'], 2);
        $item['collected'] = round($item['collected'], 2);
        unset($item['_invoice_ids']);
    }
    unset($item);

    return [
        'as_of_date' => $asOfDate,
        'items' => array_values(array_filter($items, static fn(array $item): bool => $item['collected'] > 0)),
    ];
}
