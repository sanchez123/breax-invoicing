<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/currency_exchange.php';

try {
    $pdo = db();
    $rateDate = refresh_kes_daily_rates($pdo);
    $updatedAt = $pdo->query('SELECT MAX(UNIX_TIMESTAMP(updated_at)) FROM fx_daily_rates WHERE rate_date=(SELECT MAX(rate_date) FROM fx_daily_rates)')->fetchColumn();
    if (!is_numeric($updatedAt) || time() - (int) $updatedAt >= 3 * 3600) {
        throw new RuntimeException('The exchange-rate feed has not refreshed within the last three hours.');
    }

    fwrite(STDOUT, sprintf(
        "Exchange rates are current. Latest rate date: %s; last successful refresh: %s\n",
        $rateDate,
        date(DATE_ATOM, (int) $updatedAt)
    ));
} catch (Throwable $exception) {
    error_log('Scheduled exchange-rate refresh failed: ' . $exception->getMessage());
    fwrite(STDERR, "Exchange-rate refresh failed or the cached rates are older than three hours. Check the application log.\n");
    exit(1);
}
