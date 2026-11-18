  </main>
  <footer class="app-footer">&copy; <?= date('Y') ?> Breax Technologies Limited.</footer>
</div>
<div class="confirm-dialog" id="confirm-dialog" role="presentation" hidden>
  <div class="confirm-dialog__backdrop" data-confirm-cancel></div>
  <section class="confirm-dialog__panel" role="alertdialog" aria-modal="true" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-message" tabindex="-1">
    <h2 id="confirm-dialog-title">Are you sure?</h2>
    <p id="confirm-dialog-message"></p>
    <div class="confirm-dialog__actions">
      <button class="btn btn--quiet btn--accent" type="button" data-confirm-cancel>No</button>
      <button class="btn btn--primary" type="button" id="confirm-dialog-accept">Yes</button>
    </div>
  </section>
</div>
  <?php if (isset($publicInvoiceUrl) && $publicInvoiceUrl !== ''): require __DIR__ . '/invoice_qr_script.php'; endif; ?>
  </body>
  </html>