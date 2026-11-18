<?php
require_once dirname(__DIR__) . '/includes/email.php';
require_once dirname(__DIR__) . '/includes/currency_exchange.php';
require_once dirname(__DIR__) . '/includes/customer_domains.php';
require_auth();
$pdo = db();
$error = '';
$submissionToken = bin2hex(random_bytes(32));
if (!is_array($_SESSION['invoice_form_tokens'] ?? null)) {
    $_SESSION['invoice_form_tokens'] = [];
}
$_SESSION['invoice_form_tokens'][$submissionToken] = true;
if (count($_SESSION['invoice_form_tokens']) > 20) {
    $_SESSION['invoice_form_tokens'] = array_slice($_SESSION['invoice_form_tokens'], -20, null, true);
}
$availableCurrenciesQuery = $pdo->query("SELECT setting_value FROM company_settings WHERE setting_key='invoice_currencies'");
$availableCurrencies = parse_invoice_currencies($availableCurrenciesQuery->fetchColumn() ?: implode(',', supported_invoice_currencies()));
$customers = $pdo->query("SELECT id,name FROM customers WHERE status='active' ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT id,name,category,billing_cycle FROM products WHERE status='active' ORDER BY name")->fetchAll();
$taxStatement = $pdo->query("SELECT setting_value FROM company_settings WHERE setting_key='default_tax_rate'");
$defaultTax = $taxStatement->fetchColumn();
$editingInvoice = null;
$editingItems = [];
$editingPayments = [];
$removedPaymentIds = [];
$pendingPayments = [];
$paymentValidationError = '';
$paymentValidationField = '';
$reopenPaymentDialog = false;
$displayTotalCents = 0;
if (isset($_GET['id'])) {
    $editQuery = $pdo->prepare("SELECT * FROM invoices WHERE id=? AND status='draft'");
    $editQuery->execute([(int) $_GET['id']]);
    $editingInvoice = $editQuery->fetch() ?: null;
    if (!$editingInvoice) {
        flash('Only draft invoices can be edited.', 'error');
        redirect('/dashboard/invoices.php');
    }
    $itemQuery = $pdo->prepare('SELECT product_id,description,quantity,unit_price FROM invoice_items WHERE invoice_id=? ORDER BY id');
    $itemQuery->execute([$editingInvoice['id']]);
    $editingItems = $itemQuery->fetchAll();
    $paymentQuery = $pdo->prepare('SELECT id,amount,payment_date,method,reference FROM payments WHERE invoice_id=? ORDER BY payment_date ASC,id ASC');
    $paymentQuery->execute([$editingInvoice['id']]);
    $editingPayments = $paymentQuery->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $postedSubmissionToken = is_string($_POST['submission_token'] ?? null) ? $_POST['submission_token'] : '';
        if ($postedSubmissionToken === '' || !isset($_SESSION['invoice_form_tokens'][$postedSubmissionToken])) {
            throw new InvalidArgumentException('This invoice form has already been submitted. Reload the page before trying again.');
        }
        $rawRemovedPaymentIds = $_POST['removed_payment_ids'] ?? [];
        if (!is_array($rawRemovedPaymentIds) || count($rawRemovedPaymentIds) > 500) {
            throw new InvalidArgumentException('The payment removals have an invalid format.');
        }
        foreach ($rawRemovedPaymentIds as $rawPaymentId) {
            $paymentId = filter_var($rawPaymentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($paymentId === false) {
                throw new InvalidArgumentException('The payment removals have an invalid format.');
            }
            $removedPaymentIds[] = (int) $paymentId;
        }
        $removedPaymentIds = array_values(array_unique($removedPaymentIds));
        $rawPendingPayments = $_POST['pending_payments'] ?? [];
        if (!is_array($rawPendingPayments) || count($rawPendingPayments) > 50) {
            $paymentValidationError = 'Add no more than 50 payment records at a time.';
            $paymentValidationField = 'form';
            $reopenPaymentDialog = true;
            throw new InvalidArgumentException($paymentValidationError);
        }
        foreach ($rawPendingPayments as $rawPayment) {
            if (!is_array($rawPayment)) {
                $paymentValidationError = 'A payment record has an invalid format.';
                $paymentValidationField = 'form';
                $reopenPaymentDialog = true;
                throw new InvalidArgumentException($paymentValidationError);
            }
            $pendingPayments[] = [
                'amount' => is_string($rawPayment['amount'] ?? null) || is_numeric($rawPayment['amount'] ?? null)
                    ? trim((string) $rawPayment['amount'])
                    : '',
                'payment_date' => is_string($rawPayment['payment_date'] ?? null) ? trim($rawPayment['payment_date']) : '',
                'method' => is_string($rawPayment['method'] ?? null) ? trim($rawPayment['method']) : '',
                'reference' => is_string($rawPayment['reference'] ?? null) ? trim($rawPayment['reference']) : '',
            ];
        }
        $currencyCode = strtoupper(is_string($_POST['currency_code'] ?? null) ? trim($_POST['currency_code']) : 'KES');
        $allowedCurrencies = $availableCurrencies;
        if ($editingInvoice) {
            $allowedCurrencies[] = invoice_currency($editingInvoice);
        }
        if (!in_array($currencyCode, array_unique($allowedCurrencies), true)) {
            throw new InvalidArgumentException('Choose a currency that is enabled in company settings.');
        }
        $invoiceNotes = post_string('invoice_notes', 4000);
        $customerId = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT);
        $statement = $pdo->prepare("SELECT id FROM customers WHERE id=? AND status='active'");
        $statement->execute([$customerId]);
        if (!$customerId || !$statement->fetch()) {
            throw new InvalidArgumentException('Choose an active customer.');
        }
        $rawDomainId = $_POST['domain_id'] ?? '';
        $domainId = $rawDomainId === '' ? null : filter_var($rawDomainId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($rawDomainId !== '' && $domainId === false) {
            throw new InvalidArgumentException('Choose a valid domain.');
        }
        $issueNow = isset($_POST['issue_now']);
        $recipientSnapshot = customer_domain_recipient(
            $pdo,
            (int) $customerId,
            $domainId === false ? null : ($domainId === null ? null : (int) $domainId),
            $issueNow
        );
        $issueDate = is_string($_POST['issue_date'] ?? null) ? $_POST['issue_date'] : '';
        $dueDate = is_string($_POST['due_date'] ?? null) ? $_POST['due_date'] : '';
        $dateValid = static function (string $date): bool {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return false;
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $errors = DateTimeImmutable::getLastErrors();
            return $parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $parsed->format('Y-m-d') === $date;
        };
        if (!$dateValid($issueDate) || !$dateValid($dueDate) || $issueDate > date('Y-m-d')) {
            throw new InvalidArgumentException('Enter valid dates; the issue date cannot be in the future.');
        }
        $percentToBasisPoints = static function (string $value): int {
            if (!preg_match('/^(?:100(?:\.00?)?|(?:\d|[1-9]\d)(?:\.\d{1,2})?)$/', $value)) {
                throw new InvalidArgumentException('Discount and tax must be between 0 and 100 percent.');
            }
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        };
        $discountInput = $_POST['discount_percent'] ?? '0';
        $taxInput = $_POST['tax_percent'] ?? '0';
        if ((!is_string($discountInput) && !is_numeric($discountInput)) || (!is_string($taxInput) && !is_numeric($taxInput))) {
            throw new InvalidArgumentException('Discount and tax must be valid percentages.');
        }
        $discountBps = $percentToBasisPoints((string) $discountInput);
        $taxBps = $percentToBasisPoints((string) $taxInput);
        $names = $_POST['item_description'] ?? [];
        $quantities = $_POST['item_quantity'] ?? [];
        $prices = $_POST['item_price'] ?? [];
        $productIds = $_POST['item_product'] ?? [];
        if ($pendingPayments) {
            $hasItemDescription = is_array($names) && count(array_filter(
                $names,
                static fn(mixed $name): bool => is_string($name) && trim($name) !== ''
            )) > 0;
            if (!$hasItemDescription) {
                $paymentValidationError = 'Add invoice items first.';
                $paymentValidationField = 'form';
                $reopenPaymentDialog = true;
                throw new InvalidArgumentException($paymentValidationError);
            }
        }
        if (!is_array($names) || !is_array($quantities) || !is_array($prices) || !is_array($productIds) || count($names) < 1 || count($names) > 50 || count($names) !== count($quantities) || count($names) !== count($prices) || count($names) !== count($productIds)) {
            throw new InvalidArgumentException('Add between one and 50 valid invoice items.');
        }
        $items = [];
        $subtotalCents = 0;
        foreach ($names as $index => $rawName) {
            if (!is_string($rawName)) {
                throw new InvalidArgumentException('Each invoice item needs a text description.');
            }
            $description = trim($rawName);
            if ($description === '' || mb_strlen($description) > 255) {
                throw new InvalidArgumentException('Each invoice item needs a description of 255 characters or fewer.');
            }
            $quantityInput = $quantities[$index] ?? null;
            if (!is_string($quantityInput) && !is_numeric($quantityInput)) {
                throw new InvalidArgumentException('Item quantities must be valid numbers.');
            }
            $quantityText = (string) $quantityInput;
            if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $quantityText) || (float) $quantityText <= 0 || (float) $quantityText > 100000) {
                throw new InvalidArgumentException('Item quantities must be positive, at most 100,000, and may have up to two decimals.');
            }
            [$quantityWhole, $quantityFraction] = array_pad(explode('.', $quantityText, 2), 2, '');
            $quantityHundredths = ((int) $quantityWhole * 100) + (int) str_pad($quantityFraction, 2, '0');
            $unitCents = money_to_cents($prices[$index] ?? '');
            if ($unitCents <= 0 || $unitCents > 9999999999) {
                throw new InvalidArgumentException('Unit prices are outside the supported maximum.');
            }
            $lineCents = invoice_line_cents($unitCents, $quantityHundredths);
            if ($lineCents <= 0 || $lineCents > 999999999999) {
                throw new InvalidArgumentException('Invoice line totals must be positive and within the supported maximum.');
            }
            $rawProductId = $productIds[$index] ?? '';
            if (!is_string($rawProductId) && !is_numeric($rawProductId)) {
                throw new InvalidArgumentException('Choose a valid catalogue product for each selected item.');
            }
            $productId = $rawProductId === '' ? null : filter_var($rawProductId, FILTER_VALIDATE_INT);
            if ($rawProductId !== '' && !$productId) {
                throw new InvalidArgumentException('Choose a valid catalogue product for each selected item.');
            }
            if ($productId !== null) {
                $productCheck = $pdo->prepare("SELECT id FROM products WHERE id=? AND status='active'");
                $productCheck->execute([$productId]);
                if (!$productCheck->fetch()) {
                    throw new InvalidArgumentException('One of the selected products is no longer active.');
                }
            }
            $items[] = [$productId, $description, number_format($quantityHundredths / 100, 2, '.', ''), cents_to_decimal($unitCents), cents_to_decimal($lineCents)];
            $subtotalCents += $lineCents;
            if ($subtotalCents > 999999999999) {
                throw new InvalidArgumentException('Invoice subtotal exceeds the supported maximum.');
            }
        }
        $discountCents = percentage_cents($subtotalCents, $discountBps);
        $taxableCents = $subtotalCents - $discountCents;
        $taxCents = percentage_cents($taxableCents, $taxBps);
        $totalCents = $subtotalCents - $discountCents + $taxCents;
        $displayTotalCents = $totalCents;
        $pendingPaymentCents = 0;
        foreach ($pendingPayments as $payment) {
            $reopenPaymentDialog = true;
            if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $payment['amount'])) {
                $paymentValidationError = 'The amount must be a number above 0, with at most 2 decimals.';
                $paymentValidationField = 'amount';
                throw new InvalidArgumentException($paymentValidationError);
            }
            $amountCents = money_to_cents($payment['amount']);
            if ($amountCents <= 0) {
                $paymentValidationError = 'The amount must be a number above 0, with at most 2 decimals.';
                $paymentValidationField = 'amount';
                throw new InvalidArgumentException($paymentValidationError);
            }
            if (!$dateValid($payment['payment_date']) || $payment['payment_date'] > date('Y-m-d')) {
                $paymentValidationError = 'Choose a valid payment date no later than today.';
                $paymentValidationField = 'date';
                throw new InvalidArgumentException($paymentValidationError);
            }
            if ($payment['method'] === '' || !in_array($payment['method'], PAYMENT_METHODS, true)) {
                $paymentValidationError = 'Choose a valid payment method.';
                $paymentValidationField = 'method';
                throw new InvalidArgumentException($paymentValidationError);
            }
            if ($payment['reference'] === '' || mb_strlen($payment['reference']) > 150) {
                $paymentValidationError = 'Enter a transaction code of 150 characters or fewer.';
                $paymentValidationField = 'reference';
                throw new InvalidArgumentException($paymentValidationError);
            }
            if ($totalCents <= 0) {
                $paymentValidationError = 'Add invoice items first.';
                $paymentValidationField = 'form';
                throw new InvalidArgumentException($paymentValidationError);
            }
            $pendingPaymentCents += $amountCents;
            if ($pendingPaymentCents > 999999999999) {
                $paymentValidationError = 'The payment total is outside the supported maximum.';
                $paymentValidationField = 'form';
                throw new InvalidArgumentException($paymentValidationError);
            }
        }
        if ($totalCents <= 0 || $totalCents > 999999999999) {
            throw new InvalidArgumentException($pendingPayments
                ? 'Add invoice items first.'
                : 'Invoice total must be greater than zero and within the supported maximum.');
        }
        $status = $issueNow ? 'issued' : 'draft';
        $existingId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $fxSnapshot = $status === 'issued'
            ? kes_rate_for_invoice_date($pdo, $currencyCode, $issueDate)
            : ['rate' => null, 'date' => null];
        $pdo->beginTransaction();
        $recipientSnapshot = customer_domain_recipient(
            $pdo,
            (int) $customerId,
            $domainId === null ? null : (int) $domainId,
            $issueNow,
            true
        );
        if ($existingId > 0) {
            $draftLock = $pdo->prepare('SELECT status FROM invoices WHERE id=? FOR UPDATE');
            $draftLock->execute([$existingId]);
            if ($draftLock->fetchColumn() !== 'draft') {
                throw new InvalidArgumentException('This invoice has already been issued and cannot be edited.');
            }
            $pdo->prepare('UPDATE invoices SET customer_id=?,domain_id=?,domain_name=?,recipient_email=?,company_name_snapshot=?,billing_address_snapshot=?,phone_number_snapshot=?,currency_code=?,kes_exchange_rate=?,fx_rate_date=?,status=?,issue_date=?,due_date=?,subtotal=?,discount_percent=?,tax_percent=?,tax_amount=?,total=?,notes=? WHERE id=?')->execute([$customerId, $recipientSnapshot['domain_id'], $recipientSnapshot['domain_name'], $recipientSnapshot['recipient_email'], $recipientSnapshot['company_name_snapshot'], $recipientSnapshot['billing_address_snapshot'], $recipientSnapshot['phone_number_snapshot'], $currencyCode, $fxSnapshot['rate'], $fxSnapshot['date'], $status, $issueDate, $dueDate, cents_to_decimal($subtotalCents), number_format($discountBps / 100, 2, '.', ''), number_format($taxBps / 100, 2, '.', ''), cents_to_decimal($taxCents), cents_to_decimal($totalCents), $invoiceNotes, $existingId]);
            $invoiceId = $existingId;
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$invoiceId]);
        } else {
            $insert = $pdo->prepare('INSERT INTO invoices (customer_id,domain_id,domain_name,recipient_email,company_name_snapshot,billing_address_snapshot,phone_number_snapshot,currency_code,kes_exchange_rate,fx_rate_date,status,issue_date,due_date,subtotal,discount_percent,tax_percent,tax_amount,total,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([$customerId, $recipientSnapshot['domain_id'], $recipientSnapshot['domain_name'], $recipientSnapshot['recipient_email'], $recipientSnapshot['company_name_snapshot'], $recipientSnapshot['billing_address_snapshot'], $recipientSnapshot['phone_number_snapshot'], $currencyCode, $fxSnapshot['rate'], $fxSnapshot['date'], $status, $issueDate, $dueDate, cents_to_decimal($subtotalCents), number_format($discountBps / 100, 2, '.', ''), number_format($taxBps / 100, 2, '.', ''), cents_to_decimal($taxCents), cents_to_decimal($totalCents), $invoiceNotes, current_user()['id']]);
            $invoiceId = (int) $pdo->lastInsertId();
        }
        if ($status === 'issued') {
            $prefixQuery = $pdo->prepare("SELECT setting_value FROM company_settings WHERE setting_key='invoice_prefix'");
            $prefixQuery->execute();
            $prefix = $prefixQuery->fetchColumn() ?: 'INV';
            $number = $prefix . '-' . date('Y', strtotime($issueDate)) . '-' . str_pad((string) $invoiceId, 6, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE invoices SET invoice_number=? WHERE id=?')->execute([$number, $invoiceId]);
            create_public_invoice_link($pdo, (int) $invoiceId);
        }
        $insertItem = $pdo->prepare('INSERT INTO invoice_items (invoice_id,product_id,description,quantity,unit_price,line_total) VALUES (?,?,?,?,?,?)');
        foreach ($items as [$productId, $description, $quantity, $price, $lineTotal]) {
            $insertItem->execute([$invoiceId, $productId, $description, $quantity, $price, $lineTotal]);
        }
        $recordedPaymentsQuery = $pdo->prepare('SELECT id,amount,reference FROM payments WHERE invoice_id=? FOR UPDATE');
        $recordedPaymentsQuery->execute([$invoiceId]);
        $recordedPayments = $recordedPaymentsQuery->fetchAll();
        $existingPaymentIds = array_map(static fn(array $payment): int => (int) $payment['id'], $recordedPayments);
        if (array_diff($removedPaymentIds, $existingPaymentIds) !== []) {
            throw new InvalidArgumentException('One of the payment records no longer belongs to this draft invoice.');
        }
        if ($removedPaymentIds !== []) {
            $deletePayment = $pdo->prepare('DELETE FROM payments WHERE id=? AND invoice_id=?');
            foreach ($removedPaymentIds as $paymentId) {
                $deletePayment->execute([$paymentId, $invoiceId]);
                if ($deletePayment->rowCount() !== 1) {
                    throw new InvalidArgumentException('A payment record could not be removed. Reload the draft and try again.');
                }
            }
        }
        $recordedPaymentCents = array_reduce(
            $recordedPayments,
            static fn(int $total, array $payment): int => in_array((int) $payment['id'], $removedPaymentIds, true)
                ? $total
                : $total + money_to_cents($payment['amount']),
            0
        );
        if ($editingInvoice && $recordedPaymentCents > 0 && $currencyCode !== invoice_currency($editingInvoice)) {
            throw new InvalidArgumentException('Remove the recorded payments before changing this invoice currency.');
        }
        if ($recordedPaymentCents > $totalCents) {
            throw new InvalidArgumentException(
                'The payments already recorded (' . money(cents_to_decimal($recordedPaymentCents), $currencyCode) .
                ') exceed the new invoice total (' . money(cents_to_decimal($totalCents), $currencyCode) .
                '). Adjust the invoice items before saving.'
            );
        }
        $balanceCents = $totalCents - $recordedPaymentCents;
        $insertPayment = $pdo->prepare('INSERT INTO payments (invoice_id,amount,payment_date,method,reference,notes,created_by) VALUES (?,?,?,?,?,?,?)');
        $existingReferenceQuery = $pdo->prepare('SELECT 1 FROM payments WHERE invoice_id=? AND reference=? LIMIT 1');
        foreach ($pendingPayments as $payment) {
            $amountCents = money_to_cents($payment['amount']);
            if ($amountCents > $balanceCents) {
                $paymentValidationError = 'Amount is more than the balance of ' . money(cents_to_decimal($balanceCents), $currencyCode) . '.';
                $paymentValidationField = 'amount';
                $reopenPaymentDialog = true;
                throw new InvalidArgumentException($paymentValidationError);
            }
            $existingReferenceQuery->execute([$invoiceId, $payment['reference']]);
            if ($existingReferenceQuery->fetchColumn()) {
                $paymentValidationError = 'This transaction code is already used for this invoice.';
                $paymentValidationField = 'reference';
                $reopenPaymentDialog = true;
                throw new InvalidArgumentException($paymentValidationError);
            }
            $balanceCents -= $amountCents;
            $insertPayment->execute([
                $invoiceId,
                cents_to_decimal($amountCents),
                $payment['payment_date'],
                $payment['method'],
                $payment['reference'],
                null,
                current_user()['id'],
            ]);
        }
        $finalPaidCents = $recordedPaymentCents + $pendingPaymentCents;
        $finalStatus = invoice_status([
            'status' => $status,
            'due_date' => $dueDate,
            'total' => cents_to_decimal($totalCents),
            'paid_amount' => cents_to_decimal($finalPaidCents),
        ]);
        $pdo->prepare('UPDATE invoices SET status=? WHERE id=?')->execute([$finalStatus, $invoiceId]);
        unset($_SESSION['invoice_form_tokens'][$postedSubmissionToken]);
        $pdo->commit();
        if ($status === 'issued') {
            $noticeMessages = [];
            $noticeType = 'success';
            $hasRecordedPayments = $pendingPaymentCents > 0 || $recordedPaymentCents > 0;
            if (!$hasRecordedPayments) {
                try {
                    $emailResult = sendInvoiceEmail((int) $invoiceId, 'invoice_issued', 'system');
                    $notice = invoice_issued_email_notice($emailResult);
                    $noticeMessages[] = $notice['message'];
                    if ($notice['type'] === 'error') {
                        $noticeType = 'error';
                    }
                } catch (Throwable $mailException) {
                    error_log('Invoice email failed after issue-now action: ' . safe_email_error($mailException->getMessage()));
                    $noticeMessages[] = 'Invoice issued, but its email could not be sent. Check the invoice email log and settings.';
                    $noticeType = 'error';
                }
            }
            if ($hasRecordedPayments) {
                try {
                    $paymentEmailResult = sendInvoiceEmail((int) $invoiceId, 'payment_received', 'system');
                    $paymentNotice = payment_received_email_notice($paymentEmailResult);
                    $noticeMessages[] = $paymentNotice['message'];
                    if ($paymentNotice['type'] === 'error') {
                        $noticeType = 'error';
                    }
                } catch (Throwable $mailException) {
                    error_log('Payment confirmation email failed after invoice issue-now action: ' . safe_email_error($mailException->getMessage()));
                    $noticeMessages[] = 'The customer payment confirmation email could not be sent. Check the invoice email log and settings.';
                    $noticeType = 'error';
                }
            }
            flash(implode(' ', $noticeMessages), $noticeType);
        } else {
            flash($existingId > 0 ? 'Draft invoice updated.' : 'Draft invoice saved.');
        }
        redirect('/dashboard/invoice_view.php?id=' . $invoiceId);
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
        if ($reopenPaymentDialog && $paymentValidationError !== '') {
            $error = '';
        }
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Invoice creation failed during a database operation.');
        if (str_contains($exception->getMessage(), 'uq_payment_invoice_reference')) {
            $paymentValidationError = 'This transaction code is already used for this invoice.';
            $paymentValidationField = 'reference';
            $reopenPaymentDialog = true;
            $error = '';
        } else {
            $error = 'The invoice could not be saved. Verify the customer and line items, then try again.';
        }
    }
}

