<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/customer_domains.php';
require_auth();
$pdo = db();
$error = '';
$errorSummary = '';
$customerSubmissionToken = bin2hex(random_bytes(32));
if (!is_array($_SESSION['customer_form_tokens'] ?? null)) {
    $_SESSION['customer_form_tokens'] = [];
}
$_SESSION['customer_form_tokens'][$customerSubmissionToken] = true;
if (count($_SESSION['customer_form_tokens']) > 20) {
    $_SESSION['customer_form_tokens'] = array_slice($_SESSION['customer_form_tokens'], -20, null, true);
}
$domainFieldErrors = [];
$submittedDomains = [];
$workspaceFieldErrors = [];
$submittedWorkspaces = [];
$customerFieldErrors = [];
$primaryDomainFieldErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'save';
        if ($action === 'delete') {
            $statement = $pdo->prepare("UPDATE customers SET status = 'archived' WHERE id = ?");
            $statement->execute([filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0]);
            flash('Customer deleted.');
            redirect('/dashboard/clients.php');
        }
        $postedSubmissionToken = is_string($_POST['submission_token'] ?? null) ? $_POST['submission_token'] : '';
        if ($postedSubmissionToken === '' || !isset($_SESSION['customer_form_tokens'][$postedSubmissionToken])) {
            throw new InvalidArgumentException('This customer form has already been submitted. Reload the page before trying again.');
        }
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $name = post_string('name', 190, true);
        $contact = post_string('contact_person', 150);
        $email = post_string('email', 190);
        $phone = post_string('phone', 50);
        $notes = post_string('notes', 4000);
        if ($contact === '') {
            $customerFieldErrors['contact_person'] = 'Enter the customer contact person.';
        }
        if ($email === '') {
            $customerFieldErrors['email'] = 'Enter the customer email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $customerFieldErrors['email'] = 'Enter a valid email address, for example name@company.com.';
        }
        $normalizedPhone = '';
        if ($phone === '') {
            $customerFieldErrors['phone'] = 'Enter the customer phone number.';
        } else {
            try {
                $normalizedPhone = normalize_customer_phone($phone);
            } catch (InvalidArgumentException $exception) {
                $customerFieldErrors['phone'] = $exception->getMessage();
            }
        }
        $invoiceDelivery = $_POST['invoice_delivery'] ?? null;
        if (!is_string($invoiceDelivery)) {
            throw new InvalidArgumentException('Choose a valid invoice delivery option.');
        }
        if (!in_array($invoiceDelivery, ['primary', 'domain'], true)) {
            throw new InvalidArgumentException('Choose a valid invoice delivery option.');
        }
        $primaryDomain = null;
        $primaryDomainExpiry = null;
        if ($invoiceDelivery === 'primary') {
            $rawPrimaryDomain = is_string($_POST['primary_domain'] ?? null) ? trim($_POST['primary_domain']) : '';
            $rawPrimaryDomainExpiry = is_string($_POST['primary_domain_expiry'] ?? null) ? trim($_POST['primary_domain_expiry']) : '';
            if ($rawPrimaryDomain === '') {
                $primaryDomainFieldErrors['primary_domain'] = 'Enter the domain';
            } else {
                try {
                    $primaryDomain = normalize_primary_customer_domain($rawPrimaryDomain);
                } catch (InvalidArgumentException) {
                    $primaryDomainFieldErrors['primary_domain'] = 'Enter a valid domain, e.g. example.co.ke';
                }
            }
            if ($rawPrimaryDomainExpiry === '') {
                $primaryDomainFieldErrors['primary_domain_expiry'] = 'Select the expiry date';
            } else {
                $expiryDate = DateTimeImmutable::createFromFormat('!Y-m-d', $rawPrimaryDomainExpiry);
                $dateErrors = DateTimeImmutable::getLastErrors();
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawPrimaryDomainExpiry)
                    || !$expiryDate
                    || $expiryDate->format('Y-m-d') !== $rawPrimaryDomainExpiry
                    || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                    $primaryDomainFieldErrors['primary_domain_expiry'] = 'Enter a valid date';
                } else {
                    $primaryDomainExpiry = $rawPrimaryDomainExpiry;
                }
            }
            if ($primaryDomainFieldErrors !== []) {
                throw new InvalidArgumentException('Fix the primary domain fields marked below.');
            }
        }
        if ($customerFieldErrors !== []) {
            throw new InvalidArgumentException('Complete the required contact details and correct the highlighted fields.');
        }
        $address = $invoiceDelivery === 'primary' ? post_string('billing_address', 4000) : '';
        $rawDomains = $_POST['domains'] ?? [];
        if (!is_array($rawDomains) || count($rawDomains) > 500) {
            throw new InvalidArgumentException('The domain list has an invalid format.');
        }
        if ($invoiceDelivery === 'primary') {
            $rawDomains = [];
        }
        $namesSeen = [];
        $emailsSeen = [];
        foreach ($rawDomains as $key => $rawDomain) {
            if (!is_array($rawDomain)) {
                throw new InvalidArgumentException('A domain row has an invalid format.');
            }
            $rawNameValue = $rawDomain['domain'] ?? ($rawDomain['domain_name'] ?? null);
            $rawName = is_string($rawNameValue) ? trim($rawNameValue) : '';
            $rawCompanyName = is_string($rawDomain['company_name'] ?? null) ? trim($rawDomain['company_name']) : '';
            $rawPhoneNumber = is_string($rawDomain['phone_number'] ?? null) ? trim($rawDomain['phone_number']) : '';
            $rawBillingAddress = is_string($rawDomain['billing_address'] ?? null) ? trim($rawDomain['billing_address']) : '';
            $rawExpiry = is_string($rawDomain['expiry_date'] ?? null) ? trim($rawDomain['expiry_date']) : '';
            $rawEmail = is_string($rawDomain['invoice_email'] ?? null) ? trim($rawDomain['invoice_email']) : '';
            $rawId = $rawDomain['id'] ?? '';
            $domainId = $rawId === '' ? null : filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($rawId !== '' && $domainId === false) {
                throw new InvalidArgumentException('A domain row has an invalid identifier.');
            }
            if ($rawName === '') {
                $domainName = '';
                $domainFieldErrors[$key]['domain_name'] = 'Domain is required.';
            } elseif (preg_match('~[/:?#\\s]~', $rawName)) {
                $domainName = '';
                $domainFieldErrors[$key]['domain_name'] = 'Enter a domain only, without a protocol or path.';
            } else {
                try {
                    $domainName = normalize_customer_domain($rawName);
                } catch (InvalidArgumentException $exception) {
                    $domainName = '';
                    $domainFieldErrors[$key]['domain_name'] = 'Enter a valid domain, for example example.co.ke.';
                }
            }
            if ($domainName !== '' && isset($namesSeen[$domainName])) {
                $domainFieldErrors[$key]['domain_name'] = 'This domain is already added for this customer.';
            }
            if ($domainName !== '') {
                $namesSeen[$domainName] = true;
            }
            if ($invoiceDelivery === 'domain' && $rawEmail === '') {
                $domainFieldErrors[$key]['invoice_email'] = 'Invoice email is required.';
            } elseif ($invoiceDelivery === 'domain' && !filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
                $domainFieldErrors[$key]['invoice_email'] = 'Enter a valid email address.';
            }
            if ($invoiceDelivery === 'domain') {
                $companyLength = mb_strlen($rawCompanyName);
                if ($companyLength < 2 || $companyLength > 150) {
                    $domainFieldErrors[$key]['company_name'] = 'Company name must be between 2 and 150 characters.';
                }
                if ($rawPhoneNumber === '') {
                    $domainFieldErrors[$key]['phone_number'] = 'Enter the phone number';
                } else {
                    try {
                        $rawPhoneNumber = validate_customer_domain_phone($rawPhoneNumber);
                    } catch (InvalidArgumentException) {
                        $domainFieldErrors[$key]['phone_number'] = 'Enter a valid phone number';
                    }
                }
                $billingLength = mb_strlen($rawBillingAddress);
                if ($billingLength < 5 || $billingLength > 500) {
                    $domainFieldErrors[$key]['billing_address'] = 'Billing address must be between 5 and 500 characters.';
                }
            }
            $normalizedEmail = mb_strtolower($rawEmail);
            if ($invoiceDelivery === 'domain' && $normalizedEmail !== '' && isset($emailsSeen[$normalizedEmail])) {
                $domainFieldErrors[$key]['invoice_email'] = 'This invoice email is repeated in another domain row. Use a unique email address for each domain.';
            }
            if ($invoiceDelivery === 'domain' && $normalizedEmail !== '') {
                $emailsSeen[$normalizedEmail] = true;
            }
            if ($rawExpiry === '' && ($domainId === null || $invoiceDelivery === 'domain')) {
                $domainFieldErrors[$key]['expiry_date'] = 'Expiry date is required.';
            } elseif ($rawExpiry !== '') {
                $expiryDate = DateTimeImmutable::createFromFormat('!Y-m-d', $rawExpiry);
                $dateErrors = DateTimeImmutable::getLastErrors();
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawExpiry)
                    || !$expiryDate
                    || $expiryDate->format('Y-m-d') !== $rawExpiry
                    || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                    $domainFieldErrors[$key]['expiry_date'] = 'Enter a valid expiry date.';
                }
            }
            $submittedDomains[$key] = [
                'id' => $domainId === false ? '' : $domainId,
                'domain_name' => $domainName !== '' ? $domainName : $rawName,
                'company_name' => $rawCompanyName,
                'phone_number' => $rawPhoneNumber,
                'expiry_date' => $rawExpiry !== '' ? $rawExpiry : null,
                'invoice_email' => $rawEmail,
                'billing_address' => $rawBillingAddress,
            ];
        }
        if ($domainFieldErrors !== []) {
            throw new InvalidArgumentException('Fix the domain fields marked below.');
        }

        $rawWorkspaces = $_POST['google_workspaces'] ?? [];
        if (!is_array($rawWorkspaces) || count($rawWorkspaces) > 500) {
            throw new InvalidArgumentException('The Google Workspace list has an invalid format.');
        }
        $workspaceNamesSeen = [];
        foreach ($rawWorkspaces as $key => $rawWorkspace) {
            if (!is_array($rawWorkspace)) {
                throw new InvalidArgumentException('A Google Workspace row has an invalid format.');
            }
            $rawName = is_string($rawWorkspace['domain_name'] ?? null) ? trim($rawWorkspace['domain_name']) : '';
            $rawExpiry = is_string($rawWorkspace['expiry_date'] ?? null) ? trim($rawWorkspace['expiry_date']) : '';
            $rawId = $rawWorkspace['id'] ?? '';
            $workspaceId = $rawId === '' ? null : filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($rawId !== '' && $workspaceId === false) {
                throw new InvalidArgumentException('A Google Workspace row has an invalid identifier.');
            }
            if ($rawName === '') {
                $workspaceDomain = '';
                $workspaceFieldErrors[$key]['domain_name'] = 'Domain is required.';
            } else {
                try {
                    $workspaceDomain = normalize_customer_domain($rawName);
                } catch (InvalidArgumentException) {
                    $workspaceDomain = '';
                    $workspaceFieldErrors[$key]['domain_name'] = 'Enter a valid domain, for example example.co.ke.';
                }
            }
            if ($workspaceDomain !== '' && isset($workspaceNamesSeen[$workspaceDomain])) {
                $workspaceFieldErrors[$key]['domain_name'] = 'This domain is already added under Google Workspace.';
            }
            if ($workspaceDomain !== '') {
                $workspaceNamesSeen[$workspaceDomain] = true;
            }
            if ($rawExpiry === '') {
                $workspaceFieldErrors[$key]['expiry_date'] = 'Expiry date is required.';
            } else {
                $expiryDate = DateTimeImmutable::createFromFormat('!Y-m-d', $rawExpiry);
                $dateErrors = DateTimeImmutable::getLastErrors();
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawExpiry)
                    || !$expiryDate
                    || $expiryDate->format('Y-m-d') !== $rawExpiry
                    || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                    $workspaceFieldErrors[$key]['expiry_date'] = 'Enter a valid expiry date.';
                }
            }
            $submittedWorkspaces[$key] = [
                'id' => $workspaceId === false ? '' : $workspaceId,
                'domain_name' => $workspaceDomain !== '' ? $workspaceDomain : $rawName,
                'expiry_date' => $rawExpiry,
            ];
        }
        if ($workspaceFieldErrors !== []) {
            throw new InvalidArgumentException('Fix the Google Workspace fields marked below.');
        }

        $restoreIds = $_POST['restore_domains'] ?? [];
        if (!is_array($restoreIds) || count($restoreIds) > 500) {
            throw new InvalidArgumentException('The archived domain selections have an invalid format.');
        }
        $restoreIds = array_values(array_unique(array_map(
            static function (mixed $rawId): int {
                $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw new InvalidArgumentException('An archived domain selection is invalid.');
                }
                return (int) $id;
            },
            $restoreIds
        )));

        $pdo->beginTransaction();
        $contactWriteLock = $pdo->query('SELECT lock_id FROM customer_contact_write_lock WHERE lock_id=1 FOR UPDATE');
        if (!$contactWriteLock->fetchColumn()) {
            throw new RuntimeException('Customer contact validation could not be synchronized. Run the customer-domain migration and try again.');
        }
        $emailDuplicateQuery = $pdo->prepare('SELECT name FROM customers WHERE LOWER(TRIM(email))=LOWER(TRIM(?)) AND id<>? LIMIT 1');
        $emailDuplicateQuery->execute([$email, $id]);
        $duplicateEmailCustomer = $emailDuplicateQuery->fetchColumn();
        if (is_string($duplicateEmailCustomer)) {
            $customerFieldErrors['email'] = 'This email address is already assigned to ' . $duplicateEmailCustomer . '. Enter a different email address.';
        }
        $existingCustomerQuery = $pdo->prepare('SELECT phone FROM customers WHERE id=?');
        $existingCustomerQuery->execute([$id]);
        $existingPhone = $id > 0 ? $existingCustomerQuery->fetchColumn() : false;
        $unchangedLegacyPhone = false;
        if (is_string($existingPhone) && $existingPhone !== '') {
            try {
                $unchangedLegacyPhone = normalize_customer_phone($existingPhone) === $normalizedPhone;
            } catch (InvalidArgumentException) {
                $unchangedLegacyPhone = false;
            }
        }
        $phoneCustomers = $pdo->query("SELECT id,name,phone FROM customers WHERE phone IS NOT NULL AND TRIM(phone)<>''")->fetchAll();
        $duplicatePhoneNames = [];
        foreach ($phoneCustomers as $phoneCustomer) {
            if ((int) $phoneCustomer['id'] === $id) {
                continue;
            }
            try {
                if (normalize_customer_phone((string) $phoneCustomer['phone']) === $normalizedPhone) {
                    $duplicatePhoneNames[] = (string) $phoneCustomer['name'];
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        if ($duplicatePhoneNames !== [] && !$unchangedLegacyPhone) {
            $customerFieldErrors['phone'] = 'This phone number is already assigned to ' . implode(', ', array_unique($duplicatePhoneNames)) . '. Enter a different phone number.';
        }
        foreach ($invoiceDelivery === 'domain' ? $submittedDomains : [] as $key => $row) {
            $domainOwnerQuery = $pdo->prepare('SELECT name FROM customers WHERE primary_domain=? AND id<>?
                UNION ALL
                SELECT c.name FROM customer_domains d JOIN customers c ON c.id=d.customer_id WHERE d.domain_name=? AND d.customer_id<>?
                LIMIT 1');
            $domainOwnerQuery->execute([$row['domain_name'], $id, $row['domain_name'], $id]);
            $domainOwner = $domainOwnerQuery->fetchColumn();
            if (is_string($domainOwner)) {
                $domainFieldErrors[$key]['domain_name'] = 'This domain is already assigned to ' . $domainOwner . '. Each domain can belong to only one customer.';
            }
            if ($invoiceDelivery === 'domain' && $row['invoice_email'] !== '') {
                $emailOwnerQuery = $pdo->prepare('SELECT c.name,d.domain_name FROM customer_domains d JOIN customers c ON c.id=d.customer_id WHERE LOWER(TRIM(d.invoice_email))=LOWER(TRIM(?)) AND d.id<>? LIMIT 1');
                $emailOwnerQuery->execute([$row['invoice_email'], $row['id'] === '' ? 0 : (int) $row['id']]);
                $emailOwner = $emailOwnerQuery->fetch();
                if ($emailOwner) {
                    $domainFieldErrors[$key]['invoice_email'] = 'This invoice email is already assigned to ' . $emailOwner['domain_name'] . ' for ' . $emailOwner['name'] . '. Use a unique email address for this domain.';
                }
            }
        }
        foreach ($submittedWorkspaces as $key => $row) {
            $workspaceOwnerQuery = $pdo->prepare('SELECT c.name FROM customer_google_workspaces w JOIN customers c ON c.id=w.customer_id WHERE w.domain_name=? AND w.id<>? LIMIT 1');
            $workspaceOwnerQuery->execute([$row['domain_name'], $row['id'] === '' ? 0 : (int) $row['id']]);
            $workspaceOwner = $workspaceOwnerQuery->fetchColumn();
            if (is_string($workspaceOwner)) {
                $workspaceFieldErrors[$key]['domain_name'] = 'This domain is already assigned to ' . $workspaceOwner . ' under Google Workspace.';
            }
        }
        if ($invoiceDelivery === 'primary') {
            $primaryDomainOwnerQuery = $pdo->prepare('SELECT name FROM customers WHERE primary_domain=? AND id<>?
                UNION ALL
                SELECT c.name FROM customer_domains d JOIN customers c ON c.id=d.customer_id WHERE d.domain_name=? AND d.customer_id<>?
                LIMIT 1');
            $primaryDomainOwnerQuery->execute([$primaryDomain, $id, $primaryDomain, $id]);
            $primaryDomainOwner = $primaryDomainOwnerQuery->fetchColumn();
            if (is_string($primaryDomainOwner)) {
                $primaryDomainFieldErrors['primary_domain'] = 'This domain is already assigned to ' . $primaryDomainOwner . '. Each domain can belong to only one customer.';
            }
        }
        if ($customerFieldErrors !== [] || $primaryDomainFieldErrors !== [] || $domainFieldErrors !== [] || $workspaceFieldErrors !== []) {
            throw new InvalidArgumentException('Resolve the highlighted customer contact, domain, or Google Workspace conflicts before saving.');
        }
        if ($id > 0) {
            $lock = $pdo->prepare('SELECT id,invoice_delivery,primary_domain,primary_domain_expiry FROM customers WHERE id=? FOR UPDATE');
            $lock->execute([$id]);
            $lockedCustomer = $lock->fetch();
            if (!$lockedCustomer) {
                throw new InvalidArgumentException('Customer not found.');
            }
            $savedDomainsQuery = $pdo->prepare('SELECT id FROM customer_domains WHERE customer_id=? FOR UPDATE');
            $savedDomainsQuery->execute([$id]);
            $savedDomainRows = count($savedDomainsQuery->fetchAll(PDO::FETCH_COLUMN));
            $savedMode = (string) ($lockedCustomer['invoice_delivery'] ?? '');
            $savedPrimaryExists = trim((string) ($lockedCustomer['primary_domain'] ?? '')) !== ''
                || ($lockedCustomer['primary_domain_expiry'] ?? null) !== null;
            $destructiveModeChange = ($savedMode === 'domain' && $invoiceDelivery === 'primary' && $savedDomainRows > 0)
                || ($savedMode === 'primary' && $invoiceDelivery === 'domain' && $savedPrimaryExists);
            if ($destructiveModeChange && ($_POST['mode_change_confirmed'] ?? '') !== '1') {
                throw new InvalidArgumentException('Confirm the invoice email mode change before deleting the saved domain details.');
            }
            if ($invoiceDelivery === 'domain') {
                $statement = $pdo->prepare('UPDATE customers SET name=?,contact_person=?,email=?,invoice_delivery=?,primary_domain=NULL,primary_domain_expiry=NULL,phone=?,notes=? WHERE id=?');
                $statement->execute([$name, $contact, $email, $invoiceDelivery, $phone, $notes ?: null, $id]);
            } else {
                $statement = $pdo->prepare('UPDATE customers SET name=?,contact_person=?,email=?,invoice_delivery=?,primary_domain=?,primary_domain_expiry=?,phone=?,billing_address=?,notes=? WHERE id=?');
                $statement->execute([$name, $contact, $email, $invoiceDelivery, $primaryDomain, $primaryDomainExpiry, $phone, $address ?: null, $notes ?: null, $id]);
            }
            $customerId = $id;
        } else {
            $statement = $pdo->prepare('INSERT INTO customers (name,contact_person,email,invoice_delivery,primary_domain,primary_domain_expiry,phone,billing_address,notes) VALUES (?,?,?,?,?,?,?,?,?)');
            $statement->execute([$name, $contact, $email, $invoiceDelivery, $invoiceDelivery === 'primary' ? $primaryDomain : null, $invoiceDelivery === 'primary' ? $primaryDomainExpiry : null, $phone, $address ?: null, $notes ?: null]);
            $customerId = (int) $pdo->lastInsertId();
        }

        if ($invoiceDelivery === 'primary') {
            $preserveInvoiceSnapshots = $pdo->prepare("UPDATE invoices i JOIN customer_domains d ON d.id=i.domain_id
                SET i.domain_name=COALESCE(NULLIF(TRIM(i.domain_name),''),d.domain_name),
                    i.recipient_email=COALESCE(NULLIF(TRIM(i.recipient_email),''),d.invoice_email),
                    i.company_name_snapshot=COALESCE(NULLIF(TRIM(i.company_name_snapshot),''),d.company_name),
                    i.billing_address_snapshot=COALESCE(NULLIF(TRIM(i.billing_address_snapshot),''),d.billing_address),
                    i.phone_number_snapshot=COALESCE(NULLIF(TRIM(i.phone_number_snapshot),''),d.phone_number)
                WHERE d.customer_id=?");
            $preserveInvoiceSnapshots->execute([$customerId]);
            $deleteCustomerDomains = $pdo->prepare('DELETE FROM customer_domains WHERE customer_id=?');
            $deleteCustomerDomains->execute([$customerId]);
        } else {
        $activeDomainsQuery = $pdo->prepare("SELECT id,domain_name FROM customer_domains WHERE customer_id=? AND status='active' FOR UPDATE");
        $activeDomainsQuery->execute([$customerId]);
        $existingActiveDomains = $activeDomainsQuery->fetchAll();
        $postedIds = [];
        $saveDomain = $pdo->prepare('UPDATE customer_domains SET company_name=?,phone_number=?,domain_name=?,expiry_date=?,invoice_email=?,billing_address=? WHERE id=? AND customer_id=?');
        $insertDomain = $pdo->prepare("INSERT INTO customer_domains (customer_id,company_name,phone_number,domain_name,expiry_date,invoice_email,billing_address,status) VALUES (?,?,?,?,?,?,?, 'active')");
        $findDomain = $pdo->prepare('SELECT id,status FROM customer_domains WHERE customer_id=? AND domain_name=?');
        foreach ($submittedDomains as $row) {
            $rowId = $row['id'] === '' || $row['id'] === null ? null : (int) $row['id'];
            if ($rowId !== null) {
                $ownerCheck = $pdo->prepare("SELECT id FROM customer_domains WHERE id=? AND customer_id=? AND status='active'");
                $ownerCheck->execute([$rowId, $customerId]);
                if (!$ownerCheck->fetchColumn()) {
                    throw new InvalidArgumentException('A domain no longer belongs to this customer. Reload the page and try again.');
                }
                $saveDomain->execute([$row['company_name'], $row['phone_number'], $row['domain_name'], $row['expiry_date'], $row['invoice_email'], $row['billing_address'], $rowId, $customerId]);
                $postedIds[] = $rowId;
            } else {
                $findDomain->execute([$customerId, $row['domain_name']]);
                $existingDomain = $findDomain->fetch();
                if ($existingDomain) {
                    if ($existingDomain['status'] === 'archived') {
                        throw new InvalidArgumentException('This domain is archived. Restore it from the archived domains list instead.');
                    }
                    $rowId = (int) $existingDomain['id'];
                    $saveDomain->execute([$row['company_name'], $row['phone_number'], $row['domain_name'], $row['expiry_date'], $row['invoice_email'], $row['billing_address'], $rowId, $customerId]);
                    $postedIds[] = $rowId;
                } else {
                    $insertDomain->execute([$customerId, $row['company_name'], $row['phone_number'], $row['domain_name'], $row['expiry_date'], $row['invoice_email'], $row['billing_address']]);
                    $rowId = (int) $pdo->lastInsertId();
                    $postedIds[] = $rowId;
                }
            }
        }
        $deleteDomain = $pdo->prepare("DELETE FROM customer_domains WHERE id=? AND customer_id=? AND status='active'");
        $archiveDomain = $pdo->prepare("UPDATE customer_domains SET status='archived' WHERE id=? AND customer_id=? AND status='active'");
        $invoiceUsage = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE domain_id=?');
        foreach ($existingActiveDomains as $existingDomain) {
            $existingId = (int) $existingDomain['id'];
            if (in_array($existingId, $postedIds, true)) {
                continue;
            }
            $invoiceUsage->execute([$existingId]);
            if ((int) $invoiceUsage->fetchColumn() > 0) {
                $archiveDomain->execute([$existingId, $customerId]);
            } else {
                $deleteDomain->execute([$existingId, $customerId]);
            }
        }
        }
        $existingWorkspacesQuery = $pdo->prepare("SELECT id FROM customer_google_workspaces WHERE customer_id=? AND status='active' FOR UPDATE");
        $existingWorkspacesQuery->execute([$customerId]);
        $existingWorkspaces = $existingWorkspacesQuery->fetchAll(PDO::FETCH_COLUMN);
        $saveWorkspace = $pdo->prepare('UPDATE customer_google_workspaces SET domain_name=?,expiry_date=? WHERE id=? AND customer_id=?');
        $insertWorkspace = $pdo->prepare("INSERT INTO customer_google_workspaces (customer_id,domain_name,expiry_date,status) VALUES (?,?,?,'active')");
        $findWorkspace = $pdo->prepare('SELECT id,status FROM customer_google_workspaces WHERE customer_id=? AND domain_name=?');
        $postedWorkspaceIds = [];
        foreach ($submittedWorkspaces as $row) {
            $rowId = $row['id'] === '' || $row['id'] === null ? null : (int) $row['id'];
            if ($rowId !== null) {
                $ownerCheck = $pdo->prepare("SELECT id FROM customer_google_workspaces WHERE id=? AND customer_id=? AND status='active'");
                $ownerCheck->execute([$rowId, $customerId]);
                if (!$ownerCheck->fetchColumn()) {
                    throw new InvalidArgumentException('A Google Workspace row no longer belongs to this customer. Reload the page and try again.');
                }
                $saveWorkspace->execute([$row['domain_name'], $row['expiry_date'], $rowId, $customerId]);
                $postedWorkspaceIds[] = $rowId;
            } else {
                $findWorkspace->execute([$customerId, $row['domain_name']]);
                $existingWorkspace = $findWorkspace->fetch();
                if ($existingWorkspace) {
                    $rowId = (int) $existingWorkspace['id'];
                    $saveWorkspace->execute([$row['domain_name'], $row['expiry_date'], $rowId, $customerId]);
                } else {
                    $insertWorkspace->execute([$customerId, $row['domain_name'], $row['expiry_date']]);
                    $rowId = (int) $pdo->lastInsertId();
                }
                $postedWorkspaceIds[] = $rowId;
            }
        }
        $deleteWorkspace = $pdo->prepare("DELETE FROM customer_google_workspaces WHERE id=? AND customer_id=? AND status='active'");
        foreach ($existingWorkspaces as $existingWorkspaceId) {
            $existingWorkspaceId = (int) $existingWorkspaceId;
            if (!in_array($existingWorkspaceId, $postedWorkspaceIds, true)) {
                $deleteWorkspace->execute([$existingWorkspaceId, $customerId]);
            }
        }
        if ($invoiceDelivery === 'domain') {
        $restoreDomain = $pdo->prepare("UPDATE customer_domains SET status='active' WHERE id=? AND customer_id=? AND status='archived'");
        foreach ($restoreIds as $restoreId) {
            $restoreDomain->execute([$restoreId, $customerId]);
            if ($restoreDomain->rowCount() !== 1) {
                throw new InvalidArgumentException('An archived domain could not be restored. Reload the page and try again.');
            }
        }

        $finalDomainsQuery = $pdo->prepare("SELECT company_name,phone_number,domain_name,expiry_date,invoice_email,billing_address FROM customer_domains WHERE customer_id=? AND status='active'");
        $finalDomainsQuery->execute([$customerId]);
        $finalDomains = $finalDomainsQuery->fetchAll();
        if ($invoiceDelivery === 'domain' && count($finalDomains) === 0) {
            throw new InvalidArgumentException('Add at least one active domain when invoices are sent to domain email.');
        }
        foreach ($finalDomains as $activeDomain) {
            if ($invoiceDelivery === 'domain' && (
                mb_strlen(trim((string) ($activeDomain['company_name'] ?? ''))) < 2
                || mb_strlen(trim((string) ($activeDomain['company_name'] ?? ''))) > 150
                || trim((string) ($activeDomain['billing_address'] ?? '')) === ''
                || mb_strlen(trim((string) ($activeDomain['billing_address'] ?? ''))) < 5
                || mb_strlen(trim((string) ($activeDomain['billing_address'] ?? ''))) > 500
                || trim((string) ($activeDomain['invoice_email'] ?? '')) === ''
                || !filter_var($activeDomain['invoice_email'], FILTER_VALIDATE_EMAIL)
                || empty($activeDomain['expiry_date']))) {
                throw new InvalidArgumentException('Every active domain needs a valid company name, domain, expiry date, invoice email, and billing address when invoices are sent to domain email.');
            }
        }
        }
        unset($_SESSION['customer_form_tokens'][$postedSubmissionToken]);
        $pdo->commit();
        $notice = $id > 0 ? 'Customer updated successfully.' : 'Customer added successfully.';
        flash($notice);
        redirect('/dashboard/clients.php?edit=' . $customerId);
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
        $errorSummary = 'Please fix the highlighted fields and try again.';
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Customer save failed: ' . $exception->getMessage());
        if (str_contains($exception->getMessage(), 'uq_customer_email')) {
            $customerFieldErrors['email'] = 'This email address is already assigned to another customer. Enter a different email address.';
        }
        $errorSummary = 'Could not save the customer. Please try again.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Customer save failed: ' . $exception->getMessage());
        $errorSummary = 'Could not save the customer. Please try again.';
    }
}

$editing = null;
$activeDomains = [];
$archivedDomains = [];
$activeWorkspaces = [];
$customerInvoices = [];
$customerEmailLogs = [];
$savedDomainCount = 0;
$savedPrimaryDomainExists = false;
$editCustomerId = filter_var($_GET['edit'] ?? ($_POST['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($editCustomerId !== false) {
    $statement = $pdo->prepare('SELECT id,name,contact_person,email,invoice_delivery,primary_domain,primary_domain_expiry,phone,billing_address,notes FROM customers WHERE id = ?');
    $statement->execute([$editCustomerId]);
    $editing = $statement->fetch() ?: null;
    if ($editing) {
        $savedDomainCountQuery = $pdo->prepare('SELECT COUNT(*) FROM customer_domains WHERE customer_id=?');
        $savedDomainCountQuery->execute([$editing['id']]);
        $savedDomainCount = (int) $savedDomainCountQuery->fetchColumn();
        $savedPrimaryDomainExists = trim((string) ($editing['primary_domain'] ?? '')) !== ''
            || ($editing['primary_domain_expiry'] ?? null) !== null;
        $domainsQuery = $pdo->prepare('SELECT id,company_name,phone_number,domain_name,expiry_date,invoice_email,billing_address,status FROM customer_domains WHERE customer_id=? ORDER BY domain_name');
        $domainsQuery->execute([$editing['id']]);
        foreach ($domainsQuery->fetchAll() as $domain) {
            if ($domain['status'] === 'active') {
                $activeDomains[] = $domain;
            } else {
                $archivedDomains[] = $domain;
            }
        }
        $workspacesQuery = $pdo->prepare("SELECT id,domain_name,expiry_date,status FROM customer_google_workspaces WHERE customer_id=? AND status='active' ORDER BY domain_name");
        $workspacesQuery->execute([$editing['id']]);
        $activeWorkspaces = $workspacesQuery->fetchAll();
        $invoicesQuery = $pdo->prepare("SELECT i.id,i.invoice_number,i.status,i.issue_date,i.due_date,i.total,i.currency_code,
                COALESCE(p.paid_amount,0) paid_amount
            FROM invoices i
            LEFT JOIN (SELECT invoice_id,SUM(amount) paid_amount FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id
            WHERE i.customer_id=?
            ORDER BY i.created_at DESC,i.id DESC");
        $invoicesQuery->execute([$editing['id']]);
        $customerInvoices = $invoicesQuery->fetchAll();
        $emailLogsQuery = $pdo->prepare("SELECT el.id,el.type,el.recipient,el.status,el.error,
                el.created_at,el.text_body,i.id invoice_id,i.invoice_number
            FROM email_log el
            JOIN invoices i ON i.id=el.invoice_id
            WHERE i.customer_id=?
            ORDER BY el.created_at DESC,el.id DESC
            LIMIT 250");
        $emailLogsQuery->execute([$editing['id']]);
        $customerEmailLogs = $emailLogsQuery->fetchAll();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && array_key_exists('edit', $_GET) && !$editing) {
    flash('Customer not found.', 'error');
    redirect('/dashboard/clients.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['domains']) && is_array($_POST['domains'])) {
    $activeDomains = $_POST['domains'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['google_workspaces']) && is_array($_POST['google_workspaces'])) {
    $activeWorkspaces = $_POST['google_workspaces'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $editing) {
    $editing['invoice_delivery'] = $_POST['invoice_delivery'] ?? $editing['invoice_delivery'];
}
$showCustomerForm = $editing !== null
    || isset($_GET['new'])
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submission_token']));
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$customers = [];
if (!$showCustomerForm) {
    $statement = $pdo->prepare("SELECT c.id,c.name,c.contact_person,c.email,c.phone,
        CASE WHEN c.invoice_delivery='primary' THEN 1
             WHEN c.invoice_delivery='domain' THEN COALESCE(domain_counts.domain_count,0)
             ELSE 0 END AS domain_count
        FROM customers c
        LEFT JOIN (
             SELECT customer_id,COUNT(*) AS domain_count
             FROM customer_domains WHERE status='active'
             GROUP BY customer_id
        ) domain_counts ON domain_counts.customer_id=c.id
        WHERE c.status = ? AND (c.name LIKE ? OR c.email LIKE ? OR c.contact_person LIKE ?)
        ORDER BY c.name LIMIT 200");
    $term = '%' . $search . '%';
    $statement->execute(['active', $term, $term, $term]);
    $customers = $statement->fetchAll();
}
$pageTitle = 'Customers';
$activePage = 'customers';
$pageSubtitle = 'Manage your client records';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-head"><div><h1 class="display-lg">Customers</h1><p class="page-head__sub">Keep billing contacts and business details up to date.</p></div></div>
<?php if ($showCustomerForm): ?><div class="notice notice--error" role="alert" data-customer-error-summary<?= $error === '' && $errorSummary === '' ? ' hidden' : '' ?>><?= e($errorSummary !== '' ? $errorSummary : $error) ?><?php if ($errorSummary !== '' && $error !== '' && $error !== $errorSummary): ?> <?= e($error) ?><?php endif; ?></div><?php elseif ($error !== '' || $errorSummary !== ''): ?><div class="notice notice--error" role="alert"><?= e($errorSummary !== '' ? $errorSummary : $error) ?></div><?php endif; ?>
<div class="customer-page">
  <?php if ($showCustomerForm): ?>
  <section class="card card__pad customer-editor">
    <div class="customer-editor__heading">
      <div><span class="eyebrow"><?= $editing ? 'CUSTOMER DETAILS' : 'NEW CUSTOMER' ?></span><h2 class="heading-lg"><?= $editing ? 'Edit customer' : 'Add a customer' ?></h2><p class="card__sub">Enter billing and contact information. Required fields are marked with an asterisk.</p></div>
      <?php if ($editing): ?><a class="btn btn--quiet btn--accent" href="<?= e(app_url('/dashboard/clients.php')) ?>">Cancel edit</a><?php endif; ?>
    </div>
    <form class="data-form customer-fields" method="post" data-customer-form data-disable-on-submit data-saved-mode="<?= e($editing['invoice_delivery'] ?? '') ?>" data-saved-domain-count="<?= e($savedDomainCount) ?>" data-saved-primary-domain="<?= $savedPrimaryDomainExists ? 'true' : 'false' ?>" data-domain-duplicate-check-url="<?= e(app_url('/dashboard/check_customer_domain.php')) ?>" data-workspace-duplicate-check-url="<?= e(app_url('/dashboard/check_customer_domain.php')) ?>"><?= csrf_field() ?><input type="hidden" name="submission_token" value="<?= e($customerSubmissionToken) ?>"><input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>"><input type="hidden" name="mode_change_confirmed" value="">
      <label class="field"><span class="field__label">Company name <span class="required-mark">*</span></span><input class="input" name="name" maxlength="190" placeholder="Enter company name" autocomplete="organization" data-validation-required="true" required value="<?= e($_POST['name'] ?? $editing['name'] ?? '') ?>"></label>
      <label class="field"><span class="field__label">Contact person <span class="required-mark">*</span></span><input class="input" name="contact_person" maxlength="150" placeholder="Full name" autocomplete="name" required aria-invalid="<?= isset($customerFieldErrors['contact_person']) ? 'true' : 'false' ?>" aria-describedby="customer-contact-error" value="<?= e($_POST['contact_person'] ?? $editing['contact_person'] ?? '') ?>"><?php if (isset($customerFieldErrors['contact_person'])): ?><span class="field__error" id="customer-contact-error" role="alert"><?= e($customerFieldErrors['contact_person']) ?></span><?php endif; ?></label>
      <label class="field"><span class="field__label">Email address <span class="required-mark">*</span></span><input class="input" type="email" name="email" maxlength="190" placeholder="Enter email address" autocomplete="email" required aria-invalid="<?= isset($customerFieldErrors['email']) ? 'true' : 'false' ?>" aria-describedby="customer-email-error" value="<?= e($_POST['email'] ?? $editing['email'] ?? '') ?>"><?php if (isset($customerFieldErrors['email'])): ?><span class="field__error" id="customer-email-error" role="alert"><?= e($customerFieldErrors['email']) ?></span><?php endif; ?></label>
      <label class="field"><span class="field__label">Phone number <span class="required-mark">*</span></span><input class="input" type="tel" name="phone" maxlength="50" placeholder="Enter phone number" autocomplete="tel" required pattern="(?=(?:\D*\d){7,15}\D*$)\+?[0-9\s().-]+" aria-describedby="<?= isset($customerFieldErrors['phone']) ? 'customer-phone-error' : 'customer-phone-hint' ?>" aria-invalid="<?= isset($customerFieldErrors['phone']) ? 'true' : 'false' ?>" value="<?= e($_POST['phone'] ?? $editing['phone'] ?? '') ?>"><?php if (isset($customerFieldErrors['phone'])): ?><span class="field__error" id="customer-phone-error" role="alert"><?= e($customerFieldErrors['phone']) ?></span><?php else: ?><span class="field__hint" id="customer-phone-hint">Use 7–15 digits; spaces, parentheses, dots and hyphens are accepted.</span><?php endif; ?></label>
      <?php $selectedInvoiceDelivery = $_POST['invoice_delivery'] ?? $editing['invoice_delivery'] ?? 'primary'; ?>
      <section class="dm customer-fields__wide<?= $selectedInvoiceDelivery === 'domain' ? ' dm--domain' : '' ?>" data-customer-domain-section>
        <fieldset class="dm-fieldset">
          <legend class="dm-legend">Send invoices to</legend>
          <div class="dm-opts">
            <label class="dm-opt"><input type="radio" name="invoice_delivery" value="primary"<?= $selectedInvoiceDelivery === 'primary' ? ' checked' : '' ?>><span><span class="dm-opt-title">Primary email</span><span class="dm-opt-desc">All invoices go to the customer's primary email.</span></span></label>
            <label class="dm-opt"><input type="radio" name="invoice_delivery" value="domain"<?= $selectedInvoiceDelivery === 'domain' ? ' checked' : '' ?>><span><span class="dm-opt-title">Domain email</span><span class="dm-opt-desc">Each invoice goes to the email of the domain you choose on the invoice.</span></span></label>
          </div>
        </fieldset>
        <fieldset id="domains-section"<?= $selectedInvoiceDelivery === 'domain' ? '' : ' hidden disabled' ?>>
        <div class="dm-head">
          <div>
            <div class="dm-title-row"><h3 class="dm-title" id="customer-domains-title">Domains</h3><span class="dm-count" data-domain-count><?= count(array_filter($activeDomains, static fn(array $domain): bool => ($domain['status'] ?? 'active') === 'active')) ?></span></div>
            <div class="dm-sub">Each domain needs its own company and billing details, used when you choose that domain on an invoice.</div>
          </div>
          <button type="button" class="dm-add dm-add--danger" data-add-customer-domain><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 3v10M3 8h10"/></svg><span>Add domain</span></button>
        </div>
        <div class="dm-rows" data-customer-domain-list>
          <?php foreach ($activeDomains as $domainIndex => $domain): if (!is_array($domain)) { continue; } $domainId = $domain['id'] ?? ''; $domainName = (string) ($domain['domain_name'] ?? ''); $domainEmail = (string) ($domain['invoice_email'] ?? ''); $domainCompany = (string) ($domain['company_name'] ?? ''); $domainPhoneNumber = (string) ($domain['phone_number'] ?? ''); $domainBillingAddress = (string) ($domain['billing_address'] ?? ''); $companyError = $domainFieldErrors[$domainIndex]['company_name'] ?? ''; $phoneNumberError = $domainFieldErrors[$domainIndex]['phone_number'] ?? ''; $domainNameError = $domainFieldErrors[$domainIndex]['domain_name'] ?? ''; $expiryError = $domainFieldErrors[$domainIndex]['expiry_date'] ?? ''; $invoiceEmailError = $domainFieldErrors[$domainIndex]['invoice_email'] ?? ''; $billingAddressError = $domainFieldErrors[$domainIndex]['billing_address'] ?? ''; $usageCount = 0; if ($domainId !== '' && filter_var($domainId, FILTER_VALIDATE_INT)) { $usageQuery = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE domain_id=?'); $usageQuery->execute([(int) $domainId]); $usageCount = (int) $usageQuery->fetchColumn(); } ?>
          <div class="domain-row" data-customer-domain-row data-domain-row>
            <input type="hidden" name="domains[<?= e($domainIndex) ?>][id]" value="<?= e($domainId) ?>">
            <div class="domain-row__header"><div class="domain-row__title"><span class="domain-row__num" data-row-number><?= e((int) $domainIndex + 1) ?></span><span>Domain</span></div><button class="domain-row__remove" type="button" aria-label="Remove domain <?= e((int) $domainIndex + 1) ?>" title="Remove domain <?= e((int) $domainIndex + 1) ?>" data-remove-customer-domain data-used-invoices="<?= e($usageCount) ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg></button></div>
            <div class="domain-row__grid domain-row__grid--two">
              <label class="field"><span class="field__label">Company name <span class="required-mark">*</span></span><input class="input" type="text" name="domains[<?= e($domainIndex) ?>][company_name]" maxlength="150" placeholder="Enter company name" value="<?= e($_POST['domains'][$domainIndex]['company_name'] ?? $domainCompany) ?>" data-domain-company required><span class="field__error" data-error-for="company_name" role="alert"<?= $companyError === '' ? ' hidden' : '' ?>><?= e($companyError) ?></span></label>
              <label class="field"><span class="field__label">Phone number <span class="required-mark">*</span></span><input class="input" type="tel" name="domains[<?= e($domainIndex) ?>][phone_number]" maxlength="20" autocomplete="tel" placeholder="Enter phone number" inputmode="tel" value="<?= e($_POST['domains'][$domainIndex]['phone_number'] ?? $domainPhoneNumber) ?>" data-domain-phone required><span class="field__error" data-error-for="phone_number" role="alert"<?= $phoneNumberError === '' ? ' hidden' : '' ?>><?= e($phoneNumberError) ?></span></label>
            </div>
            <div class="domain-row__grid">
              <label class="field"><span class="field__label">Domain <span class="required-mark">*</span></span><input class="input" type="text" name="domains[<?= e($domainIndex) ?>][domain]" maxlength="253" placeholder="example.co.ke" autocomplete="off" value="<?= e($_POST['domains'][$domainIndex]['domain'] ?? $_POST['domains'][$domainIndex]['domain_name'] ?? $domainName) ?>" data-domain-name required><span class="field__error" data-error-for="domain" data-domain-error role="alert"<?= $domainNameError === '' ? ' hidden' : '' ?>><?= e($domainNameError) ?></span></label>
              <label class="field"><span class="field__label">Expiry date <span class="required-mark">*</span></span><input class="input" type="date" name="domains[<?= e($domainIndex) ?>][expiry_date]" value="<?= e($_POST['domains'][$domainIndex]['expiry_date'] ?? $domain['expiry_date'] ?? '') ?>" data-domain-expiry="required" required><span class="field__error" data-error-for="expiry_date" data-expiry-error role="alert"<?= $expiryError === '' ? ' hidden' : '' ?>><?= e($expiryError) ?></span></label>
            <label class="field"><span class="field__label">Invoice email <span class="required-mark">*</span></span><input class="input" type="email" name="domains[<?= e($domainIndex) ?>][invoice_email]" maxlength="190" placeholder="Enter invoice email" value="<?= e($_POST['domains'][$domainIndex]['invoice_email'] ?? $domainEmail) ?>" data-domain-email required><span class="field__error" data-error-for="invoice_email" data-email-error role="alert"<?= $invoiceEmailError === '' ? ' hidden' : '' ?>><?= e($invoiceEmailError) ?></span></label>
            </div>
            <label class="field customer-fields__wide"><span class="field__label">Billing address <span class="required-mark">*</span></span><textarea class="input textarea" name="domains[<?= e($domainIndex) ?>][billing_address]" maxlength="500" rows="3" placeholder="Street, city, postal code" data-domain-billing required><?= e($_POST['domains'][$domainIndex]['billing_address'] ?? $domainBillingAddress) ?></textarea><span class="field__error" data-error-for="billing_address" role="alert"<?= $billingAddressError === '' ? ' hidden' : '' ?>><?= e($billingAddressError) ?></span></label>
          </div>
          <?php endforeach; ?>
        </div>
        <p class="field__error" data-domain-validation-summary role="alert"<?= $error !== '' && ($domainFieldErrors !== [] || str_contains($error, 'at least one active domain')) ? '' : ' hidden' ?>><?= $error !== '' && ($domainFieldErrors !== [] || str_contains($error, 'at least one active domain')) ? e($error) : '' ?></p>
        <template data-customer-domain-template><div class="domain-row" data-customer-domain-row data-domain-row>
          <input type="hidden" name="domains[__INDEX__][id]" value="">
          <div class="domain-row__header"><div class="domain-row__title"><span class="domain-row__num" data-row-number>1</span><span>Domain</span></div><button class="domain-row__remove" type="button" aria-label="Remove domain 1" title="Remove domain 1" data-remove-customer-domain data-used-invoices="0"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg></button></div>
          <div class="domain-row__grid domain-row__grid--two">
            <label class="field"><span class="field__label">Company name <span class="required-mark">*</span></span><input class="input" type="text" name="domains[__INDEX__][company_name]" maxlength="150" placeholder="Enter company name" data-domain-company required><span class="field__error" data-error-for="company_name" role="alert" hidden></span></label>
            <label class="field"><span class="field__label">Phone number <span class="required-mark">*</span></span><input class="input" type="tel" name="domains[__INDEX__][phone_number]" maxlength="20" autocomplete="tel" placeholder="Enter phone number" inputmode="tel" data-domain-phone required><span class="field__error" data-error-for="phone_number" role="alert" hidden></span></label>
          </div>
          <div class="domain-row__grid">
            <label class="field"><span class="field__label">Domain <span class="required-mark">*</span></span><input class="input" type="text" name="domains[__INDEX__][domain]" maxlength="253" placeholder="example.co.ke" autocomplete="off" data-domain-name required><span class="field__error" data-error-for="domain" data-domain-error data-domain-duplicate-error role="alert" hidden></span></label>
            <label class="field"><span class="field__label">Expiry date <span class="required-mark">*</span></span><input class="input" type="date" name="domains[__INDEX__][expiry_date]" data-domain-expiry="required" required><span class="field__error" data-error-for="expiry_date" data-expiry-error role="alert" hidden></span></label>
            <label class="field"><span class="field__label">Invoice email <span class="required-mark">*</span></span><input class="input" type="email" name="domains[__INDEX__][invoice_email]" maxlength="190" placeholder="Enter invoice email" data-domain-email required><span class="field__error" data-error-for="invoice_email" data-email-error data-domain-email-duplicate-error role="alert" hidden></span></label>
          </div>
          <label class="field customer-fields__wide"><span class="field__label">Billing address <span class="required-mark">*</span></span><textarea class="input textarea" name="domains[__INDEX__][billing_address]" maxlength="500" rows="3" placeholder="Street, city, postal code" data-domain-billing required></textarea><span class="field__error" data-error-for="billing_address" role="alert" hidden></span></label>
        </div></template>
        <?php if ($archivedDomains): ?><details class="dm-archived"><summary>Archived domains (<?= count($archivedDomains) ?>)</summary><?php foreach ($archivedDomains as $archivedDomain): ?><div class="dm-archived-row"><span><?= e($archivedDomain['domain_name']) ?><?= $archivedDomain['expiry_date'] ? ' · Expires ' . e($archivedDomain['expiry_date']) : '' ?><?= $archivedDomain['invoice_email'] ? ' · ' . e($archivedDomain['invoice_email']) : '' ?></span><label><input type="checkbox" name="restore_domains[]" value="<?= e($archivedDomain['id']) ?>" data-restore-domain> <span data-restore-label>Restore</span></label></div><?php endforeach; ?></details><?php endif; ?>
        </fieldset>
      </section>
      <section id="primary-domain-section" class="customer-fields__wide" aria-labelledby="primary-domain-title"<?= $selectedInvoiceDelivery === 'domain' ? ' hidden' : '' ?>>
        <div class="dm-title-row"><h3 id="primary-domain-title" class="section__title">Domain</h3><span class="dm-count" data-primary-domain-count><?= $savedPrimaryDomainExists ? '1' : '0' ?></span></div>
        <p class="section__hint">Add business domain.</p>
        <div class="domain-row domain-row--single">
          <div class="domain-row__grid domain-row__grid--two">
            <label class="field"><span class="field__label">Domain <span class="required-mark">*</span></span><input class="input" type="text" name="primary_domain" maxlength="253" placeholder="example.co.ke" autocomplete="off" data-primary-domain required<?= $selectedInvoiceDelivery === 'domain' ? ' disabled' : '' ?> aria-invalid="<?= isset($primaryDomainFieldErrors['primary_domain']) ? 'true' : 'false' ?>" value="<?= e(is_string($_POST['primary_domain'] ?? null) ? $_POST['primary_domain'] : (string) ($editing['primary_domain'] ?? '')) ?>"><span class="field__error" data-error-for="primary_domain" role="alert"<?= isset($primaryDomainFieldErrors['primary_domain']) ? '' : ' hidden' ?>><?= e($primaryDomainFieldErrors['primary_domain'] ?? '') ?></span></label>
            <label class="field"><span class="field__label">Expiry date <span class="required-mark">*</span></span><input class="input" type="date" name="primary_domain_expiry" data-primary-domain-expiry required<?= $selectedInvoiceDelivery === 'domain' ? ' disabled' : '' ?> aria-invalid="<?= isset($primaryDomainFieldErrors['primary_domain_expiry']) ? 'true' : 'false' ?>" value="<?= e(is_string($_POST['primary_domain_expiry'] ?? null) ? $_POST['primary_domain_expiry'] : (string) ($editing['primary_domain_expiry'] ?? '')) ?>"><span class="field__error" data-error-for="primary_domain_expiry" role="alert"<?= isset($primaryDomainFieldErrors['primary_domain_expiry']) ? '' : ' hidden' ?>><?= e($primaryDomainFieldErrors['primary_domain_expiry'] ?? '') ?></span></label>
          </div>
        </div>
      </section>
      <section class="dm customer-fields__wide" data-customer-workspace-section>
        <div class="dm-head dm-workspace-head">
          <div class="dm-workspace-heading">
            <div class="dm-title-row"><h3 class="dm-title" id="customer-workspaces-title">Google Workspace</h3><span class="dm-count" data-workspace-count><?= count($activeWorkspaces) ?></span></div>
            <div class="dm-sub">Optional. Add each Google Workspace domain and its expiry date.<br>A domain may also appear under Domains.</div>
          </div>
          <button type="button" class="dm-add dm-add--danger" data-add-customer-workspace><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 3v10M3 8h10"/></svg><span>Add Google Workspace</span></button>
        </div>
        <div class="dm-rows" data-customer-workspace-list>
          <?php foreach ($activeWorkspaces as $workspaceIndex => $workspace): if (!is_array($workspace)) { continue; } $workspaceId = $workspace['id'] ?? ''; $workspaceName = (string) ($workspace['domain_name'] ?? ''); $workspaceNameError = $workspaceFieldErrors[$workspaceIndex]['domain_name'] ?? ''; $workspaceExpiryError = $workspaceFieldErrors[$workspaceIndex]['expiry_date'] ?? ''; ?>
          <div class="dm-row dm-workspace-row" data-customer-workspace-row>
            <input type="hidden" name="google_workspaces[<?= e($workspaceIndex) ?>][id]" value="<?= e($workspaceId) ?>">
            <div><label class="dm-label" for="dm-workspace-domain-<?= e($workspaceIndex) ?>">Domain<span class="dm-req"> *</span></label><input class="dm-input" type="text" id="dm-workspace-domain-<?= e($workspaceIndex) ?>" name="google_workspaces[<?= e($workspaceIndex) ?>][domain_name]" maxlength="253" placeholder="example.co.ke" value="<?= e($_POST['google_workspaces'][$workspaceIndex]['domain_name'] ?? $workspaceName) ?>" data-workspace-domain required aria-describedby="workspace-domain-error-<?= e($workspaceIndex) ?>"<?= $workspaceNameError !== '' ? ' aria-invalid="true" data-validation-touched="true"' : '' ?>><div class="dm-error" id="workspace-domain-error-<?= e($workspaceIndex) ?>" data-workspace-domain-error role="alert"<?= $workspaceNameError === '' ? ' hidden' : '' ?>><?= e($workspaceNameError) ?></div></div>
            <div><label class="dm-label" for="dm-workspace-expiry-<?= e($workspaceIndex) ?>">Expiry date<span class="dm-req"> *</span></label><input class="dm-input" type="date" id="dm-workspace-expiry-<?= e($workspaceIndex) ?>" name="google_workspaces[<?= e($workspaceIndex) ?>][expiry_date]" value="<?= e($_POST['google_workspaces'][$workspaceIndex]['expiry_date'] ?? $workspace['expiry_date'] ?? '') ?>" required data-workspace-expiry aria-describedby="workspace-expiry-error-<?= e($workspaceIndex) ?>"<?= $workspaceExpiryError !== '' ? ' aria-invalid="true" data-validation-touched="true"' : '' ?>><div class="dm-error" id="workspace-expiry-error-<?= e($workspaceIndex) ?>" data-workspace-expiry-error role="alert"<?= $workspaceExpiryError === '' ? ' hidden' : '' ?>><?= e($workspaceExpiryError) ?></div></div>
            <button class="dm-remove" type="button" aria-label="Remove Google Workspace domain" title="Remove Google Workspace domain" data-remove-customer-workspace><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg></button>
          </div>
          <?php endforeach; ?>
        </div>
        <template data-customer-workspace-template><div class="dm-row dm-workspace-row" data-customer-workspace-row>
          <input type="hidden" name="google_workspaces[__INDEX__][id]" value="">
          <div><label class="dm-label" for="dm-workspace-domain-__INDEX__">Domain<span class="dm-req"> *</span></label><input class="dm-input" type="text" id="dm-workspace-domain-__INDEX__" name="google_workspaces[__INDEX__][domain_name]" maxlength="253" placeholder="example.co.ke" data-workspace-domain required aria-describedby="workspace-domain-error-__INDEX__"><div class="dm-error" id="workspace-domain-error-__INDEX__" data-workspace-domain-error role="alert" hidden></div></div>
          <div><label class="dm-label" for="dm-workspace-expiry-__INDEX__">Expiry date<span class="dm-req"> *</span></label><input class="dm-input" type="date" id="dm-workspace-expiry-__INDEX__" name="google_workspaces[__INDEX__][expiry_date]" required data-workspace-expiry aria-describedby="workspace-expiry-error-__INDEX__"><div class="dm-error" id="workspace-expiry-error-__INDEX__" data-workspace-expiry-error role="alert" hidden></div></div>
          <button class="dm-remove" type="button" aria-label="Remove Google Workspace domain" title="Remove Google Workspace domain" data-remove-customer-workspace><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l-1-12M9 7V4h6v3"/></svg></button>
        </div></template>
      </section>
      <div id="primary-billing-address" class="customer-fields__wide"<?= $selectedInvoiceDelivery === 'domain' ? ' hidden' : '' ?>><label class="field customer-fields__wide"><span class="field__label">Billing address</span><textarea class="input textarea" name="billing_address" maxlength="4000" placeholder="Street, city, postal code"<?= $selectedInvoiceDelivery === 'domain' ? ' disabled' : '' ?>><?= e($selectedInvoiceDelivery === 'domain' ? ($editing['billing_address'] ?? '') : ($_POST['billing_address'] ?? $editing['billing_address'] ?? '')) ?></textarea></label></div>
      <label class="field"><span class="field__label">Internal notes</span><textarea class="input textarea" name="notes" maxlength="4000" placeholder="Notes for your team"><?= e($_POST['notes'] ?? $editing['notes'] ?? '') ?></textarea></label>
      <div class="customer-fields__actions"><button class="btn btn--primary<?= $editing ? '' : ' btn--danger' ?>" type="submit"><?= $editing ? 'Save customer changes' : 'Add customer' ?></button><a class="btn btn--secondary customer-fields__cancel" href="<?= e(app_url('/dashboard/clients.php')) ?>">Back to customers</a></div>
    </form>
  </section>
  <?php if ($editing): ?>
  <section class="card card__pad">
    <div class="card__head"><div><h2 class="heading-lg">Customer invoices</h2><p class="card__sub">Invoices issued or drafted for <?= e($editing['name']) ?>.</p></div></div>
    <?php if ($customerInvoices): ?>
    <div class="table-wrap"><table id="customer-invoice-register" data-enhanced-table data-controls="page-size-only" data-status-filter-column="5"><thead><tr><th>Invoice</th><th data-sort-type="date">Issue date</th><th data-sort-type="date">Due date</th><th data-sort-type="number">Total</th><th data-sort-type="number">Balance</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($customerInvoices as $invoice): $status = invoice_status($invoice); $statusClass = $status === 'paid' ? 'paid' : ($status === 'overdue' || ($status === 'issued' && money_to_cents($invoice['paid_amount']) === 0) ? 'overdue' : 'pending'); $balance = max(0, money_to_cents($invoice['total']) - money_to_cents($invoice['paid_amount'])); ?>
      <tr><td><a class="link" href="<?= e(app_url('/dashboard/invoice_view.php?id=' . $invoice['id'])) ?>"><?= e($invoice['invoice_number'] ?: 'Draft #' . $invoice['id']) ?></a></td><td data-sort-value="<?= e($invoice['issue_date']) ?>"><?= e($invoice['issue_date']) ?></td><td data-sort-value="<?= e($invoice['due_date']) ?>"><?= e($invoice['due_date']) ?></td><td data-sort-value="<?= e(money_to_cents($invoice['total'])) ?>"><?= money($invoice['total'], invoice_currency($invoice)) ?></td><td data-sort-value="<?= e($balance) ?>"><?= money(cents_to_decimal($balance), invoice_currency($invoice)) ?></td><td><span class="pill pill--<?= $statusClass ?>"><?= e(status_label($status)) ?></span></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php else: ?><p class="empty">No invoices have been created for this customer.</p><?php endif; ?>
  </section>
  <section class="card card__pad">
    <div class="card__head"><div><h2 class="heading-lg">Customer email log</h2><p class="card__sub">Recent email deliveries for <?= e($editing['name']) ?>’s invoices.</p></div></div>
    <?php if ($customerEmailLogs): ?>
    <div class="table-wrap"><table id="customer-email-log" data-enhanced-table data-filter-columns="2,4" data-filter-labels="Type,Status"><thead><tr><th data-sort-type="date">Date</th><th>Invoice</th><th>Type</th><th>Recipient</th><th>Status</th><th>Preview</th><th>Action</th></tr></thead><tbody>
      <?php foreach ($customerEmailLogs as $emailLog): $emailCreatedAt = new DateTimeImmutable($emailLog['created_at'], new DateTimeZone('UTC')); ?>
      <tr><td data-sort-value="<?= e($emailCreatedAt->format('Y-m-d H:i:s')) ?>"><?= e($emailCreatedAt->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('j M Y H:i')) ?></td><td><a class="link" href="<?= e(app_url('/dashboard/invoice_view.php?id=' . $emailLog['invoice_id'])) ?>"><?= e($emailLog['invoice_number'] ?: 'Draft #' . $emailLog['invoice_id']) ?></a></td><td><?= e(str_replace('_', ' ', $emailLog['type'])) ?></td><td><?= e($emailLog['recipient'] ?? '—') ?></td><td><?= e(ucfirst($emailLog['status'])) ?></td><td><?php if (trim((string) ($emailLog['text_body'] ?? '')) !== ''): ?><details><summary>View</summary><pre><?= e($emailLog['text_body']) ?></pre></details><?php else: ?>—<?php endif; ?></td><td><?php if (can_send_invoice_emails() && in_array($emailLog['type'], ['invoice_issued','invoice_partial','invoice_overdue','payment_received'], true)): ?><form method="post" action="<?= e(app_url('/dashboard/invoice_view.php?id=' . $emailLog['invoice_id'])) ?>" class="invoice-email-log__send-form" data-disable-on-submit data-confirm="Resend this invoice email?" data-confirm-title="Send Email" data-confirm-accept-label="Send Email" data-confirm-danger="true" data-confirm-action-style="danger"><?= csrf_field() ?><input type="hidden" name="action" value="send_email_log"><input type="hidden" name="email_log_id" value="<?= e($emailLog['id']) ?>"><button class="btn btn--danger invoice-email-log__send" type="submit">Send Email</button></form><?php else: ?>—<?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php else: ?><p class="empty">No invoice emails have been recorded for this customer.</p><?php endif; ?>
  </section>
  <?php endif; ?>
  <?php else: ?>
  <section class="card card__pad">
    <div class="card__head card__head--row">
      <div><h2 class="heading-lg">Customer directory</h2><p class="card__sub"><?= count($customers) ?> active customers shown</p></div>
      <a class="btn btn--danger" href="<?= e(app_url('/dashboard/clients.php?new=1')) ?>">Create a Customer</a>
    </div>
    <form class="filter-form filter-panel filter-panel--search" method="get" role="search" aria-label="Search customers">
      <label class="field"><span class="field__label">Search customers</span><input class="input" type="search" name="q" data-table-search-for="customer-directory" placeholder="e.g. company, contact person, or email" value="<?= e($search) ?>"></label>
      <div class="filter-panel__actions"><button class="btn btn--primary" type="submit"><svg class="icon"><use href="#i-search"></use></svg>Search customers</button></div>
    </form>
    <?php if ($customers): ?><div class="table-wrap"><table id="customer-directory" data-enhanced-table data-controls="page-size-only"><thead><tr><th>Company Name</th><th>Contact Person</th><th>Email Address</th><th class="col-domains" data-sort-type="number">DOMAINS</th><th>Phone Number</th><th>Actions</th></tr></thead><tbody><?php foreach ($customers as $customer): $domainCount = (int) $customer['domain_count']; ?><tr><td><strong><?= e($customer['name']) ?></strong></td><td><?= e($customer['contact_person']) ?></td><td><?= e($customer['email']) ?></td><td class="col-domains" data-sort-value="<?= e($domainCount) ?>"><span class="customer-domain-count<?= $domainCount > 0 ? ' customer-domain-count--has-domains' : '' ?>"><svg class="icon" aria-hidden="true"><use href="#i-globe"></use></svg><span><?= e($domainCount) ?></span></span></td><td><?= e($customer['phone']) ?></td><td><div class="row-actions"><a class="action-pill action-pill--edit" href="<?= e(app_url('/dashboard/clients.php?edit=' . $customer['id'])) ?>">Edit</a><form method="post" data-confirm="Are you sure you want to delete this customer? Their existing invoices will be kept." data-confirm-title="Delete customer" data-confirm-danger="true"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($customer['id']) ?>"><button class="action-pill action-pill--delete" type="submit">Delete</button></form></div></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="empty">No active customers found.</p><?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>