<?php
declare(strict_types=1);

require_once __DIR__ . '/invoice_payment_totals.php';

function overdue_service_data(PDO $pdo): array
{
    $today = $pdo->query('SELECT CURDATE()')->fetchColumn();
    if (!is_string($today) || $today === '') {
        throw new RuntimeException('The current database date is unavailable.');
    }

    $invoiceQuery = $pdo->prepare("SELECT i.id,i.invoice_number,i.status,i.due_date,i.total,
            i.currency_code,i.kes_exchange_rate,COALESCE(p.paid_amount,0) paid_amount,
            c.id customer_id,c.name customer_name,
            c.phone customer_phone,c.email customer_email,c.billing_address customer_address
        FROM invoices i
        LEFT JOIN customers c ON c.id=i.customer_id
        LEFT JOIN " . invoice_payment_totals_subquery() . " p ON p.invoice_id=i.id
        WHERE i.status NOT IN ('draft','cancelled') AND i.due_date<?
          AND COALESCE(p.paid_amount,0)<i.total
        ORDER BY i.due_date,i.invoice_number,i.id");
    $invoiceQuery->execute([$today]);
    $invoices = $invoiceQuery->fetchAll();
    $invoiceSummaryByCurrency = [];
    foreach ($invoices as $invoice) {
        $currency = strtoupper((string) $invoice['currency_code']);
        $invoiceSummaryByCurrency[$currency] ??= [
            'overdue_count' => 0,
            'overdue_balance_count' => 0,
            'overdue_balance_amount' => 0.0,
            'overdue_balance_kes' => 0.0,
            'missing_overdue_rate' => 0,
        ];
        $balance = max(0.0, (float) $invoice['total'] - (float) $invoice['paid_amount']);
        $invoiceSummaryByCurrency[$currency]['overdue_count']++;
        $invoiceSummaryByCurrency[$currency]['overdue_balance_count']++;
        $invoiceSummaryByCurrency[$currency]['overdue_balance_amount'] += $balance;
        if ($currency === 'KES') {
            $invoiceSummaryByCurrency[$currency]['overdue_balance_kes'] += $balance;
        } elseif ($invoice['kes_exchange_rate'] !== null) {
            $invoiceSummaryByCurrency[$currency]['overdue_balance_kes'] += $balance * (float) $invoice['kes_exchange_rate'];
        } else {
            $invoiceSummaryByCurrency[$currency]['missing_overdue_rate']++;
        }
    }

    $domainQuery = $pdo->prepare("SELECT d.id domain_id,d.domain_name,d.expiry_date,
            c.id customer_id,c.name customer_name,c.email customer_email,c.phone customer_phone,
            c.billing_address customer_address,DATEDIFF(?,d.expiry_date) days_overdue
        FROM customer_domains d
        LEFT JOIN customers c ON c.id=d.customer_id
        WHERE d.status='active' AND d.expiry_date<?
        ORDER BY d.expiry_date,d.domain_name,c.name,d.id");
    $domainQuery->execute([$today, $today]);
    $domains = $domainQuery->fetchAll();

    $workspaceQuery = $pdo->prepare("SELECT w.id workspace_id,w.domain_name,w.expiry_date,
            c.id customer_id,c.name customer_name,c.email customer_email,c.phone customer_phone,
            c.billing_address customer_address,DATEDIFF(?,w.expiry_date) days_overdue
        FROM customer_google_workspaces w
        LEFT JOIN customers c ON c.id=w.customer_id
        WHERE w.status='active' AND w.expiry_date<?
        ORDER BY w.expiry_date,w.domain_name,c.name,w.id");
    $workspaceQuery->execute([$today, $today]);
    $workspaces = $workspaceQuery->fetchAll();

    return [
        'as_of_date' => $today,
        'invoices' => $invoices,
        'invoice_summary_by_currency' => $invoiceSummaryByCurrency,
        'domains' => $domains,
        'workspaces' => $workspaces,
        'counts' => [
            'invoices' => count($invoices),
            'domains' => count($domains),
            'workspaces' => count($workspaces),
        ],
    ];
}