$pageTitle = 'Create invoice';
$pageTitle = $editingInvoice ? 'Edit draft invoice' : $pageTitle;

$issueDateValue = (string) ($_POST['issue_date'] ?? $editingInvoice['issue_date'] ?? date('Y-m-d'));
$dueDateValue = (string) ($_POST['due_date'] ?? $editingInvoice['due_date'] ?? date('Y-m-d', strtotime('+30 days')));
$defaultInvoiceCurrency = in_array('KES', $availableCurrencies, true) ? 'KES' : ($availableCurrencies[0] ?? 'KES');
$formCurrencyCode = strtoupper((string) ($_POST['currency_code'] ?? ($editingInvoice ? invoice_currency($editingInvoice) : $defaultInvoiceCurrency)));
$formCurrencyChoices = $availableCurrencies;
if ($editingInvoice && !in_array(invoice_currency($editingInvoice), $formCurrencyChoices, true)) {
    $formCurrencyChoices[] = invoice_currency($editingInvoice);
}
if (!in_array($formCurrencyCode, $formCurrencyChoices, true)) {
    $formCurrencyCode = 'KES';
}
$invoiceNotesValue = (string) ($_POST['invoice_notes'] ?? ($editingInvoice['notes'] ?? ''));
$selectedCustomerId = (string) ($_POST['customer_id'] ?? $editingInvoice['customer_id'] ?? '');
$selectedDomainId = (string) ($_POST['domain_id'] ?? $editingInvoice['domain_id'] ?? '');
$activePage = 'invoices';
$invoiceFormStyles = true;
$pageSubtitle = 'Build and issue an invoice';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-head"><div><h1 class="display-lg"><?= $editingInvoice ? 'Edit draft invoice' : 'Create invoice' ?></h1><p class="page-head__sub">Choose a customer and add one or more invoice items.</p></div></div>
<?php if ($error !== ''): ?><div class="notice notice--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (!$customers): ?><div class="notice notice--warning">Add a customer before creating an invoice. <a class="link" href="<?= e(app_url('/dashboard/clients.php')) ?>">Manage customers</a></div><?php else: ?>
<?php
$formLines = [];
if (is_array($_POST['item_description'] ?? null)) {
    foreach ($_POST['item_description'] as $index => $description) {
        $formLines[] = ['product_id' => $_POST['item_product'][$index] ?? '', 'description' => $description, 'quantity' => $_POST['item_quantity'][$index] ?? '1', 'unit_price' => $_POST['item_price'][$index] ?? ''];
    }
} elseif ($editingItems) {
    $formLines = $editingItems;
} else {
    $formLines[] = ['product_id' => '', 'description' => '', 'quantity' => '1', 'unit_price' => ''];
}
$existingPaymentsCents = array_reduce(
    $editingPayments,
    static fn(int $total, array $payment): int => in_array((int) $payment['id'], $removedPaymentIds, true)
        ? $total
        : $total + money_to_cents($payment['amount']),
    0
);
$pendingPaymentsCents = 0;
foreach ($pendingPayments as $payment) {
    if (preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $payment['amount'])) {
        $pendingPaymentsCents += money_to_cents($payment['amount']);
    }
}
$formTotalCents = $displayTotalCents > 0
    ? $displayTotalCents
    : ($editingInvoice ? money_to_cents($editingInvoice['total']) : 0);
