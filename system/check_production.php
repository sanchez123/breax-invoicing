<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$checks = [];
$check = static function (string $label, bool $passed, string $detail = '') use (&$checks): void {
    $checks[] = [$label, $passed, $detail];
};

try {
    require_once dirname(__DIR__) . '/includes/config.php';

    $check('PHP 8.2 or newer', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
    foreach (['mbstring', 'pdo_mysql', 'openssl', 'fileinfo'] as $extension) {
        $check('PHP extension: ' . $extension, extension_loaded($extension));
    }
    $check('Process execution (proc_open)', function_exists('proc_open'));
    $check('Remote URL streams', filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN));

    $environment = strtolower(trim((string) (getenv('APP_ENV') ?: '')));
    $check('APP_ENV=production', $environment === 'production');

    $baseUrl = trim((string) (getenv('APP_BASE_URL') ?: getenv('APP_PUBLIC_BASE_URL')));
    $urlParts = $baseUrl !== '' ? parse_url($baseUrl) : false;
    $validBaseUrl = $urlParts !== false
        && isset($urlParts['scheme'], $urlParts['host'])
        && strtolower($urlParts['scheme']) === 'https'
        && array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($urlParts)) === []
        && filter_var($baseUrl, FILTER_VALIDATE_URL) !== false;
    $check('Public HTTPS application URL', $validBaseUrl);

    foreach (['APP_DB_HOST', 'APP_DB_NAME', 'APP_DB_USER', 'APP_DB_PASSWORD'] as $name) {
        $value = getenv($name);
        $check($name, is_string($value) && trim($value) !== '');
    }
    $check('Dedicated database account', strtolower(trim((string) getenv('APP_DB_USER'))) !== 'root');
    $mailMode = strtolower(trim((string) (getenv('MAIL_MODE') ?: '')));
    $check('MAIL_MODE=live for admin overdue reports', $mailMode === 'live');
    foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_ENCRYPTION', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS'] as $name) {
        $value = getenv($name);
        $check($name, is_string($value) && trim($value) !== '');
    }
    $mailPort = filter_var(getenv('MAIL_PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $check('SMTP port and encryption', $mailPort !== false && in_array(strtolower((string) getenv('MAIL_ENCRYPTION')), ['ssl', 'tls'], true));

    $nodeBinary = trim((string) (getenv('INVOICE_PDF_NODE') ?: 'node'));
    $nodeProbe = null;
    if (function_exists('proc_open')) {
        $nodeProbe = @proc_open(
            [$nodeBinary, '-e', "require('playwright-core'); require('qrcode-generator'); process.stdout.write(process.version)"],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true]
        );
    }
    if (is_resource($nodeProbe)) {
        fclose($pipes[0]);
        $nodeOutput = trim((string) stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $nodeExit = proc_close($nodeProbe);
        $check('Node.js and PDF packages', $nodeExit === 0, $nodeExit === 0 ? $nodeOutput : 'Node.js dependencies could not be loaded.');
    } else {
        $check('Node.js and PDF packages', false, 'Node.js could not be started.');
    }

    $configuredBrowser = trim((string) (getenv('INVOICE_PDF_BROWSER') ?: ''));
    $browsers = $configuredBrowser !== ''
        ? [$configuredBrowser]
        : (PHP_OS_FAMILY === 'Windows'
            ? [
                'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
                'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
                'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
                'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            ]
            : ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome']);
    $browserPath = null;
    foreach ($browsers as $candidate) {
        if (is_file($candidate) && (PHP_OS_FAMILY === 'Windows' || is_executable($candidate))) {
            $browserPath = $candidate;
            break;
        }
    }
    $check('Headless Chromium for invoice PDFs', $browserPath !== null, $browserPath !== null ? 'Available' : 'Set INVOICE_PDF_BROWSER to an executable supported by the host.');
    $pdfCheckPassed = false;
    if ($browserPath !== null && function_exists('proc_open')) {
        $inputPath = tempnam(sys_get_temp_dir(), 'bx-pdf-check-input-');
        $outputPath = tempnam(sys_get_temp_dir(), 'bx-pdf-check-output-');
        if ($inputPath !== false && $outputPath !== false) {
            try {
                $payload = json_encode(['html' => '<!doctype html><html><body>Production PDF check</body></html>', 'qrUrl' => ''], JSON_THROW_ON_ERROR);
                if (file_put_contents($inputPath, $payload, LOCK_EX) === strlen($payload)) {
                    $renderer = dirname(__DIR__) . '/bin/render_invoice_pdf.js';
                    $process = @proc_open(
                        [$nodeBinary, $renderer, $inputPath, $outputPath],
                        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                        $pdfPipes,
                        dirname(__DIR__),
                        null,
                        ['bypass_shell' => true]
                    );
                    if (is_resource($process)) {
                        fclose($pdfPipes[0]);
                        stream_get_contents($pdfPipes[1]);
                        stream_get_contents($pdfPipes[2]);
                        fclose($pdfPipes[1]);
                        fclose($pdfPipes[2]);
                        $pdfExitCode = proc_close($process);
                        $pdfSignature = file_get_contents($outputPath, false, null, 0, 5);
                        $pdfCheckPassed = $pdfExitCode === 0 && $pdfSignature === '%PDF-';
                    }
                }
            } finally {
                foreach ([$inputPath, $outputPath] as $temporaryFile) {
                    if (is_file($temporaryFile)) {
                        unlink($temporaryFile);
                    }
                }
            }
        }
    }
    $check('Invoice PDF render smoke test', $pdfCheckPassed, $pdfCheckPassed ? '' : 'The renderer could not launch Chromium and produce a PDF.');

    $uploadDirectory = dirname(__DIR__) . '/assets/uploads';
    $check('Company-logo upload directory', is_dir($uploadDirectory) && is_writable($uploadDirectory));
    $temporaryDirectory = sys_get_temp_dir();
    $check('PHP temporary directory', is_dir($temporaryDirectory) && is_writable($temporaryDirectory));

    try {
        $host = trim((string) getenv('APP_DB_HOST'));
        $name = trim((string) getenv('APP_DB_NAME'));
        $user = (string) getenv('APP_DB_USER');
        $password = (string) getenv('APP_DB_PASSWORD');
        if ($host !== '' && $name !== '' && $user !== '' && $password !== '') {
            $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $tableRows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $tables = array_fill_keys(array_map('strval', $tableRows), true);
            $requiredTables = [
                'users', 'app_install_lock', 'customers', 'customer_domains', 'customer_google_workspaces',
                'customer_contact_write_lock', 'products',
                'invoices', 'invoice_items', 'payments', 'company_settings', 'email_log',
                'email_bounced_addresses', 'invoice_public_link_events', 'public_invoice_rate_limits',
                'fx_daily_rates', 'admin_overdue_alert_baseline', 'admin_overdue_alert_job_config',
                'admin_overdue_alert_run_state',
            ];
            $missingTables = array_values(array_diff($requiredTables, array_keys($tables)));
            $check('Database connection', true);
            $check('Application schema tables', $missingTables === [], $missingTables === [] ? '' : 'Missing: ' . implode(', ', $missingTables));

            if (isset($tables['users'])) {
                $userColumns = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
                $missingUserColumns = array_values(array_diff(['can_send_invoice_emails', 'receive_overdue_alerts'], $userColumns));
                $check('User alert/email migrations', $missingUserColumns === [], $missingUserColumns === [] ? '' : 'Missing: ' . implode(', ', $missingUserColumns));
                if (in_array('receive_overdue_alerts', $userColumns, true)) {
                    $recipientEmails = $pdo->query("SELECT email FROM users WHERE receive_overdue_alerts=1")->fetchAll(PDO::FETCH_COLUMN);
                    $recipientCount = count(array_filter($recipientEmails, static fn($email): bool => is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false));
                    $check('Opted-in admin report recipients', $recipientCount > 0);
                }
            }
            if (isset($tables['email_log'])) {
                $typeColumn = $pdo->query("SHOW COLUMNS FROM email_log LIKE 'type'")->fetch();
                $typeDefinition = (string) ($typeColumn['Type'] ?? '');
                $reportTypes = ['admin_domain_expiry_report', 'admin_overdue_invoice_report', 'admin_google_workspace_report'];
                $missingReportTypes = array_values(array_filter(
                    $reportTypes,
                    static fn(string $type): bool => !str_contains($typeDefinition, "'" . $type . "'")
                ));
                $check('Admin report email migrations', $missingReportTypes === [], $missingReportTypes === [] ? '' : 'Missing: ' . implode(', ', $missingReportTypes));
            }
        } else {
            $check('Database connection', false, 'Set all APP_DB_* values before running this check.');
        }
    } catch (Throwable $exception) {
        $check('Database connection', false, 'Could not connect with the configured production credentials.');
        error_log('Production readiness database check failed: ' . $exception->getMessage());
    }
} catch (Throwable $exception) {
    $check('Application configuration', false, 'Environment configuration could not be loaded.');
    error_log('Production readiness configuration check failed: ' . $exception->getMessage());
}

$failed = 0;
foreach ($checks as [$label, $passed, $detail]) {
    $status = $passed ? 'OK  ' : 'FAIL';
    fwrite(STDOUT, $status . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL);
    if (!$passed) {
        $failed++;
    }
}
fwrite(STDOUT, sprintf('%d check(s) passed; %d failed.%s', count($checks) - $failed, $failed, PHP_EOL));
exit($failed === 0 ? 0 : 1);
