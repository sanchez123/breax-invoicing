<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/overdue_services.php';
require_once __DIR__ . '/invoice_payment_totals.php';

function currency_summary_data(PDO $pdo, ?array $overdueServiceData = null): array
{
    $overdueServiceData ??= overdue_service_data($pdo);
    $currencyListLoaded = true;
    try {
        $enabledQuery = $pdo->prepare("SELECT setting_value FROM company_settings WHERE setting_key='invoice_currencies'");
        $enabledQuery->execute();
        $currencies = parse_invoice_currencies($enabledQuery->fetchColumn() ?: null);
        $invoiceCurrencyQuery = $pdo->query('SELECT DISTINCT currency_code FROM invoices ORDER BY currency_code');
        foreach ($invoiceCurrencyQuery->fetchAll(PDO::FETCH_COLUMN) as $invoiceCurrency) {
            $invoiceCurrency = strtoupper((string) $invoiceCurrency);
            if (preg_match('/^[A-Z]{3}$/', $invoiceCurrency) && !in_array($invoiceCurrency, $currencies, true)) {
                $currencies[] = $invoiceCurrency;
            }
        }
    } catch (PDOException $exception) {
        $currencyListLoaded = false;
        $currencies = ['KES'];
        error_log('Currency summary list failed: ' . $exception->getMessage());
    }

    $currencyStats = [];
    $currencyStatsLoaded = $currencyListLoaded;
    try {
        $currencyRows = $pdo->query("
            SELECT i.currency_code,
                   COUNT(*) AS invoice_count,
                   SUM(CASE WHEN i.status='paid' OR (i.status<>'cancelled' AND COALESCE(p.paid,0)>=i.total) THEN 1 ELSE 0 END) AS paid_count,
                   SUM(CASE WHEN i.status NOT IN ('draft','cancelled') AND COALESCE(p.paid,0)<i.total AND i.due_date>=CURDATE() THEN 1 ELSE 0 END) AS unpaid_count,
                   SUM(CASE WHEN i.status NOT IN ('draft','cancelled') THEN 1 ELSE 0 END) AS invoiced_count,
                   COALESCE(SUM(CASE WHEN i.status NOT IN ('draft','cancelled') THEN i.total ELSE 0 END),0) AS invoiced_amount,
                   SUM(CASE WHEN i.status<>'cancelled' AND COALESCE(p.payment_count,0)>0 THEN 1 ELSE 0 END) AS received_count,
                   COALESCE(SUM(CASE WHEN i.status<>'cancelled' THEN COALESCE(p.paid,0) ELSE 0 END),0) AS received_amount,
                   SUM(CASE WHEN i.status NOT IN ('draft','cancelled') AND COALESCE(p.paid,0)<i.total THEN 1 ELSE 0 END) AS outstanding_count,
                   COALESCE(SUM(CASE WHEN i.status NOT IN ('draft','cancelled') THEN GREATEST(i.total-COALESCE(p.paid,0),0) ELSE 0 END),0) AS outstanding_amount,
                   COALESCE(SUM(CASE WHEN i.status NOT IN ('draft','cancelled') THEN i.total*CASE WHEN i.currency_code='KES' THEN 1 ELSE i.kes_exchange_rate END ELSE 0 END),0) AS invoiced_kes,
                   COALESCE(SUM(CASE WHEN i.status<>'cancelled' THEN COALESCE(p.paid,0)*CASE WHEN i.currency_code='KES' THEN 1 ELSE i.kes_exchange_rate END ELSE 0 END),0) AS received_kes,
                   COALESCE(SUM(CASE WHEN i.status NOT IN ('draft','cancelled') THEN GREATEST(i.total-COALESCE(p.paid,0),0)*CASE WHEN i.currency_code='KES' THEN 1 ELSE i.kes_exchange_rate END ELSE 0 END),0) AS outstanding_kes,
                   SUM(CASE WHEN i.currency_code<>'KES' AND i.status NOT IN ('draft','cancelled') AND i.kes_exchange_rate IS NULL THEN 1 ELSE 0 END) AS missing_invoiced_rate,
                   SUM(CASE WHEN i.currency_code<>'KES' AND i.status<>'cancelled' AND COALESCE(p.payment_count,0)>0 AND i.kes_exchange_rate IS NULL THEN 1 ELSE 0 END) AS missing_received_rate,
                   SUM(CASE WHEN i.currency_code<>'KES' AND i.status NOT IN ('draft','cancelled') AND COALESCE(p.paid,0)<i.total AND i.kes_exchange_rate IS NULL THEN 1 ELSE 0 END) AS missing_outstanding_rate
              FROM invoices i
              LEFT JOIN " . invoice_payment_totals_subquery() . " p ON p.invoice_id=i.id
             GROUP BY i.currency_code
        ")->fetchAll();
        foreach ($currencyRows as $row) {
            $currencyStats[strtoupper((string) $row['currency_code'])] = $row;
        }
        foreach ($currencyStats as $currency => &$row) {
            $overdue = $overdueServiceData['invoice_summary_by_currency'][$currency] ?? [
                'overdue_count' => 0,
                'overdue_balance_count' => 0,
                'overdue_balance_amount' => 0.0,
                'overdue_balance_kes' => 0.0,
                'missing_overdue_rate' => 0,
            ];
            foreach ($overdue as $key => $value) {
                $row[$key] = $value;
            }
        }
        unset($row);
    } catch (PDOException $exception) {
        $currencyStatsLoaded = false;
        error_log('Currency summary query failed: ' . $exception->getMessage());
    }

    return [
        'currencies' => $currencies,
        'currency_stats' => $currencyStats,
        'currency_list_loaded' => $currencyListLoaded,
        'currency_stats_loaded' => $currencyStatsLoaded,
    ];
}

function currency_display_name(string $currency): string
{
    $names = [
        'KES' => 'Kenyan Shilling',
        'USD' => 'US Dollar',
        'EUR' => 'Euro',
        'GBP' => 'British Pound',
    ];

    return $names[$currency] ?? $currency;
}
