<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_auth();
$pdo = db();
$primaryAdminEmail = (string) $pdo->query('SELECT email FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
$error = '';
$uploadDirectory = dirname(__DIR__) . '/assets/uploads';
$newLogoFilename = null;
$removeStoredLogo = static function (string $filename) use ($uploadDirectory): void {
    if (preg_match('/\Acompany-logo-[a-f0-9]{32}\.(?:png|jpg|webp)\z/', $filename)) {
        $path = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path) && !unlink($path)) {
            error_log('Could not remove company logo file: ' . $filename);
        }
    }
};
$defaults = [
    'business_name' => 'Breax Technologies',
    'business_email' => '',
    'business_phone' => '',
    'business_address' => '',
    'currency' => APP_CURRENCY,
    'invoice_currencies' => 'KES,USD,EUR',
    'invoice_prefix' => 'INV',
    'default_tax_rate' => '0.00',
    'payment_instructions' => '',
    'payment_terms' => '',
    'mpesa_phone' => '0702655068',
    'mpesa_name' => 'Brian Wosukila Mose',
    'bank_name' => 'SBM Bank Kenya',
    'bank_account_number' => '0212420560001',
    'bank_account_name' => 'Brian Wosukila Mose',
    'renewal_reminder_recipients' => $primaryAdminEmail,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $values = [
            'business_name' => post_string('business_name', 190, true),
            'business_email' => post_string('business_email', 190),
            'business_phone' => post_string('business_phone', 60),
            'business_address' => post_string('business_address', 2000),
            'invoice_prefix' => strtoupper(post_string('invoice_prefix', 10, true)),
            'default_tax_rate' => post_string('default_tax_rate', 6, true),
            'payment_instructions' => post_string('payment_instructions', 4000, true),
            'payment_terms' => post_string('payment_terms', 4000),
            'mpesa_phone' => post_string('mpesa_phone', 60),
            'mpesa_name' => post_string('mpesa_name', 190),
            'bank_name' => post_string('bank_name', 190),
            'bank_account_number' => post_string('bank_account_number', 100),
            'bank_account_name' => post_string('bank_account_name', 190),
            'renewal_reminder_recipients' => post_string('renewal_reminder_recipients', 2000, true),
        ];
        $rawRecipients = array_values(array_filter(
            array_map('trim', explode(',', $values['renewal_reminder_recipients'])),
            static fn(string $address): bool => $address !== ''
        ));
        if ($rawRecipients === []) {
            throw new InvalidArgumentException('Enter one or more valid renewal reminder email addresses, separated by commas.');
        }
        foreach ($rawRecipients as $recipient) {
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Enter one or more valid renewal reminder email addresses, separated by commas.');
            }
        }
        $recipients = [];
        $seenRecipients = [];
        foreach ($rawRecipients as $recipient) {
            $recipientKey = strtolower($recipient);
            if (!isset($seenRecipients[$recipientKey])) {
                $seenRecipients[$recipientKey] = true;
                $recipients[] = $recipient;
            }
        }
        $values['renewal_reminder_recipients'] = implode(', ', $recipients);
        $rawInvoiceCurrencies = $_POST['currencies'] ?? $_POST['invoice_currencies'] ?? [];
        if (!is_array($rawInvoiceCurrencies)) {
            throw new InvalidArgumentException('Select at least one supported invoice currency.');
        }
        $selectedInvoiceCurrencies = array_values(array_unique(array_map(
            static fn(mixed $currency): string => is_string($currency) ? strtoupper(trim($currency)) : '',
            $rawInvoiceCurrencies
        )));
        if ($selectedInvoiceCurrencies === [] ||
            array_diff($selectedInvoiceCurrencies, supported_invoice_currencies()) !== []) {
            throw new InvalidArgumentException('Select at least one of the supported invoice currencies.');
        }
        $values['invoice_currencies'] = implode(',', array_values(array_intersect(supported_invoice_currencies(), $selectedInvoiceCurrencies)));
        if ($values['business_email'] !== '' && !filter_var($values['business_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid business email address.');
        }
        if (!preg_match('/^[A-Z0-9]{2,10}$/', $values['invoice_prefix'])) {
            throw new InvalidArgumentException('Invoice prefix must be 2–10 letters or numbers.');
        }
        if (!preg_match('/^(?:100(?:\.00?)?|(?:\d|[1-9]\d)(?:\.\d{1,2})?)$/', $values['default_tax_rate'])) {
            throw new InvalidArgumentException('Default tax rate must be between 0 and 100.');
        }
        $oldLogoFilename = $pdo->query("SELECT setting_value FROM company_settings WHERE setting_key='company_logo'")->fetchColumn();
        $oldLogoFilename = is_string($oldLogoFilename) ? $oldLogoFilename : '';
        $logoUpload = $_FILES['company_logo'] ?? null;
        $hasLogoUpload = is_array($logoUpload) && ($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($hasLogoUpload) {
            if (($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('The logo upload failed. Please select the image again.');
            }
            if (!isset($logoUpload['tmp_name'], $logoUpload['size']) || !is_uploaded_file($logoUpload['tmp_name'])) {
                throw new InvalidArgumentException('Choose a valid image file for the company logo.');
            }
            if ((int) $logoUpload['size'] < 1 || (int) $logoUpload['size'] > 2 * 1024 * 1024) {
                throw new InvalidArgumentException('The company logo must be no larger than 2 MB.');
            }
            $imageInfo = @getimagesize($logoUpload['tmp_name']);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($logoUpload['tmp_name']);
            $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            if (!$imageInfo || !isset($extensions[$mime]) || $imageInfo['mime'] !== $mime) {
                throw new InvalidArgumentException('Upload a valid PNG, JPEG, or WebP image.');
            }
            if ($imageInfo[0] > 5000 || $imageInfo[1] > 5000 || $imageInfo[0] * $imageInfo[1] > 20000000) {
                throw new InvalidArgumentException('The company logo dimensions are too large. Use an image up to 5000 × 5000 pixels.');
            }
            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                throw new RuntimeException('The logo upload directory could not be created.');
            }
            $newLogoFilename = 'company-logo-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
            if (!move_uploaded_file($logoUpload['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $newLogoFilename)) {
                $newLogoFilename = null;
                throw new RuntimeException('The uploaded company logo could not be saved.');
            }
            $values['company_logo'] = $newLogoFilename;
        } elseif (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
            $values['company_logo'] = '';
        }
        $pdo->beginTransaction();
        $save = $pdo->prepare('INSERT INTO company_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        foreach ($values as $key => $value) {
            $save->execute([$key, $value]);
        }
        $pdo->commit();
        if (($hasLogoUpload || (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1')) && $oldLogoFilename !== '') {
            $removeStoredLogo($oldLogoFilename);
        }
        flash('Company settings saved.');
        redirect('/dashboard/settings.php');
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newLogoFilename !== null) {
            $removeStoredLogo($newLogoFilename);
        }
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newLogoFilename !== null) {
            $removeStoredLogo($newLogoFilename);
        }
        error_log('Company settings update failed: ' . $exception->getMessage());
        $error = 'Settings could not be saved.';
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newLogoFilename !== null) {
            $removeStoredLogo($newLogoFilename);
        }
        error_log('Company logo upload failed: ' . $exception->getMessage());
        $error = 'The company logo could not be saved. Check that the uploads folder is writable and try again.';
    }
}
$stored = [];
foreach ($pdo->query('SELECT setting_key,setting_value FROM company_settings')->fetchAll() as $row) {
    $stored[$row['setting_key']] = $row['setting_value'];
}
$settings = array_merge($defaults, $stored);
$emailPaymentValues = [];
foreach (['mpesa_phone', 'mpesa_name', 'bank_name', 'bank_account_number', 'bank_account_name'] as $key) {
    $submittedValue = $_POST[$key] ?? null;
    $emailPaymentValues[$key] = is_string($submittedValue)
        ? $submittedValue
        : (string) ($settings[$key] ?? '');
}
$emailPaymentCompleteCount = count(array_filter(
    $emailPaymentValues,
    static fn(string $value): bool => trim($value) !== ''
));
$submittedCurrencies = $_POST['currencies'] ?? $_POST['invoice_currencies'] ?? null;
$selectedInvoiceCurrencies = is_array($submittedCurrencies)
    ? array_values(array_intersect(
        supported_invoice_currencies(),
        array_map(
            static fn(mixed $currency): string => is_string($currency) ? strtoupper(trim($currency)) : '',
            $submittedCurrencies
        )
    ))
    : parse_invoice_currencies((string) $settings['invoice_currencies']);
$pageTitle = 'Company settings';
$activePage = 'settings';
$pageSubtitle = 'Business profile and invoice defaults';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-head"><div><h1 class="display-lg">Company settings</h1><p class="page-head__sub">Business details are used on invoices and payment instructions.</p></div></div>
<?php if ($error !== ''): ?><div class="notice notice--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="card card__pad form-card">
  <div class="card__head"><div><h2 class="heading-lg">Business information</h2><p class="card__sub">These details are shown on your invoices and used for payment defaults.</p></div></div>
  <form class="data-form data-form--grid" method="post" enctype="multipart/form-data"><?= csrf_field() ?>
    <label class="field field--wide"><span class="field__label">Business name *</span><input class="input" name="business_name" maxlength="190" data-validation-required="true" required value="<?= e($_POST['business_name'] ?? $settings['business_name']) ?>"></label>
    <label class="field"><span class="field__label">Business email</span><input class="input" type="email" name="business_email" maxlength="190" value="<?= e($_POST['business_email'] ?? $settings['business_email']) ?>"></label>
    <label class="field"><span class="field__label">Business phone</span><input class="input" name="business_phone" maxlength="60" value="<?= e($_POST['business_phone'] ?? $settings['business_phone']) ?>"></label>
    <label class="field field--wide"><span class="field__label">Business address</span><textarea class="input textarea" name="business_address" maxlength="2000"><?= e($_POST['business_address'] ?? $settings['business_address']) ?></textarea></label>
    <div class="logo-settings field--wide">
      <div class="lg-intro">
        <h2 class="lg-heading">Company logo</h2>
        <p class="lg-sub">Upload the logo that represents your company. It is shown wherever your branding appears.</p>
      </div>
      <div class="lg-grid">
        <div class="lg-upload">
          <label class="lg-label" for="company-logo-file">Upload logo</label>
          <div class="lg-drop">
            <input id="company-logo-file" type="file" name="company_logo" accept="image/png,image/jpeg,image/webp" aria-describedby="company-logo-hint">
            <span class="lg-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg></span>
            <span class="lg-drop-text"><strong>Choose a file</strong> or drag and drop it here</span>
            <span class="lg-file" id="company-logo-name">No file chosen</span>
          </div>
          <p class="lg-hint" id="company-logo-hint">PNG, JPEG, or WebP. Maximum file size: 2 MB.</p>
        </div>
        <div class="lg-current">
          <span class="lg-label">Current logo</span>
          <div class="lg-preview">
            <?php if (!empty($settings['company_logo']) && preg_match('/\Acompany-logo-[a-f0-9]{32}\.(?:png|jpg|webp)\z/', $settings['company_logo'])): ?>
              <img src="<?= e(app_url('/assets/uploads/' . rawurlencode($settings['company_logo']))) ?>" alt="Current company logo">
            <?php else: ?>
              <span class="lg-empty">No logo uploaded yet</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($settings['company_logo']) && preg_match('/\Acompany-logo-[a-f0-9]{32}\.(?:png|jpg|webp)\z/', $settings['company_logo'])): ?>
            <label class="lg-remove"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
            <p class="lg-note">The logo will be removed when you save.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <script>
      (function () {
        var fileInput = document.getElementById('company-logo-file');
        var fileName = document.getElementById('company-logo-name');
        if (fileInput && fileName) {
          fileInput.addEventListener('change', function () {
            fileName.textContent = fileInput.files && fileInput.files.length ? fileInput.files[0].name : 'No file chosen';
          });
        }
      }());
    </script>
    <div class="board field--wide">
      <section class="invoice-defaults">
        <div class="df-intro">
          <h2 class="df-heading">Invoice defaults</h2>
          <p class="df-sub">Set the currencies, numbering and tax used when creating invoices.</p>
        </div>
        <fieldset>
          <legend>Currencies available for invoices</legend>
          <p class="df-hint">Choose which currencies can be selected when creating or editing an invoice. KES is the default when enabled.</p>
          <div class="df-grid">
            <?php foreach (supported_invoice_currencies() as $currency): ?>
              <?php $isCurrencySelected = in_array($currency, $selectedInvoiceCurrencies, true); ?>
              <?php $flagUrl = currency_flag_url($currency); ?>
              <label class="df-tile">
                <input id="currency-<?= e(strtolower($currency)) ?>" type="checkbox" name="currencies[]" value="<?= e($currency) ?>"<?= $isCurrencySelected ? ' checked' : '' ?>>
                <span class="df-flag" aria-hidden="true"><?php if ($flagUrl !== null): ?><img src="<?= e($flagUrl) ?>" alt="" width="40" height="40" loading="lazy" decoding="async" onerror="this.parentElement.classList.add('df-flag--fallback');this.remove();this.parentElement.textContent='<?= e($currency) ?>'"><?php else: ?><span class="df-flag-fallback"><?= e($currency) ?></span><?php endif; ?></span>
                <span class="df-info">
                  <span class="df-code"><?= e($currency) ?><?php if ($currency === 'KES' && $isCurrencySelected): ?><span class="df-badge">Default</span><?php endif; ?></span>
                  <span class="df-name"><?= e(['KES' => 'Kenyan shilling', 'USD' => 'US dollar', 'GBP' => 'British pound', 'EUR' => 'Euro'][$currency]) ?></span>
                </span>
                <span class="df-check" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <div class="df-fields">
          <div>
            <label class="df-label" for="df-prefix">Invoice number prefix <span>*</span></label>
            <input id="df-prefix" class="df-control" type="text" name="invoice_prefix" maxlength="10" minlength="2" pattern="[A-Za-z0-9]{2,10}" data-validation-required="true" data-validation-pattern-message="Use 2–10 letters or numbers." required value="<?= e($_POST['invoice_prefix'] ?? $settings['invoice_prefix']) ?>">
            <p class="df-field-hint">Added at the start of every invoice number.</p>
          </div>
          <div>
            <label class="df-label" for="df-tax">Default tax (%)</label>
            <div class="df-input">
              <input id="df-tax" class="df-control has-suffix" type="number" name="default_tax_rate" min="0" max="100" step="0.01" value="<?= e($_POST['default_tax_rate'] ?? $settings['default_tax_rate']) ?>">
              <span class="df-suffix">%</span>
            </div>
            <p class="df-field-hint">Applied to new invoices by default.</p>
          </div>
        </div>
      </section>
    </div>
    <div class="field--wide settings-fields-row settings-fields-row--two">
      <label class="field"><span class="field__label">Payment Details *</span><textarea class="input textarea" name="payment_instructions" maxlength="4000" data-validation-required="true" required><?= e($_POST['payment_instructions'] ?? $settings['payment_instructions']) ?></textarea></label>
      <label class="field"><span class="field__label">Payment Terms</span><textarea class="input textarea" name="payment_terms" maxlength="4000"><?= e($_POST['payment_terms'] ?? $settings['payment_terms']) ?></textarea></label>
    </div>
    <div class="email-payment-settings field--wide">
      <section class="ep-shell" aria-labelledby="email-payment-title">
        <div class="ep-head">
          <div>
            <h2 class="ep-title" id="email-payment-title">Email payment details</h2>
            <p class="ep-sub">Shown to clients in invoice emails so they know where to pay. Values are safely escaped before sending.</p>
          </div>
          <div class="ep-complete" role="status" aria-live="polite">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
            <span><span data-ep-count><?= $emailPaymentCompleteCount ?></span> of 5 complete</span>
          </div>
        </div>

        <section class="ep-group" aria-labelledby="ep-mpesa-title">
          <div class="ep-group-head">
            <span class="ep-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/></svg></span>
            <div><h3 class="ep-group-title" id="ep-mpesa-title">M-Pesa</h3><p class="ep-group-sub">Mobile money payments</p></div>
          </div>
          <div class="ep-grid ep-grid--two">
            <div class="ep-field">
              <label class="ep-label" for="email-mpesa-phone">M-Pesa phone/Paybill</label>
              <div class="ep-input">
                <input class="ep-control" id="email-mpesa-phone" type="tel" name="mpesa_phone" maxlength="60" value="<?= e($emailPaymentValues['mpesa_phone']) ?>" data-ep-input>
                <span class="ep-ok" aria-hidden="true"<?= trim($emailPaymentValues['mpesa_phone']) === '' ? ' hidden' : '' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>
              </div>
              <p class="ep-hint">The number or Paybill clients send money to.</p>
            </div>
            <div class="ep-field">
              <label class="ep-label" for="email-mpesa-name">M-Pesa account name</label>
              <div class="ep-input">
                <input class="ep-control" id="email-mpesa-name" type="text" name="mpesa_name" maxlength="190" value="<?= e($emailPaymentValues['mpesa_name']) ?>" data-ep-input>
                <span class="ep-ok" aria-hidden="true"<?= trim($emailPaymentValues['mpesa_name']) === '' ? ' hidden' : '' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>
              </div>
              <p class="ep-hint">Name registered on the M-Pesa line.</p>
            </div>
          </div>
        </section>

        <section class="ep-group" aria-labelledby="ep-bank-title">
          <div class="ep-group-head">
            <span class="ep-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 4l9 5.5"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20.5h18"/></svg></span>
            <div><h3 class="ep-group-title" id="ep-bank-title">Bank transfer</h3><p class="ep-group-sub">Direct deposit details</p></div>
          </div>
          <div class="ep-grid ep-grid--three">
            <div class="ep-field">
              <label class="ep-label" for="email-bank-name">Bank name</label>
              <div class="ep-input">
                <input class="ep-control" id="email-bank-name" type="text" name="bank_name" maxlength="190" value="<?= e($emailPaymentValues['bank_name']) ?>" data-ep-input>
                <span class="ep-ok" aria-hidden="true"<?= trim($emailPaymentValues['bank_name']) === '' ? ' hidden' : '' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>
              </div>
            </div>
            <div class="ep-field">
              <label class="ep-label" for="email-bank-account-number">Bank account number</label>
              <div class="ep-input">
                <input class="ep-control" id="email-bank-account-number" type="text" inputmode="numeric" name="bank_account_number" maxlength="100" value="<?= e($emailPaymentValues['bank_account_number']) ?>" data-ep-input>
                <span class="ep-ok" aria-hidden="true"<?= trim($emailPaymentValues['bank_account_number']) === '' ? ' hidden' : '' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>
              </div>
            </div>
            <div class="ep-field">
              <label class="ep-label" for="email-bank-account-name">Bank account name</label>
              <div class="ep-input">
                <input class="ep-control" id="email-bank-account-name" type="text" name="bank_account_name" maxlength="190" value="<?= e($emailPaymentValues['bank_account_name']) ?>" data-ep-input>
                <span class="ep-ok" aria-hidden="true"<?= trim($emailPaymentValues['bank_account_name']) === '' ? ' hidden' : '' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>
              </div>
            </div>
          </div>
        </section>

        <div class="ep-preview">
          <p class="ep-preview-label">How it appears in the email</p>
          <div class="ep-preview-grid">
            <div>
              <div class="ep-preview-title">Pay via M-Pesa/Paybill</div>
              <div class="ep-preview-value"><strong>Number:</strong> <span data-ep-preview="email-mpesa-phone"><?= e($emailPaymentValues['mpesa_phone']) ?></span></div>
              <div class="ep-preview-value"><strong>Account Name:</strong> <span data-ep-preview="email-mpesa-name"><?= e($emailPaymentValues['mpesa_name']) ?></span></div>
            </div>
            <div>
              <div class="ep-preview-title">Pay via bank transfer</div>
              <div class="ep-preview-value"><strong>Bank Name:</strong> <span data-ep-preview="email-bank-name"><?= e($emailPaymentValues['bank_name']) ?></span></div>
              <div class="ep-preview-value"><strong>Account Number:</strong> <span data-ep-preview="email-bank-account-number"><?= e($emailPaymentValues['bank_account_number']) ?></span></div>
              <div class="ep-preview-value"><strong>Account Name:</strong> <span data-ep-preview="email-bank-account-name"><?= e($emailPaymentValues['bank_account_name']) ?></span></div>
            </div>
          </div>
        </div>
      </section>
    </div>
    <section class="field--wide" aria-labelledby="renewal-reminder-settings-title">
      <div class="card__head"><div><h2 class="heading-lg" id="renewal-reminder-settings-title">Renewal reminders</h2><p class="card__sub">Daily expiry digests are sent only to these administrator addresses.</p></div></div>
      <label class="field"><span class="field__label">Renewal reminder recipients</span><input class="input" type="text" name="renewal_reminder_recipients" maxlength="2000" autocomplete="off" value="<?= e($_POST['renewal_reminder_recipients'] ?? $settings['renewal_reminder_recipients']) ?>"></label>
      <p class="field__hint">Enter one or more email addresses, separated by commas. Defaults to the first administrator account.</p>
    </section>
    <script>
      (function () {
        var section = document.querySelector('.email-payment-settings');
        if (!section) return;
        var inputs = section.querySelectorAll('[data-ep-input]');
        var count = section.querySelector('[data-ep-count]');
        inputs.forEach(function (input) {
          input.addEventListener('input', function () {
            var complete = 0;
            inputs.forEach(function (field) {
              var filled = field.value.trim() !== '';
              if (filled) complete += 1;
              field.closest('.ep-input').querySelector('.ep-ok').hidden = !filled;
              section.querySelectorAll('[data-ep-preview="' + field.id + '"]').forEach(function (preview) {
                preview.textContent = field.value;
              });
            });
            count.textContent = String(complete);
          });
        });
      }());
    </script>
    <div class="form-actions field--wide"><button class="btn btn--primary">Save settings</button></div>
  </form>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>