$formPaymentsCents = $existingPaymentsCents + $pendingPaymentsCents;
$formBalanceCents = max(0, $formTotalCents - $formPaymentsCents);
$initialIssueNow = $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['issue_now']) : !$editingInvoice;
$initialStatus = invoice_status([
    'status' => $initialIssueNow ? 'issued' : 'draft',
    'due_date' => $dueDateValue,
    'total' => cents_to_decimal($formTotalCents),
    'paid_amount' => cents_to_decimal($formPaymentsCents),
]);
$initialStatusKey = match ($initialStatus) {
    'partially_paid' => 'partial',
    'issued' => 'unpaid',
    default => $initialStatus,
};
$initialStatusLabel = match ($initialStatusKey) {
    'partial' => 'Partially paid',
    'paid' => 'Paid',
    'overdue' => 'Overdue',
    'draft' => 'Draft',
    default => 'Unpaid',
};
$initialPercentPaid = $formTotalCents > 0
    ? min(100, (int) round($formPaymentsCents * 100 / $formTotalCents))
    : 0;
$initialBalanceCents = max(0, $formTotalCents - $formPaymentsCents);
$formatSummaryAmount = static function (int $cents): string {
    $amount = number_format($cents / 100, 2, '.', ',');
    return rtrim(rtrim($amount, '0'), '.');
};
?>
<form method="post" class="invoice-form card card__pad" id="invoice-form" data-disable-on-submit data-existing-paid-cents="<?= e($existingPaymentsCents) ?>" data-currency-code="<?= e($formCurrencyCode) ?>" data-current-date="<?= e(date('Y-m-d')) ?>" data-customer-domains-url="<?= e(app_url('/dashboard/customer_domains.php')) ?>"><?= csrf_field() ?><input type="hidden" name="submission_token" value="<?= e($submissionToken) ?>"><input type="hidden" name="invoice_id" value="<?= e($editingInvoice['id'] ?? '') ?>">
  <?php foreach ($removedPaymentIds as $paymentId): ?><input type="hidden" name="removed_payment_ids[]" value="<?= e($paymentId) ?>"><?php endforeach; ?>
  <div class="card__head"><div><h2 class="heading-lg">Invoice details</h2><p class="card__sub">Add the customer, dates, and line items for this invoice.</p></div></div>
  <div class="form-row">
    <div class="invoice-party field--wide">
      <div class="ip-grid">
        <div class="field invoice-customer-field">
          <label for="invoice-customer-search" class="field__label">Customer <span class="ip-required">*</span></label>
          <div class="invoice-customer-search-wrap ip-search-wrap"><input class="input invoice-customer-search ip-control" id="invoice-customer-search" type="search" placeholder="Search for a customer" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="invoice-customer-options" aria-expanded="false" required><div class="invoice-customer-options" id="invoice-customer-options" role="listbox" hidden></div></div>
          <select class="sr-only" id="invoice-customer" name="customer_id" aria-hidden="true" tabindex="-1"><option value="">Choose a customer</option><?php foreach ($customers as $customer): ?><option value="<?= e($customer['id']) ?>"<?= $selectedCustomerId === (string) $customer['id'] ? ' selected' : '' ?>><?= e($customer['name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="invoice-domain-picker ip-domain-picker" data-invoice-domain-picker hidden>
          <label class="field"><span class="field__label" data-invoice-domain-label>Domain</span><input class="input invoice-domain-search ip-control" type="search" placeholder="Search domains" aria-label="Search domains" data-invoice-domain-search hidden><select class="input ip-control" name="domain_id" data-invoice-domain-select data-initial-domain-id="<?= e($selectedDomainId) ?>"><option value="">Choose a domain</option></select></label>
        </div>
        <div class="invoice-recipient-line ip-recipient" data-invoice-recipient-line role="status" aria-live="polite">
          <span class="ip-mail" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg></span>
          <div class="ip-body"><span class="ip-label">Invoice will be sent to:</span><strong class="invoice-recipient-line__value ip-email" data-invoice-recipient-value>—</strong></div>
          <p class="invoice-domain-name ip-domain" data-invoice-domain-name<?= empty($editingInvoice['domain_name']) ? ' hidden' : '' ?>><span>Domain:</span><strong><a class="invoice-domain-link" data-invoice-domain-value href="<?= !empty($editingInvoice['domain_name']) ? e('https://' . $editingInvoice['domain_name']) : '#' ?>" target="_blank" rel="noopener noreferrer"><?= e($editingInvoice['domain_name'] ?? '') ?></a></strong></p>
        </div>
         <p class="field__error ip-error" data-invoice-recipient-error role="alert" hidden></p>
         <div class="invoice-domain-details" data-invoice-domain-details hidden>
           <p><span>Company:</span> <strong data-invoice-company-name></strong></p>
           <p hidden><span>Phone number:</span> <strong data-invoice-domain-phone></strong></p>
           <p><span>Billing address:</span> <strong data-invoice-billing-address></strong></p>
         </div>
      </div>
    </div>
    <label class="field"><span class="field__label">Issue date *</span><input class="input" type="date" name="issue_date" max="<?= e(date('Y-m-d')) ?>" data-validation-required="true" required value="<?= e($issueDateValue) ?>"></label>
    <label class="field"><span class="field__label">Due date *</span><input class="input" type="date" name="due_date" data-validation-required="true" required value="<?= e($dueDateValue) ?>"></label>
  </div>
  <section class="line-items">
    <div class="li-intro"><h2 class="li-heading">Line items</h2><p class="li-sub">Add the products or services for this invoice, with the quantity and unit price for each.</p></div>
    <div class="li-head" aria-hidden="true"><span>Catalogue item</span><span>Description <i>*</i></span><span>Qty <i>*</i></span><span>Unit price <i>*</i></span><span></span></div>
    <div class="li-rows" id="invoice-items">
      <?php foreach ($formLines as $lineIndex => $line): ?>
      <div class="invoice-item-row li-row">
        <div class="li-cell li-cat"><label class="li-label" for="invoice-item-product-<?= e($lineIndex) ?>">Catalogue item</label><select id="invoice-item-product-<?= e($lineIndex) ?>" class="input item-product li-control" name="item_product[]"><option value="">Select catalogue item</option><?php foreach ($products as $product): $productLabel = $product['name'] . ' · ' . $product['category'] . ' (' . ucfirst((string) $product['billing_cycle']) . ')'; ?><option value="<?= e($product['id']) ?>" data-name="<?= e($product['name']) ?>"<?= (string) ($line['product_id'] ?? '') === (string) $product['id'] ? ' selected' : '' ?>><?= e($productLabel) ?></option><?php endforeach; ?></select></div>
        <div class="li-cell li-desc"><label class="li-label" for="invoice-item-description-<?= e($lineIndex) ?>">Description <span>*</span></label><input id="invoice-item-description-<?= e($lineIndex) ?>" class="input item-description li-control" name="item_description[]" maxlength="255" data-validation-required="true" required value="<?= e($line['description']) ?>"></div>
        <div class="li-cell li-qty"><label class="li-label" for="invoice-item-quantity-<?= e($lineIndex) ?>">Qty <span>*</span></label><input id="invoice-item-quantity-<?= e($lineIndex) ?>" class="input li-control" type="number" name="item_quantity[]" min="0.01" max="100000" step="0.01" data-validation-required="true" required value="<?= e($line['quantity']) ?>"></div>
        <div class="li-cell li-price"><label class="li-label" for="invoice-item-price-<?= e($lineIndex) ?>">Unit price <span>*</span></label><input id="invoice-item-price-<?= e($lineIndex) ?>" class="input item-price li-control" type="number" name="item_price[]" min="0.01" step="0.01" data-validation-required="true" required value="<?= e($line['unit_price']) ?>"></div>
        <button class="remove-item li-remove" type="button" aria-label="Remove item">Remove</button>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="li-add" type="button" id="add-invoice-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add line item</button>
  </section>
  <div class="form-row form-row--narrow invoice-totals-fields">
    <label class="field"><span class="field__label">Currency</span><select class="input" name="currency_code" id="invoice-currency" data-validation-required="true" required><?php foreach ($formCurrencyChoices as $currency): ?><option value="<?= e($currency) ?>"<?= $formCurrencyCode === $currency ? ' selected' : '' ?>><?= e($currency) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span class="field__label">Discount (%)</span><input class="input" type="number" name="discount_percent" min="0" max="100" step="0.01" value="<?= e($_POST['discount_percent'] ?? $editingInvoice['discount_percent'] ?? '0') ?>"></label>
    <label class="field"><span class="field__label">Tax (%)</span><input class="input" type="number" name="tax_percent" min="0" max="100" step="0.01" value="<?= e($_POST['tax_percent'] ?? $editingInvoice['tax_percent'] ?? ($defaultTax ?: '0')) ?>"></label>
  </div>
  <label class="field"><span class="field__label">Terms/Notes</span><textarea class="input textarea" name="invoice_notes" maxlength="4000"><?= e($invoiceNotesValue) ?></textarea><span class="field__hint">Leave blank to use the default terms/notes from Company settings.</span></label>
  <section class="pay-summary" data-state="<?= $initialBalanceCents === 0 && $formTotalCents > 0 ? 'paid' : 'due' ?>" aria-labelledby="pay-summary-title">
    <div class="ps-row">
      <div class="ps-title-block">
        <h2 class="ps-title" id="pay-summary-title">Payment summary</h2>
        <span class="ps-pill" data-status="<?= e($initialStatusKey) ?>" role="status"><span class="ps-dot"></span><span class="ps-pill-text"><?= e($initialStatusLabel) ?></span></span>
      </div>
      <div class="ps-stat"><div class="ps-label">Total</div><div class="ps-value" id="invoice-summary-total"><?= e($formCurrencyCode) ?> <?= e($formatSummaryAmount($formTotalCents)) ?></div></div>
      <div class="ps-stat"><div class="ps-label">Payments recorded</div><div class="ps-value" id="invoice-summary-paid"><?= e($formCurrencyCode) ?> <?= e($formatSummaryAmount($formPaymentsCents)) ?></div></div>
      <div class="ps-balance">
        <div class="ps-balance-label"><span>Balance due</span><span class="ps-pct" id="invoice-payment-meta-percent"><?= e($initialPercentPaid) ?>% paid</span></div>
        <div class="ps-amount" aria-live="polite"><span class="ps-cur"><?= e($formCurrencyCode) ?></span><span class="ps-num"><?= e($formatSummaryAmount($initialBalanceCents)) ?></span></div>
      </div>
      <button type="button" class="ps-btn ps-btn--danger" id="open-payment-dialog" aria-label="Add payment record" title="<?= $formTotalCents <= 0 ? 'Add invoice items first' : ($initialBalanceCents <= 0 ? 'This invoice is fully paid' : 'Add payment record') ?>"<?= $formTotalCents <= 0 || $initialBalanceCents <= 0 ? ' disabled' : '' ?>>
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 3v10M3 8h10"/></svg>
        <span>Add payment record</span>
      </button>
    </div>
    <div class="ps-track" id="invoice-payment-progress" role="progressbar" aria-label="Amount paid" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e($initialPercentPaid) ?>"><div class="ps-fill pct-tens-<?= intdiv($initialPercentPaid, 10) ?> pct-ones-<?= $initialPercentPaid % 10 ?> pct-decimals-0" id="invoice-payment-progress-fill"></div></div>
  </section>
  <?php if ($editingPayments || $pendingPayments): ?>
  <section class="invoice-payment-records" aria-label="Payment records">
    <h3>Payment records</h3>
    <ul id="invoice-payment-record-list">
      <?php foreach ($editingPayments as $payment): ?>
      <?php if (!in_array((int) $payment['id'], $removedPaymentIds, true)): ?><li class="invoice-payment-record" data-recorded-payment="<?= e($payment['id']) ?>" data-amount-cents="<?= e(money_to_cents($payment['amount'])) ?>" data-payment-reference="<?= e($payment['reference'] ?? '') ?>">
        <span><?= e($payment['payment_date']) ?> · <?= e($payment['method'] !== '' ? $payment['method'] : '—') ?> · <?= e($payment['reference'] ?: '—') ?></span>
        <strong data-payment-amount-cents="<?= e(money_to_cents($payment['amount'])) ?>"><?= money($payment['amount'], $formCurrencyCode) ?></strong>
        <span class="invoice-payment-record__state">Recorded</span>
        <button class="link link--danger invoice-payment-remove" type="button" data-remove-recorded-payment>Remove</button>
      </li><?php endif; ?>
      <?php endforeach; ?>
      <?php foreach ($pendingPayments as $index => $payment): ?>
      <li class="invoice-payment-record" data-pending-payment="<?= e($index) ?>" data-payment-reference="<?= e($payment['reference']) ?>">
        <input type="hidden" name="pending_payments[<?= e($index) ?>][amount]" value="<?= e($payment['amount']) ?>">
        <input type="hidden" name="pending_payments[<?= e($index) ?>][payment_date]" value="<?= e($payment['payment_date']) ?>">
        <input type="hidden" name="pending_payments[<?= e($index) ?>][method]" value="<?= e($payment['method']) ?>">
        <input type="hidden" name="pending_payments[<?= e($index) ?>][reference]" value="<?= e($payment['reference']) ?>">
        <span><?= e($payment['payment_date']) ?> · <?= e($payment['method'] !== '' ? $payment['method'] : '—') ?> · <?= e($payment['reference'] !== '' ? $payment['reference'] : '—') ?></span>
        <strong<?= e($payment['amount']) !== '' && preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $payment['amount']) ? ' data-payment-amount-cents="' . e(money_to_cents($payment['amount'])) . '"' : '' ?>><?= e($payment['amount']) !== '' && preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $payment['amount']) ? money((float) $payment['amount'], $formCurrencyCode) : e($payment['amount']) ?></strong>
        <span class="invoice-payment-record__state">Not saved yet</span>
        <button class="link link--danger invoice-payment-remove" type="button" data-remove-payment>Remove</button>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php else: ?>
  <section class="invoice-payment-records" aria-label="Payment records" hidden>
    <h3>Payment records</h3><ul id="invoice-payment-record-list"></ul>
  </section>
  <?php endif; ?>
  <label class="check"><input class="check__input" type="checkbox" name="issue_now" value="1" data-invoice-issue-now<?= (($_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['issue_now']) : !$editingInvoice) ? ' checked' : '') ?>>Issue this invoice now (uncheck to save as a draft)</label>
  <div class="form-actions"><button class="btn btn--primary btn--danger" type="submit" data-submitting-label="Saving...">Save invoice</button><a class="btn btn--quiet btn--accent" href="<?= e(app_url('/dashboard/invoices.php')) ?>">Cancel</a></div>
