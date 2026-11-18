CREATE TABLE IF NOT EXISTS email_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NULL,
    type ENUM('invoice_issued','invoice_partial','invoice_overdue','payment_received','admin_overdue_alert','admin_domain_expiry_report','admin_overdue_invoice_report','admin_google_workspace_report','template_test') NOT NULL,
    recipient VARCHAR(254) NULL,
    subject VARCHAR(255) NOT NULL,
    status ENUM('sent','failed','bounced') NOT NULL,
    smtp_message_id VARCHAR(255) NULL,
    error TEXT NULL,
    triggered_by VARCHAR(190) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    sent_at DATETIME(6) NULL,
    cc VARCHAR(254) NULL,
    html_body LONGTEXT NULL,
    text_body LONGTEXT NULL,
    idempotency_key VARCHAR(190) NULL,
    INDEX idx_email_log_invoice (invoice_id, created_at),
    UNIQUE KEY uq_email_log_idempotency (idempotency_key),
    CONSTRAINT fk_email_log_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_bounced_addresses (
    address VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci PRIMARY KEY,
    first_bounced_at DATETIME NOT NULL,
    last_bounced_at DATETIME NOT NULL,
    reason VARCHAR(500) NOT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO company_settings (setting_key,setting_value) VALUES
    ('mpesa_phone','0702655068'),
    ('mpesa_name','Brian Wosukila Mose'),
    ('bank_name','SBM Bank Kenya'),
    ('bank_account_number','0212420560001'),
    ('bank_account_name','Brian Wosukila Mose');
