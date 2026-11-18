<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/renewal_reminders.php';

$options = array_slice($argv, 1);
$knownOptions = ['--dry-run', '--send-test', '--force'];
$todayOption = null;
foreach ($options as $option) {
    if (preg_match('/\A--today=(\d{4}-\d{2}-\d{2})\z/', $option, $match)) {
        if ($todayOption !== null) {
            fwrite(STDERR, "Pass --today only once.\n");
            exit(2);
        }
        $todayOption = $match[1];
    } elseif (!in_array($option, $knownOptions, true)) {
        fwrite(STDERR, "Unknown option: {$option}\nUsage: php system/send_renewal_reminders.php [--dry-run] [--send-test] [--force] [--today=YYYY-MM-DD]\n");
        exit(2);
    }
}
$dryRun = in_array('--dry-run', $options, true);
$sendTest = in_array('--send-test', $options, true);
$force = in_array('--force', $options, true);
if (($dryRun && $sendTest) || ($sendTest && $force)) {
    fwrite(STDERR, "Choose only one of --dry-run or --send-test; --force cannot be combined with --send-test.\n");
    exit(2);
}

$lockAcquired = false;
try {
    $config = renewal_reminder_config();
    $today = $todayOption !== null ? renewal_reminder_valid_date($todayOption, $config['timezone']) : new DateTimeImmutable('today', $config['timezone']);
    if ($today === null) {
        throw new InvalidArgumentException('--today must be a real date in YYYY-MM-DD format.');
    }
    $today = $today->setTimezone($config['timezone']);
    $pdo = db();
    if ((int) $pdo->query("SELECT GET_LOCK('breax_renewal_reminders',0)")->fetchColumn() !== 1) {
        throw new RuntimeException('Another renewal reminder run is already in progress.');
    }
    $lockAcquired = true;
    renewal_reminder_write_log('Run started; date=' . $today->format('Y-m-d') . '; timezone=' . $config['timezone_name'] . '; scheduled local time=' . sprintf('%02d:00', $config['hour']) . '; mode=' . ($sendTest ? 'test' : ($dryRun ? 'dry-run' : 'scheduled')) . '.');

    $recipients = renewal_reminder_recipients($pdo);
    if ($sendTest) {
        $items = renewal_reminder_test_items($today);
    } else {
        $warnings = [];
        $foundItems = renewal_reminder_source_items($pdo, $today, $config, $warnings);
        $items = renewal_reminder_eligible_items($pdo, $foundItems, $force);
        renewal_reminder_write_log('Items found in sources=' . count($foundItems) . '; newly qualifying=' . count($items) . '.');
        foreach ($warnings as $warning) {
            fwrite(STDERR, "Warning: {$warning}\n");
            renewal_reminder_write_log('Warning: ' . $warning);
        }
    }
    if ($force) {
        fwrite(STDERR, "WARNING: --force ignores prior reminder records and resends all qualifying items.\n");
    }

    $digest = renewal_reminder_render($items, $config, $today->format('Y-m-d'), $sendTest);
    fwrite(STDOUT, 'Recipients: ' . implode(', ', $recipients) . "\n");
    fwrite(STDOUT, 'Subject: ' . $digest['subject'] . "\n");
    fwrite(STDOUT, 'Items: ' . count($items) . "\n");
    foreach ($items as $item) {
        $days = $item['days_remaining'] < 0 ? 'Expired ' . abs($item['days_remaining']) . ' days ago' : $item['days_remaining'] . ' days remaining';
        $editUrl = renewal_reminder_public_base_url() . '/dashboard/clients?edit=' . $item['customer_id'];
        fwrite(STDOUT, '- Customer: ' . $item['customer_name'] . ' | Company: ' . ($item['company_name'] !== '' ? $item['company_name'] : 'Not set')
            . ' | Domain: ' . $item['domain'] . ' | Type: ' . $item['type'] . ' | Expiry: ' . $item['expiry_date']
            . ' | Days: ' . $days . ' | Link: ' . $editUrl . "\n");
    }

    if ($sendTest || (!$dryRun && $items !== [])) {
        $delivery = sendEmail([
            'to' => $recipients[0],
            'cc' => array_slice($recipients, 1),
            'subject' => $digest['subject'],
            'html' => $digest['html'],
            'text' => $digest['text'],
            'idempotencyKey' => 'renewal-digest-' . bin2hex(random_bytes(16)),
            'tags' => ['type' => 'admin_renewal_reminder_digest', 'triggered_by' => 'system'],
        ]);
        if (($delivery['status'] ?? '') !== 'sent') {
            renewal_reminder_write_log('Email failed; items sent=0; ' . (string) ($delivery['message'] ?? 'Mailer did not accept the digest.') . '; qualifying items=' . count($items) . '.');
            throw new RuntimeException('The mailer did not accept the digest; no reminder records were written.');
        }
        if (!$sendTest) {
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare("INSERT INTO renewal_reminder_log
                    (source_type,source_id,customer_id,domain,expiry_date,stage_days,sent_at,recipients)
                    VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),?)
                    ON DUPLICATE KEY UPDATE customer_id=VALUES(customer_id),domain=VALUES(domain),sent_at=VALUES(sent_at),recipients=VALUES(recipients)");
                foreach ($items as $item) {
                    $insert->execute([
                        $item['source_type'], $item['source_id'], $item['customer_id'], $item['domain'],
                        $item['expiry_date'], $item['stage_days'], implode(',', $recipients),
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
        renewal_reminder_write_log(($sendTest ? 'Test email sent' : 'Digest sent') . '; items sent=' . count($items) . '; recipients=' . implode(',', $recipients) . '.');
        fwrite(STDOUT, $sendTest ? "Test digest sent.\n" : "Digest sent and reminder records saved.\n");
    } elseif ($dryRun) {
        renewal_reminder_write_log('Dry run completed; qualifying items=' . count($items) . '; recipients=' . implode(',', $recipients) . '.');
        fwrite(STDOUT, "Dry run completed; no email was sent and no reminder records were written.\n");
    } else {
        renewal_reminder_write_log('No qualifying items; items sent=0; no email sent.');
        fwrite(STDOUT, "No qualifying items; no email sent.\n");
    }
} catch (Throwable $exception) {
    error_log('Renewal reminder job failed: ' . $exception->getMessage());
    fwrite(STDERR, 'Renewal reminder job failed: ' . $exception->getMessage() . "\n");
    try {
        renewal_reminder_write_log('Run failed: ' . $exception->getMessage());
    } catch (Throwable $logException) {
        error_log('Renewal reminder log write failed: ' . $logException->getMessage());
    }
    $exitCode = 1;
} finally {
    if ($lockAcquired && isset($pdo) && $pdo instanceof PDO) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('breax_renewal_reminders')");
        } catch (Throwable $exception) {
            error_log('Renewal reminder lock release failed: ' . $exception->getMessage());
        }
    }
}
if (isset($exitCode)) {
    exit($exitCode);
}
