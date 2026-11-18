<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_auth();
$pdo = db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT);
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    try {
        if (!$invoiceId || !in_array($action, ['generate', 'regenerate', 'revoke'], true)) {
            throw new InvalidArgumentException('Choose a valid invoice link action.');
        }
        $pdo->beginTransaction();
        if ($action === 'revoke') {
            revoke_public_invoice_link($pdo, (int) $invoiceId);
            $pdo->commit();
            flash('Public invoice link revoked.');
        } else {
            regenerate_public_invoice_link($pdo, (int) $invoiceId);
            $pdo->commit();
            flash($action === 'generate' ? 'Public invoice link created.' : 'Public invoice link regenerated.');
        }
        redirect('/dashboard/public_links.php');
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Public invoice link update failed during a database operation.');
        $error = 'The public invoice link could not be updated.';
    }
}

$invoices = $pdo->query(
    "SELECT i.id,i.invoice_number,i.status,i.issue_date,i.due_date,i.total,i.public_token,i.public_token_created_at,
            c.name customer_name,COALESCE(p.paid_amount,0) paid_amount
       FROM invoices i
       JOIN customers c ON c.id=i.customer_id
       LEFT JOIN (SELECT invoice_id,SUM(amount) paid_amount FROM payments GROUP BY invoice_id) p ON p.invoice_id=i.id
       WHERE i.status <> 'draft'
       ORDER BY i.created_at DESC"
)->fetchAll();
$pageTitle = 'Public invoice links';
$activePage = 'public-links';
$pageSubtitle = 'Create, regenerate, and revoke customer access links';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="page-head"><div><h1 class="display-lg">Public invoice links</h1><p class="page-head__sub">Links are private to anyone who has them. Regenerating one immediately invalidates the previous URL.</p></div></div>
<?php if ($error !== ''): ?><div class="notice notice--error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="card card__pad">
  <div class="card__head"><div><h2 class="heading-lg">Issued invoices</h2><p class="card__sub">Set APP_PUBLIC_BASE_URL on the server to your public application URL before creating or regenerating links.</p></div></div>
  <?php if ($invoices): ?><div class="table-wrap issued-invoices-table"><table id="issued-public-invoices" data-enhanced-table data-filter-column="2" data-filter-label="Status"><thead><tr><th>Invoice</th><th>Customer</th><th>Status</th><th>Public link</th><th data-sort-type="date">Created</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($invoices as $invoice):
        $status = invoice_status($invoice);
        $hasLink = is_string($invoice['public_token']) && $invoice['public_token'] !== '';
        $linkUrl = '';
        if ($hasLink) {
            try {
                $linkUrl = public_invoice_url($invoice['public_token']);
            } catch (InvalidArgumentException) {
                $linkUrl = '';
            }
        }
    ?><tr>
      <td><?= e($invoice['invoice_number'] ?: 'Invoice #' . $invoice['id']) ?></td>
      <td><?= e($invoice['customer_name']) ?></td>
      <td><?= e(status_label($status)) ?></td>
      <td><?php if ($hasLink && $linkUrl !== ''): ?><a class="link" href="<?= e($linkUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($linkUrl) ?></a><?php elseif ($hasLink): ?><span>Active (set APP_PUBLIC_BASE_URL to display URL)</span><?php else: ?><span>None</span><?php endif; ?></td>
      <td data-sort-value="<?= e($invoice['public_token_created_at'] ?? '') ?>"><?= $hasLink && !empty($invoice['public_token_created_at']) ? e(date('j F Y H:i', strtotime($invoice['public_token_created_at']))) : '—' ?></td>
      <td><div class="row-actions">
        <form method="post" data-confirm="<?= $hasLink ? 'Are you sure you want to regenerate this link? The old link will stop working immediately.' : 'Are you sure you want to create a public link for this invoice?' ?>" data-confirm-title="<?= $hasLink ? 'Regenerate public link' : 'Create public link' ?>"<?= $hasLink ? ' data-confirm-danger="true"' : '' ?>><?= csrf_field() ?><input type="hidden" name="invoice_id" value="<?= e($invoice['id']) ?>"><input type="hidden" name="action" value="<?= $hasLink ? 'regenerate' : 'generate' ?>"><button class="action-pill action-pill--edit" type="submit"><?= $hasLink ? 'Regenerate' : 'Create link' ?></button></form>
        <?php if ($hasLink): ?><form method="post" data-confirm="Are you sure you want to revoke this public link? Anyone using it will no longer be able to view the invoice." data-confirm-title="Revoke public link" data-confirm-danger="true"><?= csrf_field() ?><input type="hidden" name="invoice_id" value="<?= e($invoice['id']) ?>"><input type="hidden" name="action" value="revoke"><button class="action-pill action-pill--end" type="submit">Revoke</button></form><?php endif; ?>
      </div></td>
    </tr><?php endforeach; ?>
  </tbody></table></div><?php else: ?><p class="empty">There are no issued invoices yet.</p><?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
