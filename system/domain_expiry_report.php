<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/domain_expiry_report.php';

$options = array_slice($argv, 1);
$preview = in_array('--preview', $options, true);
$send = in_array('--send', $options, true);
$resend = in_array('--resend-today', $options, true);
$outputPath = null;
foreach ($options as $option) {
    if (str_starts_with($option, '--output=')) {
        $outputPath = substr($option, strlen('--output='));
        if ($outputPath === '') {
            fwrite(STDERR, "--output requires a file path.\n");
            exit(2);
        }
    } elseif (!in_array($option, ['--preview', '--send', '--resend-today'], true)) {
        fwrite(STDERR, "Usage: php system/domain_expiry_report.php --preview [--output=FILE.pdf] | --send [--resend-today]\n");
        exit(2);
    }
}
if ($preview === $send || ($outputPath !== null && !$preview) || ($resend && !$send)) {
    fwrite(STDERR, "Choose exactly one of --preview or --send; --output is preview-only and --resend-today requires --send.\n");
    exit(2);
}

try {
    if (!$send) {
        $result = run_domain_renewal_report(false, $outputPath);
        fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
    } else {
        $operations = [
            'domain_report' => static fn(): array => run_domain_renewal_report(true, null, $resend),
            'google_workspace_report' => static fn(): array => run_google_workspace_renewal_report(true, $resend),
        ];
        $results = [];
        foreach ($operations as $name => $operation) {
            try {
                $results[$name] = $operation();
            } catch (Throwable $exception) {
                error_log($name . ' failed: ' . safe_email_error($exception->getMessage()));
                $results[$name] = ['sent' => 0, 'failed' => 1, 'skipped' => 0, 'message' => 'Report run failed; check the application log.'];
            }
        }
        fwrite(STDOUT, json_encode($results, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
        if ($results['domain_report']['failed'] > 0 || $results['google_workspace_report']['failed'] > 0) {
            exit(1);
        }
    }
} catch (Throwable $exception) {
    error_log('Domain renewal report run failed: ' . safe_email_error($exception->getMessage()));
    fwrite(STDERR, "Domain renewal report run failed. Check the application log.\n");
    exit(1);
}
