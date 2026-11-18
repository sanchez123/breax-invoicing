<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_auth();
$pdo = db();
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (post_string('action', 40, true) !== 'delete_selected_invoices') {
            throw new InvalidArgumentException('Unknown invoice action.');
        }
        $submittedIds = $_POST['invoice_ids'] ?? null;
        if (!is_array($submittedIds)) {
            throw new InvalidArgumentException('Select at least one invoice to delete.');
        }
        $invoiceIds = [];
        foreach ($submittedIds as $submittedId) {
            $invoiceId = filter_var($submittedId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($invoiceId === false) {
                throw new InvalidArgumentException('The selected invoice list is invalid.');
            }
            $invoiceIds[] = $invoiceId;
        }
        $deletedCount = delete_selected_invoices($pdo, $invoiceIds);
        flash(sprintf('Deleted %d invoice %s.', $deletedCount, $deletedCount === 1 ? 'record' : 'records'));
    } catch (InvalidArgumentException $exception) {
        flash($exception->getMessage(), 'error');
    } catch (Throwable $exception) {
        error_log('Selected invoice deletion failed: ' . $exception->getMessage());
        flash('The selected invoices could not be deleted. No selected invoice was removed.', 'error');
    }
    redirect('/dashboard/invoices.php' . ($search !== '' || $statusFilter !== ''
        ? '?' . http_build_query(['q' => $search, 'status' => $statusFilter])
        : ''));
}
$query = "SELECT i.*,
                COALESCE(NULLIF(TRIM(i.company_name_snapshot),''),c.name) customer_name,
                COALESCE(NULLIF(TRIM(i.domain_name),''),NULLIF(TRIM(c.primary_domain),'')) customer_domain,
                COALESCE(p.paid_amount,0) paid_amount
            FROM invoices i JOIN customers c ON c.id=i.customer_id
            LEFT JOIN (SELECT invoice_id,SUM(amount) paid_amount FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id
           WHERE (i.invoice_number LIKE ? OR c.name LIKE ? OR i.company_name_snapshot LIKE ?
               OR i.domain_name LIKE ? OR c.primary_domain LIKE ?)";
$params = array_fill(0, 5, '%' . $search . '%');
if ($statusFilter === 'overdue') {
    $query .= " AND i.status NOT IN ('draft','cancelled') AND i.due_date < CURDATE() AND COALESCE(p.paid_amount,0) < i.total";
} elseif (in_array($statusFilter, ['draft','issued','partially_paid','paid','cancelled'], true)) {
    $query .= ' AND i.status=?';
    $params[] = $statusFilter;
}
$query .= ' ORDER BY i.created_at DESC LIMIT 250';
$statement = $pdo->prepare($query);
$statement->execute($params);
$invoices = $statement->fetchAll();
$pageTitle = 'Invoices';
$activePage = 'invoices';
$pageSubtitle = 'Create and track customer billing';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-head"><div><h1 class="display-lg">Invoices</h1><p class="page-head__sub">Draft, issue, and monitor customer balances.</p></div><a class="btn btn--danger" href="<?= e(app_url('/dashboard/create_invoice.php')) ?>"><svg class="icon"><use href="#i-plus"/></svg>Create invoice</a></div>
<section class="card card__pad">
  <div class="card__head"><div><h2 class="heading-lg">Invoice register</h2><p class="card__sub">Search and filter invoices by customer, number, or status.</p></div></div>
  <form class="filter-form filter-panel" method="get" role="search" aria-label="Filter invoices">
    <label class="field"><span class="field__label">Search invoices</span><input class="input" type="search" name="q" data-table-search-for="invoice-register" aria-label="Search by invoice number or customer" placeholder="e.g. INV-2026-001 or customer name" value="<?= e($search) ?>"></label>
    <label class="field"><span class="field__label">Invoice status</span><select class="input" name="status" data-table-filter-for="invoice-register" aria-label="Filter by invoice status"><option value="">All invoice statuses</option><?php foreach (['draft','issued','partially_paid','overdue','paid','cancelled'] as $status): ?><option value="<?= e($status) ?>"<?= $statusFilter === $status ? ' selected' : '' ?>><?= e(status_label($status)) ?></option><?php endforeach; ?></select></label>
    <div class="filter-panel__actions"><button class="btn btn--primary" type="submit"><svg class="icon"><use href="#i-search"/></svg>Search invoices</button><?php if ($search !== '' || $statusFilter !== ''): ?><a class="btn btn--quiet" href="<?= e(app_url('/dashboard/invoices.php')) ?>">Clear</a><?php endif; ?></div>
  </form>
  <?php if ($invoices): ?>
  <form id="delete-selected-invoices" method="post" data-confirm="Permanently delete the selected invoices regardless of status? Any recorded payments and public-link history for these invoices will also be deleted. Email log entries will be retained without their invoice link. This action cannot be undone." data-confirm-title="Delete selected invoices" data-confirm-accept-label="Delete invoices" data-confirm-cancel-label="Keep invoices" data-confirm-danger="true" data-confirm-action-style="danger">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete_selected_invoices">
    <div class="invoice-register__delete-bar">
      <p class="card__sub">Select invoices in any status to delete. Related payments and public-link history will also be removed.</p>
      <button class="btn btn--danger" type="submit" data-invoice-bulk-delete disabled>Delete selected invoices <span data-invoice-selection-count>0</span></button>
    </div>
    <div class="table-wrap"><table id="invoice-register" data-enhanced-table data-filter-key="tableFilter"><thead><tr><th>Invoice</th><th>Customer</th><th data-sort-type="date">Issue date</th><th data-sort-type="date">Due date</th><th data-sort-type="number">Total</th><th data-sort-type="number">Balance</th><th>Status</th><th class="invoice-register__select-column"><label class="invoice-register__select-all"><span class="sr-only">Select all listed invoices</span><input type="checkbox" data-invoice-select-all aria-label="Select all listed invoices"></label></th></tr></thead><tbody>
    <?php foreach ($invoices as $invoice): $status = invoice_status($invoice); $statusClass = $status === 'paid' ? 'paid' : ($status === 'overdue' || ($status === 'issued' && money_to_cents($invoice['paid_amount']) === 0) ? 'overdue' : 'pending'); $invoiceCurrency = invoice_currency($invoice); ?><tr data-table-filter="<?= e($status === 'overdue' ? 'overdue' : $invoice['status']) ?>"><td><a class="link" href="<?= e(app_url('/dashboard/invoice_view.php?id=' . $invoice['id'])) ?>"><?= e($invoice['invoice_number'] ?: 'Draft #' . $invoice['id']) ?><?php if ($status === 'draft'): ?><small class="table-sub"><span class="action-pill action-pill--edit">Edit draft</span></small><?php endif; ?></a></td><td><?= e($invoice['customer_name']) ?><?php if ($invoice['customer_domain']): ?><small class="table-sub">Domain: <?= e($invoice['customer_domain']) ?></small><?php endif; ?></td><td data-sort-value="<?= e($invoice['issue_date']) ?>"><?= e($invoice['issue_date']) ?></td><td data-sort-value="<?= e($invoice['due_date']) ?>"><?= e($invoice['due_date']) ?></td><td data-sort-value="<?= e(money_to_cents($invoice['total'])) ?>"><?= money($invoice['total'], $invoiceCurrency) ?></td><td data-sort-value="<?= e(max(0, money_to_cents($invoice['total']) - money_to_cents($invoice['paid_amount']))) ?>"><?= money(max(0, (float) $invoice['total'] - (float) $invoice['paid_amount']), $invoiceCurrency) ?></td><td><span class="pill pill--<?= $statusClass ?>"><?= e(status_label($status)) ?></span></td><td class="invoice-register__select-cell"><label class="invoice-register__select"><span class="sr-only">Select invoice <?= e($invoice['invoice_number'] ?: 'Draft #' . $invoice['id']) ?> for deletion</span><input type="checkbox" name="invoice_ids[]" value="<?= e($invoice['id']) ?>" data-invoice-select></label></td></tr><?php endforeach; ?>
  </tbody></table></div>
  </form>
  <?php else: ?><p class="empty">No matching invoices found. <a class="link" href="<?= e(app_url('/dashboard/create_invoice.php')) ?>">Create an invoice</a>.</p><?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>