</form>
<dialog class="invoice-payment-dialog" id="payment-entry-dialog" aria-labelledby="payment-dialog-title" data-reopen="<?= $reopenPaymentDialog ? 'true' : 'false' ?>" data-server-error="<?= e($paymentValidationError) ?>" data-server-error-field="<?= e($paymentValidationField) ?>">
  <div class="invoice-payment-dialog__panel">
    <h2 id="payment-dialog-title">Add payment record</h2>
    <p class="field__error" id="payment-form-error" role="alert" aria-live="polite" hidden></p>
    <form id="payment-entry-form" method="dialog" novalidate data-validation-managed>
      <label class="field" for="payment-entry-amount">
        <span class="field__label">Amount *</span>
        <span class="invoice-payment-amount"><input class="input" id="payment-entry-amount" type="text" inputmode="decimal" autocomplete="off" aria-required="true" aria-describedby="payment-entry-error"><span id="payment-entry-currency"><?= e($formCurrencyCode) ?></span></span>
        <span class="field__error" id="payment-entry-error" role="alert" aria-live="polite" hidden></span>
      </label>
      <label class="field"><span class="field__label">Payment date *</span><input class="input" id="payment-entry-date" type="date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required aria-describedby="payment-date-error"><span class="field__error" id="payment-date-error" role="alert" hidden></span></label>
      <label class="field"><span class="field__label">Payment method *</span><select class="input" id="payment-entry-method" required aria-describedby="payment-method-error"><option value="">Select a method</option><?php foreach (PAYMENT_METHODS as $method): ?><option value="<?= e($method) ?>"><?= e($method) ?></option><?php endforeach; ?></select><span class="field__error" id="payment-method-error" role="alert" hidden></span></label>
      <label class="field"><span class="field__label">Transaction code *</span><input class="input" id="payment-entry-reference" maxlength="150" required aria-describedby="payment-reference-error"><span class="field__error" id="payment-reference-error" role="alert" hidden></span></label>
      <div class="invoice-payment-dialog__actions">
        <button class="btn btn--quiet btn--accent" type="button" id="cancel-payment-dialog">Cancel</button>
        <button class="btn btn--primary" type="submit" id="confirm-payment-add" disabled>Add payment</button>
      </div>
    </form>
  </div>
</dialog>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
