<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/domain_expiry_report.php';

$options = array_slice($argv, 1);
$preview = $options === ['--preview'];
$send = $options === ['--send'];
if (!$preview && !$send) {
    fwrite(STDERR, "Usage: php system/admin_overdue_reports.php --preview | --send\n");
    exit(2);
}

$operations = [
    'overdue_invoice_report' => static fn(): array => run_overdue_invoice_report($send),
    'domain_renewal_report' => static fn(): array => run_domain_renewal_report($send),
    'google_workspace_renewal_report' => static fn(): array => run_google_workspace_renewal_report($send),
];
$results = [];
foreach ($operations as $name => $operation) {
    try {
        $results[$name] = $operation();
        $message = (string) ($results[$name]['message'] ?? '');
        if ($send && (str_contains($message, 'Run database/') || str_contains($message, 'require MAIL_MODE=live'))) {
            $results[$name]['failed'] = max(1, (int) ($results[$name]['failed'] ?? 0));
        }
    } catch (Throwable $exception) {
        error_log($name . ' failed: ' . safe_email_error($exception->getMessage()));
        $results[$name] = ['sent' => 0, 'failed' => 1, 'skipped' => 0, 'message' => 'Report run failed; check the application log.'];
    }
}

foreach ($results as $name => $result) {
    error_log(sprintf(
        'Overdue report %s: sent=%d failed=%d skipped=%d. %s',
        $name,
        (int) ($result['sent'] ?? 0),
        (int) ($result['failed'] ?? 0),
        (int) ($result['skipped'] ?? 0),
        (string) ($result['message'] ?? '')
    ));
}

fwrite(STDOUT, json_encode($results, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
if ($send && count(array_filter($results, static fn(array $result): bool => (int) ($result['failed'] ?? 0) > 0)) > 0) {
    exit(1);
}
