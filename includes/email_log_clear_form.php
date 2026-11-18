<?php
$emailLogClearInvoiceId = isset($emailLogClearInvoiceId) ? (int) $emailLogClearInvoiceId : null;
$emailLogClearMessage = $emailLogClearInvoiceId === null
    ? 'Permanently delete all email log entries? This cannot be undone. The bounced-address blocklist will not be changed.'
    : 'Permanently delete all email log entries for this invoice? This cannot be undone.';
?>
<form method="post" class="email-log-clear-form" data-confirm="<?= e($emailLogClearMessage) ?>" data-confirm-title="Clear email logs" data-confirm-accept-label="Clear logs" data-confirm-cancel-label="Keep logs" data-confirm-danger="true" data-confirm-action-style="danger">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="clear_email_logs">
  <?php if ($emailLogClearInvoiceId !== null): ?><input type="hidden" name="invoice_id" value="<?= e($emailLogClearInvoiceId) ?>"><?php endif; ?>
  <button class="btn btn--danger" type="submit">Clear Email Logs</button>
</form>
