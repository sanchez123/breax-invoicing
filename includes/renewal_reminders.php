<?php
declare(strict_types=1);

require_once __DIR__ . '/email.php';

function renewal_reminder_config(): array
{
    $timezoneSetting = getenv('RENEWAL_REMINDER_TIMEZONE');
    $timezoneName = trim($timezoneSetting === false || trim($timezoneSetting) === '' ? 'Africa/Nairobi' : $timezoneSetting);
    try {
        $timezone = new DateTimeZone($timezoneName);
    } catch (Throwable $exception) {
        throw new RuntimeException('RENEWAL_REMINDER_TIMEZONE must name a valid time zone.', 0, $exception);
    }
    $hourSetting = getenv('RENEWAL_REMINDER_HOUR');
    $hour = filter_var($hourSetting === false || trim($hourSetting) === '' ? '8' : trim($hourSetting), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 23]]);
    if ($hour === false) {
        throw new RuntimeException('RENEWAL_REMINDER_HOUR must be between 0 and 23.');
    }
    $expiredSetting = getenv('RENEWAL_REMINDER_EXPIRED_DAYS');
    $expiredDays = filter_var($expiredSetting === false || trim($expiredSetting) === '' ? '90' : trim($expiredSetting), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
    if ($expiredDays === false) {
        throw new RuntimeException('RENEWAL_REMINDER_EXPIRED_DAYS must be a positive number of days.');
    }
    $stageSetting = getenv('RENEWAL_REMINDER_STAGES');
    $rawStages = trim($stageSetting === false || trim($stageSetting) === '' ? '30,7' : $stageSetting);
    $stages = [];
    foreach (explode(',', $rawStages) as $rawStage) {
        $stage = filter_var(trim($rawStage), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
        if ($stage === false) {
            throw new RuntimeException('RENEWAL_REMINDER_STAGES must be comma-separated positive day counts.');
        }
        $stages[] = $stage;
    }
    $stages = array_values(array_unique($stages));
    rsort($stages, SORT_NUMERIC);
    if ($stages === []) {
        throw new RuntimeException('At least one renewal reminder stage is required.');
    }
    return [
        'timezone' => $timezone,
        'timezone_name' => $timezoneName,
        'hour' => $hour,
        'expired_days' => $expiredDays,
        'stages' => $stages,
        'window_days' => max($stages),
    ];
}

function renewal_reminder_write_log(string $message): void
{
    $directory = dirname(__DIR__) . '/logs';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The renewal log directory could not be created.');
    }
    $line = '[' . (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM) . '] ' . $message . PHP_EOL;
    if (file_put_contents($directory . '/renewals.log', $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('The renewal log file could not be written.');
    }
}

function renewal_reminder_valid_date(mixed $value, ?DateTimeZone $timezone = null): ?DateTimeImmutable
{
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        return null;
    }
    return $date;
}

