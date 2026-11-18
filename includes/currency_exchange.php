<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

const FX_RATES_ENDPOINT = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@';

function fetch_kes_rate_feed(string $date = 'latest'): ?array
{
    if ($date !== 'latest' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('The exchange-rate date is invalid.');
    }
    $url = FX_RATES_ENDPOINT . rawurlencode($date) . '/v1/currencies/kes.json';
    $context = stream_context_create([
        'http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $response = @file_get_contents($url, false, $context);
    if (!is_string($response)) {
        return null;
    }
    try {
        $payload = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($payload) || !is_string($payload['date'] ?? null)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['date'])
        || ($date !== 'latest' && $payload['date'] !== $date)
        || !is_array($payload['kes'] ?? null)
        || !isset($payload['kes']['usd'], $payload['kes']['gbp'], $payload['kes']['eur'])) {
        return null;
    }
    return $payload;
}

function store_kes_daily_rates(PDO $pdo, array $feed): string
{
    $date = (string) $feed['date'];
    $rates = $feed['kes'];
    $save = $pdo->prepare('INSERT INTO fx_daily_rates (rate_date,currency_code,kes_per_unit) VALUES (?,?,?) ON DUPLICATE KEY UPDATE kes_per_unit=VALUES(kes_per_unit),updated_at=CURRENT_TIMESTAMP');
    $save->execute([$date, 'KES', '1.00000000']);
    foreach (['USD' => 'usd', 'GBP' => 'gbp', 'EUR' => 'eur'] as $currency => $code) {
        $foreignPerKes = filter_var($rates[$code], FILTER_VALIDATE_FLOAT);
        if ($foreignPerKes === false || $foreignPerKes <= 0) {
            throw new RuntimeException('The exchange-rate service returned an invalid currency rate.');
        }
        $save->execute([$date, $currency, number_format(1 / $foreignPerKes, 8, '.', '')]);
    }
    return $date;
}

function refresh_kes_daily_rates(PDO $pdo): string
{
    $latestQuery = $pdo->query('SELECT MAX(rate_date) FROM fx_daily_rates');
    $latestDate = $latestQuery->fetchColumn();
    $lastRefresh = $pdo->query('SELECT MAX(UNIX_TIMESTAMP(updated_at)) FROM fx_daily_rates WHERE rate_date=(SELECT MAX(rate_date) FROM fx_daily_rates)')->fetchColumn();
    if (is_string($latestDate) && is_numeric($lastRefresh)
        && time() - (int) $lastRefresh < 3 * 3600) {
        return $latestDate;
    }

    $feed = fetch_kes_rate_feed();
    if ($feed !== null) {
        try {
            return store_kes_daily_rates($pdo, $feed);
        } catch (RuntimeException $exception) {
            error_log('Exchange-rate refresh failed: ' . $exception->getMessage());
        }
    } else {
        error_log('Exchange-rate refresh failed: the daily exchange-rate service could not be reached.');
    }

    $latestDate = $pdo->query('SELECT MAX(rate_date) FROM fx_daily_rates')->fetchColumn();
    if (is_string($latestDate) && $latestDate !== '') {
        return $latestDate;
    }
    throw new InvalidArgumentException('KES exchange rates are unavailable. Check the server internet connection and reload this page.');
}

function kes_rate_for_invoice_date(PDO $pdo, string $currency, string $issueDate): array
{
    $currency = strtoupper($currency);
    if ($currency === 'KES') {
        return ['rate' => '1.00000000', 'date' => $issueDate];
    }
    if (!in_array($currency, supported_invoice_currencies(), true)) {
        throw new InvalidArgumentException('The invoice currency has no supported KES exchange rate.');
    }

    try {
        refresh_kes_daily_rates($pdo);
    } catch (InvalidArgumentException $exception) {
        error_log('Latest exchange rates unavailable; trying the invoice-date rate: ' . $exception->getMessage());
    }
    $exactQuery = $pdo->prepare('SELECT 1 FROM fx_daily_rates WHERE rate_date=? AND currency_code=?');
    $exactQuery->execute([$issueDate, $currency]);
    if (!$exactQuery->fetchColumn()) {
        $historicalFeed = fetch_kes_rate_feed($issueDate);
        if ($historicalFeed !== null) {
            try {
                store_kes_daily_rates($pdo, $historicalFeed);
            } catch (RuntimeException $exception) {
                error_log('Historical exchange-rate lookup failed: ' . $exception->getMessage());
            }
        }
    }
    return lookup_kes_rate_for_invoice_date($pdo, $currency, $issueDate);
}

function lookup_kes_rate_for_invoice_date(PDO $pdo, string $currency, string $issueDate): array
{
    $query = $pdo->prepare('SELECT rate_date,kes_per_unit FROM fx_daily_rates WHERE currency_code=? ORDER BY (rate_date=?) DESC,rate_date DESC LIMIT 1');
    $query->execute([$currency, $issueDate]);
    $rate = $query->fetch();
    if (!$rate || (float) $rate['kes_per_unit'] <= 0) {
        throw new InvalidArgumentException('A KES exchange rate is unavailable for this invoice currency.');
    }
    return ['rate' => (string) $rate['kes_per_unit'], 'date' => (string) $rate['rate_date']];
}

function ensure_invoice_reporting_rates(PDO $pdo): string
{
    $hasForeignInvoices = (bool) $pdo->query("SELECT 1 FROM invoices WHERE currency_code<>'KES' LIMIT 1")->fetchColumn();
    $rateDate = (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');
    $pdo->exec("UPDATE invoices SET kes_exchange_rate=1,fx_rate_date=issue_date WHERE currency_code='KES' AND kes_exchange_rate IS NULL");
    $missing = $pdo->query("SELECT id,currency_code,issue_date FROM invoices WHERE currency_code<>'KES' AND kes_exchange_rate IS NULL")->fetchAll();
    if ($missing) {
        try {
            $rateDate = refresh_kes_daily_rates($pdo);
        } catch (InvalidArgumentException $exception) {
            error_log('Latest exchange rates unavailable; trying historical invoice rates: ' . $exception->getMessage());
        }
    }
    $update = $pdo->prepare('UPDATE invoices SET kes_exchange_rate=?,fx_rate_date=? WHERE id=? AND kes_exchange_rate IS NULL');
    $datesToFetch = [];
    foreach ($missing as $invoice) {
        $datesToFetch[(string) $invoice['issue_date']] = true;
    }
    $checkDate = $pdo->prepare('SELECT 1 FROM fx_daily_rates WHERE rate_date=? AND currency_code=?');
    foreach (array_keys($datesToFetch) as $date) {
        foreach (['USD', 'GBP', 'EUR'] as $currency) {
            $checkDate->execute([$date, $currency]);
            if (!$checkDate->fetchColumn()) {
                $historicalFeed = fetch_kes_rate_feed($date);
                if ($historicalFeed !== null) {
                    try {
                        store_kes_daily_rates($pdo, $historicalFeed);
                    } catch (RuntimeException $exception) {
                        error_log('Historical exchange-rate lookup failed: ' . $exception->getMessage());
                    }
                }
                break;
            }
        }
    }
    foreach ($missing as $invoice) {
        $snapshot = lookup_kes_rate_for_invoice_date($pdo, (string) $invoice['currency_code'], (string) $invoice['issue_date']);
        $update->execute([$snapshot['rate'], $snapshot['date'], $invoice['id']]);
    }
    if ($hasForeignInvoices) {
        $cachedRateDate = $pdo->query('SELECT MAX(rate_date) FROM fx_daily_rates')->fetchColumn();
        if (is_string($cachedRateDate) && $cachedRateDate !== '') {
            $rateDate = $cachedRateDate;
        } else {
            $snapshotDate = $pdo->query("SELECT MAX(fx_rate_date) FROM invoices WHERE currency_code<>'KES' AND kes_exchange_rate IS NOT NULL")->fetchColumn();
            $rateDate = is_string($snapshotDate) && $snapshotDate !== '' ? $snapshotDate : '';
        }
        if ($rateDate === '' && $missing) {
            throw new InvalidArgumentException('KES exchange rates are unavailable. Check the server internet connection and reload this page.');
        }
    }
    return $rateDate;
}

function reporting_kes_sql(string $amountExpression, string $currencyExpression = 'i.currency_code', string $rateExpression = 'i.kes_exchange_rate'): string
{
    return "CASE WHEN {$currencyExpression}='KES' THEN {$amountExpression} ELSE {$amountExpression}*{$rateExpression} END";
}

function exchange_rates_panel_data(PDO $pdo): array
{
    try {
        refresh_kes_daily_rates($pdo);
    } catch (InvalidArgumentException $exception) {
        error_log('Exchange rates panel refresh unavailable: ' . $exception->getMessage());
    }

    $staleAfterHours = getenv('FX_STALE_AFTER_HOURS');
    $staleAfterHours = $staleAfterHours === false || $staleAfterHours === ''
        ? 3
        : filter_var($staleAfterHours, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8760]]);
    if ($staleAfterHours === false) {
        throw new InvalidArgumentException('FX_STALE_AFTER_HOURS must be an integer between 1 and 8760.');
    }

    $enabledQuery = $pdo->prepare("SELECT setting_value FROM company_settings WHERE setting_key='invoice_currencies'");
    $enabledQuery->execute();
    $enabled = parse_invoice_currencies($enabledQuery->fetchColumn() ?: null);
    $enabledForeign = array_values(array_diff($enabled, ['KES']));
    $orderedCurrencies = ['USD', 'EUR', 'GBP'];
    foreach ($enabledForeign as $currency) {
        if (!in_array($currency, $orderedCurrencies, true)) {
            $orderedCurrencies[] = $currency;
        }
    }

    $rates = [];
    $rateQuery = $pdo->query('SELECT currency_code,kes_per_unit FROM fx_daily_rates WHERE rate_date=(SELECT MAX(rate_date) FROM fx_daily_rates)');
    foreach ($rateQuery->fetchAll() as $row) {
        $rates[(string) $row['currency_code']] = (string) $row['kes_per_unit'];
    }
    $updatedAt = $pdo->query('SELECT MAX(UNIX_TIMESTAMP(updated_at)) FROM fx_daily_rates WHERE rate_date=(SELECT MAX(rate_date) FROM fx_daily_rates)')->fetchColumn();
    $updatedTimestamp = is_numeric($updatedAt) ? (int) $updatedAt : null;

    return [
        'currencies' => $orderedCurrencies,
        'rates' => $rates,
        'updated_timestamp' => $updatedTimestamp,
        'stale_after_hours' => $staleAfterHours,
        'is_stale' => $updatedTimestamp !== null && time() - $updatedTimestamp > $staleAfterHours * 3600,
    ];
}
