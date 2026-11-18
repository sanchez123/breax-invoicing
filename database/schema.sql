CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    can_send_invoice_emails TINYINT(1) NOT NULL DEFAULT 0,
    receive_overdue_alerts TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_install_lock (
    lock_id TINYINT UNSIGNED PRIMARY KEY
) ENGINE=InnoDB;
INSERT IGNORE INTO app_install_lock (lock_id) VALUES (1);

CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    contact_person VARCHAR(150) NULL,
    email VARCHAR(190) NULL,
    invoice_delivery ENUM('primary','domain') NOT NULL DEFAULT 'primary',
    primary_domain VARCHAR(253) NULL,
    primary_domain_expiry DATE NULL,
    phone VARCHAR(50) NULL,
    billing_address TEXT NULL,
    notes TEXT NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customers_status_name (status, name),
    INDEX idx_customers_primary_domain (primary_domain),
    UNIQUE KEY uq_customer_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_domains (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    company_name VARCHAR(190) NULL,
    phone_number VARCHAR(20) NULL,
    domain_name VARCHAR(253) NOT NULL,
    expiry_date DATE NULL,
    invoice_email VARCHAR(190) NULL,
    billing_address TEXT NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_domain_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    UNIQUE KEY uq_customer_domain_global (domain_name),
    UNIQUE KEY uq_customer_domain_invoice_email (invoice_email),
    INDEX idx_customer_domain_status (customer_id,status,domain_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_google_workspaces (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    domain_name VARCHAR(253) NOT NULL,
    expiry_date DATE NOT NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_google_workspace_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    UNIQUE KEY uq_customer_google_workspace_domain (domain_name),
    INDEX idx_customer_google_workspace_expiry (status,expiry_date,domain_name),
    INDEX idx_customer_google_workspace_customer (customer_id,status,domain_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_contact_write_lock (
    lock_id TINYINT UNSIGNED PRIMARY KEY
) ENGINE=InnoDB;
INSERT IGNORE INTO customer_contact_write_lock (lock_id) VALUES (1);

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'General',
    billing_cycle VARCHAR(40) NOT NULL DEFAULT 'one-time',
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_name (name),
    INDEX idx_products_active (status, name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(40) NULL UNIQUE,
    customer_id BIGINT UNSIGNED NOT NULL,
    domain_id BIGINT UNSIGNED NULL,
    domain_name VARCHAR(253) NULL,
    recipient_email VARCHAR(190) NULL,
    company_name_snapshot VARCHAR(190) NULL,
    billing_address_snapshot TEXT NULL,
    phone_number_snapshot VARCHAR(20) NULL,
    currency_code CHAR(3) CHARACTER SET ascii NOT NULL DEFAULT 'KES',
    kes_exchange_rate DECIMAL(18,8) NULL,
    fx_rate_date DATE NULL,
    status ENUM('draft','issued','partially_paid','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL,
    notes TEXT NULL,
    payment_terms TEXT NULL,
    public_token CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NULL,
    public_token_created_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_invoice_domain FOREIGN KEY (domain_id) REFERENCES customer_domains(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoice_user FOREIGN KEY (created_by) REFERENCES users(id),
    UNIQUE KEY uq_invoice_public_token (public_token),
    INDEX idx_invoice_status_due (status, due_date),
    INDEX idx_invoice_customer (customer_id, created_at),
    INDEX idx_invoice_domain (domain_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_public_link_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('created','revoked','regenerated') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_public_link_event_invoice (invoice_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fx_daily_rates (
    rate_date DATE NOT NULL,
    currency_code CHAR(3) CHARACTER SET ascii NOT NULL,
    kes_per_unit DECIMAL(18,8) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rate_date,currency_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS public_invoice_rate_limits (
    ip_hash CHAR(64) PRIMARY KEY,
    window_started DATETIME NOT NULL,
    request_count SMALLINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoice_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    line_total DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    INDEX idx_item_invoice (invoice_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    method VARCHAR(60) NOT NULL,
    reference VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id),
    CONSTRAINT fk_payment_user FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_payment_date (payment_date),
    UNIQUE KEY uq_payment_invoice_reference (invoice_id, reference)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS company_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO company_settings (setting_key,setting_value) VALUES
    ('mpesa_phone','0702655068'),
    ('mpesa_name','Brian Wosukila Mose'),
    ('bank_name','SBM Bank Kenya'),
    ('bank_account_number','0212420560001'),
    ('bank_account_name','Brian Wosukila Mose'),
    ('invoice_currencies','KES,USD,EUR');

CREATE TABLE IF NOT EXISTS email_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NULL,
    type ENUM('invoice_issued','invoice_partial','invoice_overdue','payment_received','admin_overdue_alert','admin_domain_expiry_report','admin_overdue_invoice_report','admin_google_workspace_report','admin_renewal_reminder_digest','template_test') NOT NULL,
    recipient VARCHAR(254) NULL,
    subject VARCHAR(255) NOT NULL,
    status ENUM('sent','failed','bounced') NOT NULL,
    smtp_message_id VARCHAR(255) NULL,
    error TEXT NULL,
    triggered_by VARCHAR(190) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    sent_at DATETIME(6) NULL,
    cc TEXT NULL,
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

CREATE TABLE IF NOT EXISTS admin_overdue_alert_baseline (
    invoice_id BIGINT UNSIGNED PRIMARY KEY,
    due_date DATE NOT NULL,
    suppressed_at DATETIME NOT NULL,
    CONSTRAINT fk_admin_overdue_baseline_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_overdue_alert_job_config (
    config_key VARCHAR(40) PRIMARY KEY,
    config_date DATE NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_overdue_alert_run_state (
    state_key VARCHAR(40) PRIMARY KEY,
    last_run_at DATETIME NOT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO admin_overdue_alert_run_state (state_key,last_run_at)
VALUES ('daily','1000-01-01 00:00:00');

INSERT IGNORE INTO products (name, category, billing_cycle) VALUES
    ('cPanel Starter Package', 'Hosting', 'Yearly'),
    ('cPanel Executive', 'Hosting', 'Yearly'),
    ('Unlimited Hosting Plan', 'Hosting', 'Yearly');