function renewal_reminder_source_items(PDO $pdo, DateTimeImmutable $today, array $config, array &$warnings): array
{
    $queries = [
        "SELECT c.id AS customer_id,c.name AS customer_name,c.name AS company_name,
                'primary_domain' AS source_type,c.id AS source_id,c.primary_domain AS domain,
                c.primary_domain_expiry AS expiry_date,'Domain' AS type
         FROM customers c
         WHERE c.status='active' AND c.invoice_delivery='primary'
           AND c.primary_domain IS NOT NULL AND TRIM(c.primary_domain)<>'' AND c.primary_domain_expiry IS NOT NULL",
        "SELECT c.id AS customer_id,c.name AS customer_name,COALESCE(NULLIF(TRIM(d.company_name),''),'') AS company_name,
                'client_domain' AS source_type,d.id AS source_id,d.domain_name AS domain,
                d.expiry_date AS expiry_date,'Domain' AS type
         FROM customer_domains d JOIN customers c ON c.id=d.customer_id
         WHERE c.status='active' AND c.invoice_delivery='domain' AND d.status='active'
           AND d.domain_name IS NOT NULL AND TRIM(d.domain_name)<>'' AND d.expiry_date IS NOT NULL",
        "SELECT c.id AS customer_id,c.name AS customer_name,c.name AS company_name,
                'google_workspace' AS source_type,w.id AS source_id,w.domain_name AS domain,
                w.expiry_date AS expiry_date,'Google Workspace' AS type
         FROM customer_google_workspaces w JOIN customers c ON c.id=w.customer_id
         WHERE c.status='active' AND w.status='active'
           AND w.domain_name IS NOT NULL AND TRIM(w.domain_name)<>'' AND w.expiry_date IS NOT NULL",
    ];
    $items = [];
    foreach ($queries as $sql) {
        $query = $pdo->query($sql);
        foreach ($query->fetchAll() as $row) {
            $expiry = renewal_reminder_valid_date($row['expiry_date'] ?? null, $config['timezone']);
            $domain = trim((string) ($row['domain'] ?? ''));
            if ($expiry === null || $domain === '') {
                $warnings[] = 'Skipped invalid renewal date or empty domain for customer ' . (int) $row['customer_id'] . '.';
                continue;
            }
            $daysRemaining = (int) $today->diff($expiry)->format('%r%a');
            if ($daysRemaining < -$config['expired_days']) {
                continue;
            }
            if ($daysRemaining < 0) {
                $stage = 0;
            } else {
                $orderedStages = $config['stages'];
                sort($orderedStages, SORT_NUMERIC);
                $stage = null;
                foreach ($orderedStages as $candidate) {
                    if ($daysRemaining <= $candidate) {
                        $stage = $candidate;
                        break;
                    }
                }
                if ($stage === null) {
                    continue;
                }
            }
            $items[] = [
                'customer_id' => (int) $row['customer_id'],
                'customer_name' => (string) $row['customer_name'],
                'company_name' => (string) $row['company_name'],
                'type' => (string) $row['type'],
                'source_type' => (string) $row['source_type'],
                'source_id' => (int) $row['source_id'],
                'domain' => $domain,
                'expiry_date' => $expiry->format('Y-m-d'),
                'days_remaining' => $daysRemaining,
                'stage_days' => $stage,
            ];
        }
    }
    return $items;
}

function renewal_reminder_eligible_items(PDO $pdo, array $items, bool $force): array
{
    $sent = [];
    foreach ($pdo->query('SELECT source_type,source_id,expiry_date,stage_days FROM renewal_reminder_log')->fetchAll() as $row) {
        $key = implode('|', [(string) $row['source_type'], (int) $row['source_id'], (string) $row['expiry_date'], (int) $row['stage_days']]);
        $sent[$key] = true;
    }
    return array_values(array_filter($items, static function (array $item) use ($sent, $force): bool {
        if ($force) {
            return true;
        }
        $key = implode('|', [$item['source_type'], $item['source_id'], $item['expiry_date'], $item['stage_days']]);
        return !isset($sent[$key]);
    }));
}

function renewal_reminder_public_base_url(): string
{
    $base = trim((string) (getenv('APP_BASE_URL') ?: getenv('APP_PUBLIC_BASE_URL')));
    $parts = $base !== '' ? parse_url($base) : false;
    if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('Configure APP_BASE_URL as the public application URL before sending renewal reminders.');
    }
    return rtrim($base, '/');
}

function renewal_reminder_html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renewal_reminder_render(array $items, array $config, string $today, bool $test = false): array
{
    $baseUrl = renewal_reminder_public_base_url();
    $groupTitles = [
        'expired' => 'Already expired',
        'soon' => 'Due in ' . min($config['stages']) . ' days or less',
        'window' => 'Due within ' . $config['window_days'] . ' days',
    ];
    $groups = ['expired' => [], 'soon' => [], 'window' => []];
    foreach ($items as $item) {
        $group = $item['days_remaining'] < 0 ? 'expired' : ($item['days_remaining'] <= min($config['stages']) ? 'soon' : 'window');
        $groups[$group][] = $item;
    }
    foreach ($groups as &$groupItems) {
        usort($groupItems, static fn(array $a, array $b): int => [$a['expiry_date'], $a['customer_name'], $a['domain']] <=> [$b['expiry_date'], $b['customer_name'], $b['domain']]);
    }
    unset($groupItems);
    $html = '';
    $text = [];
    foreach ($groups as $groupKey => $groupItems) {
        if ($groupItems === []) {
            continue;
        }
        $title = $groupTitles[$groupKey];
        $html .= '<h2 style="font-size:18px;color:#332D70;margin:24px 0 10px">' . renewal_reminder_html_escape($title) . '</h2><table role="presentation" style="width:100%;border-collapse:collapse">';
        $text[] = $title;
        foreach ($groupItems as $item) {
            $url = $baseUrl . '/dashboard/clients?edit=' . $item['customer_id'];
            $formattedDate = (new DateTimeImmutable($item['expiry_date'], $config['timezone']))->format('d M Y');
            if ($item['days_remaining'] < 0) {
                $expiredDays = abs($item['days_remaining']);
                $remaining = 'Expired ' . $expiredDays . ' ' . ($expiredDays === 1 ? 'day' : 'days') . ' ago';
            } elseif ($item['days_remaining'] === 0) {
                $remaining = 'Expires today';
            } else {
                $remaining = $item['days_remaining'] . ' ' . ($item['days_remaining'] === 1 ? 'day' : 'days') . ' remaining';
            }
            $company = trim($item['company_name']) !== '' ? $item['company_name'] : 'Not set';
            $html .= '<tr><td style="padding:12px 8px;border-bottom:1px solid #e3e1f1">'
                . '<strong>' . renewal_reminder_html_escape($item['customer_name']) . '</strong><br>'
                . 'Company: ' . renewal_reminder_html_escape($company) . '<br>'
                . 'Domain: ' . renewal_reminder_html_escape($item['domain']) . '<br>'
                . 'Type: ' . renewal_reminder_html_escape($item['type']) . '<br>'
                . 'Expiry date: ' . renewal_reminder_html_escape($formattedDate) . '<br>'
                . 'Days remaining: ' . renewal_reminder_html_escape($remaining) . '<br>'
                . '<a href="' . renewal_reminder_html_escape($url) . '" style="color:#534AB7">Open customer</a></td></tr>';
            $text[] = $item['customer_name'] . ' | ' . $company . ' | ' . $item['domain'] . ' | ' . $item['type'] . ' | ' . $formattedDate . ' | ' . $remaining . ' | ' . $url;
        }
        $html .= '</table>';
        $text[] = '';
    }
    $count = count($items);
    $subject = ($test ? '[TEST] ' : '') . 'Renewals due soon: ' . $count . ' ' . ($count === 1 ? 'item' : 'items') . ' expiring in the next ' . $config['window_days'] . ' days';
    $templatePath = dirname(__DIR__) . '/emails/email-admin-renewal-reminders.html';
    $template = file_get_contents($templatePath);
    if (!is_string($template)) {
        throw new RuntimeException('The renewal reminder email template could not be read.');
    }
    $intro = $test ? 'TEST digest. This message contains fake sample data and is not a customer notification.' : 'Renewals as of ' . $today . '. Review each item and follow up before it expires.';
    $renderedHtml = strtr($template, [
        '{{SUBJECT}}' => renewal_reminder_html_escape($subject),
        '{{INTRO}}' => renewal_reminder_html_escape($intro),
        '{{CONTENT}}' => $html,
    ]);
    $plain = [$subject, '', $intro, '', ...$text, ''];
    return ['subject' => $subject, 'html' => $renderedHtml, 'text' => implode("\n", $plain)];
}

function renewal_reminder_test_items(DateTimeImmutable $today): array
{
    return [[
        'customer_id' => 0,
        'customer_name' => 'TEST Customer',
        'company_name' => 'TEST Company',
        'type' => 'Domain',
        'source_type' => 'test',
        'source_id' => 0,
        'domain' => 'example.invalid',
        'expiry_date' => $today->modify('+25 days')->format('Y-m-d'),
        'days_remaining' => 25,
        'stage_days' => 30,
    ]];
}
