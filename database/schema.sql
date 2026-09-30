-- This is the complete current schema. Re-running it never deletes existing content.
CREATE DATABASE IF NOT EXISTS simple_store
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE simple_store;

-- Admin and customer accounts share a role-scoped identity table.
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  display_name VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(40) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  role VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  password_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_username (username),
  UNIQUE KEY users_customer_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add customer fields to installations created before customer accounts existed.
SET @customer_email_column = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email');
SET @customer_email_upgrade = IF(@customer_email_column=0,
  'ALTER TABLE users ADD COLUMN email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER username',
  'SELECT 1');
PREPARE customer_email_statement FROM @customer_email_upgrade;
EXECUTE customer_email_statement;
DEALLOCATE PREPARE customer_email_statement;

SET @customer_name_column = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='display_name');
SET @customer_name_upgrade = IF(@customer_name_column=0,
  'ALTER TABLE users ADD COLUMN display_name VARCHAR(120) NOT NULL DEFAULT '''' AFTER email', 'SELECT 1');
PREPARE customer_name_statement FROM @customer_name_upgrade;
EXECUTE customer_name_statement;
DEALLOCATE PREPARE customer_name_statement;

SET @customer_phone_column = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='phone');
SET @customer_phone_upgrade = IF(@customer_phone_column=0,
  'ALTER TABLE users ADD COLUMN phone VARCHAR(40) NOT NULL DEFAULT '''' AFTER display_name', 'SELECT 1');
PREPARE customer_phone_statement FROM @customer_phone_upgrade;
EXECUTE customer_phone_statement;
DEALLOCATE PREPARE customer_phone_statement;

SET @customer_email_index = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='users_customer_email');
SET @customer_index_upgrade = IF(@customer_email_index=0,
  'ALTER TABLE users ADD UNIQUE KEY users_customer_email (email)', 'SELECT 1');
PREPARE customer_index_statement FROM @customer_index_upgrade;
EXECUTE customer_index_statement;
DEALLOCATE PREPARE customer_index_statement;

-- Addresses are owned by one customer and are always queried with user_id.
CREATE TABLE IF NOT EXISTS customer_addresses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(60) NOT NULL,
  recipient VARCHAR(120) NOT NULL,
  street VARCHAR(190) NOT NULL,
  city VARCHAR(120) NOT NULL,
  postal_code VARCHAR(20) NOT NULL,
  country CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CZ',
  phone VARCHAR(40) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY addresses_for_customer (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Checkout keeps immutable prices, recipient, delivery and bank details at the time of purchase.
-- Existing customer history continues to work; a guest order has no user_id.
CREATE TABLE IF NOT EXISTS shop_checkout_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  settings_json LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The admin updater records the last completed version and progress here.
CREATE TABLE IF NOT EXISTS shop_schema_updates (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  schema_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  completed_statements SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) DEFAULT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  order_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  order_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  fulfillment_source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'own',
  fulfillment_note VARCHAR(190) NULL DEFAULT NULL,
  customer_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  subtotal_czk INT UNSIGNED NULL DEFAULT NULL,
  shipping_czk INT UNSIGNED NULL DEFAULT NULL,
  total_czk INT UNSIGNED NOT NULL,
  items_json LONGTEXT NOT NULL,
  shipping_json LONGTEXT NOT NULL,
  dispatch_shipping_json LONGTEXT NULL DEFAULT NULL,
  payment_method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'legacy',
  payment_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unknown',
  payment_details_json LONGTEXT NULL,
  payment_due_at DATETIME NULL DEFAULT NULL,
  payment_paid_at DATETIME NULL DEFAULT NULL,
  payment_verified_by BIGINT UNSIGNED NULL DEFAULT NULL,
  provider_reference VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  variable_symbol VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY order_number (order_number),
  UNIQUE KEY orders_token (order_token),
  UNIQUE KEY orders_variable_symbol (variable_symbol),
  UNIQUE KEY orders_idempotency_key (idempotency_key),
  KEY orders_for_customer (user_id, id),
  KEY orders_payment_status (payment_status, id),
  KEY orders_fulfillment_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A payment may be retried after cancellation. Keep every remote transaction so
-- late notifications can still be reconciled against the original order.
CREATE TABLE IF NOT EXISTS shop_comgate_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NULL,
  order_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
  total_czk INT UNSIGNED NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  merchant VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  test_mode TINYINT(1) NOT NULL,
  trans_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
  redirect_url VARCHAR(2048) NULL,
  return_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY comgate_trans_id (trans_id),
  UNIQUE KEY comgate_return_token (return_token),
  KEY comgate_order_attempt (order_id, id),
  CONSTRAINT comgate_order_fk FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One durable attempt for every GoPay transaction. Pending and uncertain creation
-- reservations prevent duplicate charges on parallel checkout requests.
CREATE TABLE IF NOT EXISTS shop_gopay_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NULL,
  order_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
  total_czk INT UNSIGNED NULL,
  status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  goid VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  test_mode TINYINT(1) NOT NULL,
  payment_id VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NULL,
  redirect_url VARCHAR(2048) NULL,
  return_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY gopay_payment_id (payment_id),
  UNIQUE KEY gopay_return_token (return_token),
  KEY gopay_order_attempt (order_id, id),
  CONSTRAINT gopay_order_fk FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One reservation per order prevents two API calls for the same parcel.
-- An uncertain network result must be reconciled in the Packeta client section.
CREATE TABLE IF NOT EXISTS shop_packeta_shipments (
  order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  packet_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  barcode VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  barcode_text VARCHAR(120) DEFAULT NULL,
  courier_number VARCHAR(100) DEFAULT NULL,
  weight_kg DECIMAL(6,3) NOT NULL,
  submitted_json LONGTEXT NOT NULL,
  last_error VARCHAR(500) DEFAULT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY packeta_barcode (barcode),
  CONSTRAINT packeta_order_fk FOREIGN KEY (order_id) REFERENCES shop_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Local dispatch drafts imported by an administrator into the carrier portal.
-- No number or label is fabricated; the real carrier number is entered after import.
CREATE TABLE IF NOT EXISTS shop_carrier_shipments (
  order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  draft_json LONGTEXT NOT NULL,
  tracking_number VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY carrier_tracking_number (tracking_number),
  CONSTRAINT carrier_shipment_order_fk FOREIGN KEY (order_id) REFERENCES shop_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Retain cancelled parcel numbers and submitted details when a replacement is created.
CREATE TABLE IF NOT EXISTS shop_packeta_cancelled_shipments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  packet_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  barcode VARCHAR(21) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  submitted_json LONGTEXT NOT NULL,
  cancelled_by BIGINT UNSIGNED NOT NULL,
  cancelled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY cancelled_packeta_barcode (barcode),
  KEY cancelled_packeta_order (order_id, id),
  CONSTRAINT cancelled_packeta_order_fk FOREIGN KEY (order_id) REFERENCES shop_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manual corrections and deletions leave a minimal audit trail.
-- No foreign key: the event remains when an order is removed.
CREATE TABLE IF NOT EXISTS shop_order_admin_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  order_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  old_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
  new_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
  reason VARCHAR(500) NOT NULL,
  admin_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY order_admin_events_order (order_id, id),
  KEY order_admin_events_created (created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Financial snapshot for payment corrections and bank-transfer order deletions.
-- Intentionally independent of shop_orders so the audit survives deletion.
CREATE TABLE IF NOT EXISTS shop_order_financial_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  order_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  variable_symbol VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
  action VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payment_status_before VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payment_paid_at DATETIME NULL,
  payment_verified_by BIGINT UNSIGNED NULL,
  total_czk INT UNSIGNED NOT NULL,
  reason VARCHAR(190) NOT NULL,
  admin_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY financial_events_created (created_at, id),
  KEY financial_events_order (order_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OSVČ tax records. Money movements are entered for the actual bank/cash date;
-- an unpaid order is a receivable, not taxable cash income.
CREATE TABLE IF NOT EXISTS shop_tax_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  settings_json LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_tax_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entry_date DATE NOT NULL,
  direction VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  account VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  tax_kind VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  amount_czk INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  counterparty VARCHAR(190) NOT NULL DEFAULT '',
  reference VARCHAR(100) NOT NULL DEFAULT '',
  order_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY tax_entries_date (entry_date, id),
  KEY tax_entries_order (order_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_tax_entry_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entry_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  old_json LONGTEXT NOT NULL,
  new_json LONGTEXT NULL,
  reason VARCHAR(190) NOT NULL,
  admin_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY tax_entry_events_entry (entry_id, id),
  KEY tax_entry_events_date (created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_tax_balances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  opened_on DATE NOT NULL,
  closed_on DATE NULL,
  amount_czk INT UNSIGNED NOT NULL,
  counterparty VARCHAR(190) NOT NULL DEFAULT '',
  description VARCHAR(255) NOT NULL,
  reference VARCHAR(100) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY tax_balances_open (kind, closed_on, opened_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_invoice_sequence (
  calendar_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  next_number INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NULL,
  order_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  seller_json LONGTEXT NOT NULL,
  buyer_json LONGTEXT NOT NULL,
  items_json LONGTEXT NOT NULL,
  subtotal_czk INT UNSIGNED NOT NULL,
  shipping_czk INT UNSIGNED NOT NULL,
  total_czk INT UNSIGNED NOT NULL,
  variable_symbol VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
  payment_method VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'bank_transfer',
  bank_account VARCHAR(40) NOT NULL DEFAULT '',
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'issued',
  emailed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY invoice_order (order_id),
  UNIQUE KEY invoice_number (document_number),
  KEY invoice_issue (issue_date, id),
  CONSTRAINT invoice_order_fk FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @invoice_method_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_invoices' AND COLUMN_NAME='payment_method');
SET @invoice_method_upgrade = IF(@invoice_method_exists=0,
  'ALTER TABLE shop_invoices ADD COLUMN payment_method VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''bank_transfer'' AFTER variable_symbol', 'SELECT 1');
PREPARE invoice_method_statement FROM @invoice_method_upgrade;
EXECUTE invoice_method_statement;
DEALLOCATE PREPARE invoice_method_statement;

CREATE TABLE IF NOT EXISTS shop_invoice_number_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  old_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  new_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason VARCHAR(190) NOT NULL,
  admin_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY invoice_number_events_invoice (invoice_id, id),
  CONSTRAINT invoice_number_event_fk FOREIGN KEY (invoice_id) REFERENCES shop_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Queue is durable; a checkout succeeds even when the mail transport is offline.
CREATE TABLE IF NOT EXISTS shop_mail_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  recipient_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  subject VARCHAR(190) NOT NULL,
  body_text LONGTEXT NOT NULL,
  state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY outbox_event (event_key),
  KEY outbox_state (state, id),
  KEY outbox_order (order_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quantity is a count of physical units; sale snapshots never change with a product edit.
CREATE TABLE IF NOT EXISTS shop_sale_lines (
  order_id BIGINT UNSIGNED NOT NULL,
  line_no SMALLINT UNSIGNED NOT NULL,
  product_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(255) NOT NULL,
  quantity SMALLINT UNSIGNED NOT NULL,
  unit_price_czk INT UNSIGNED NOT NULL,
  PRIMARY KEY (order_id, line_no),
  KEY sale_lines_product (product_key, order_id),
  CONSTRAINT sale_lines_order_fk FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A paid real sale keeps its itemized evidence if an administrator removes the order UI record.
CREATE TABLE IF NOT EXISTS shop_deleted_sale_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  product_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(255) NOT NULL,
  quantity SMALLINT UNSIGNED NOT NULL,
  unit_price_czk INT UNSIGNED NOT NULL,
  order_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  order_created_at DATETIME NOT NULL,
  KEY deleted_sale_lines_order (order_number, id),
  KEY deleted_sale_lines_date (order_created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Minimal carrier references stay available for reconciliation after the shop
-- order disappears; deleting here never cancels the real parcel at a carrier.
CREATE TABLE IF NOT EXISTS shop_deleted_shipments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  carrier VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  external_number VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY deleted_shipments_order (order_number, id),
  KEY deleted_shipments_external (carrier, external_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_stock_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  movement_date DATE NOT NULL,
  quantity_change INT NOT NULL,
  unit_cost_czk INT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL,
  reference VARCHAR(100) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY stock_product_date (product_key, movement_date, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Additive upgrade for installations with the older customer order placeholder.
-- Nullable new columns preserve historical rows without inventing payment details.
SET @order_user_nullable = (SELECT IS_NULLABLE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='user_id');
SET @order_user_upgrade = IF(@order_user_nullable='NO',
  'ALTER TABLE shop_orders MODIFY COLUMN user_id BIGINT UNSIGNED NULL DEFAULT NULL', 'SELECT 1');
PREPARE order_user_statement FROM @order_user_upgrade;
EXECUTE order_user_statement;
DEALLOCATE PREPARE order_user_statement;

SET @order_token_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='order_token');
SET @order_token_upgrade = IF(@order_token_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN order_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER order_number', 'SELECT 1');
PREPARE order_token_statement FROM @order_token_upgrade;
EXECUTE order_token_statement;
DEALLOCATE PREPARE order_token_statement;

SET @order_fulfillment_source_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='fulfillment_source');
SET @order_fulfillment_source_upgrade = IF(@order_fulfillment_source_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN fulfillment_source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''own'' AFTER status', 'SELECT 1');
PREPARE order_fulfillment_source_statement FROM @order_fulfillment_source_upgrade;
EXECUTE order_fulfillment_source_statement;
DEALLOCATE PREPARE order_fulfillment_source_statement;

SET @order_fulfillment_note_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='fulfillment_note');
SET @order_fulfillment_note_upgrade = IF(@order_fulfillment_note_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN fulfillment_note VARCHAR(190) NULL DEFAULT NULL AFTER fulfillment_source', 'SELECT 1');
PREPARE order_fulfillment_note_statement FROM @order_fulfillment_note_upgrade;
EXECUTE order_fulfillment_note_statement;
DEALLOCATE PREPARE order_fulfillment_note_statement;

SET @order_email_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='customer_email');
SET @order_email_upgrade = IF(@order_email_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN customer_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER status', 'SELECT 1');
PREPARE order_email_statement FROM @order_email_upgrade;
EXECUTE order_email_statement;
DEALLOCATE PREPARE order_email_statement;

SET @order_subtotal_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='subtotal_czk');
SET @order_subtotal_upgrade = IF(@order_subtotal_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN subtotal_czk INT UNSIGNED NULL DEFAULT NULL AFTER customer_email', 'SELECT 1');
PREPARE order_subtotal_statement FROM @order_subtotal_upgrade;
EXECUTE order_subtotal_statement;
DEALLOCATE PREPARE order_subtotal_statement;

SET @order_shipping_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='shipping_czk');
SET @order_shipping_upgrade = IF(@order_shipping_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN shipping_czk INT UNSIGNED NULL DEFAULT NULL AFTER subtotal_czk', 'SELECT 1');
PREPARE order_shipping_statement FROM @order_shipping_upgrade;
EXECUTE order_shipping_statement;
DEALLOCATE PREPARE order_shipping_statement;

SET @order_method_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='payment_method');
SET @order_method_upgrade = IF(@order_method_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN payment_method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''legacy'' AFTER shipping_json', 'SELECT 1');
PREPARE order_method_statement FROM @order_method_upgrade;
EXECUTE order_method_statement;
DEALLOCATE PREPARE order_method_statement;

SET @order_payment_status_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='payment_status');
SET @order_payment_status_upgrade = IF(@order_payment_status_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN payment_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''unknown'' AFTER payment_method', 'SELECT 1');
PREPARE order_payment_status_statement FROM @order_payment_status_upgrade;
EXECUTE order_payment_status_statement;
DEALLOCATE PREPARE order_payment_status_statement;

SET @order_payment_details_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='payment_details_json');
SET @order_payment_details_upgrade = IF(@order_payment_details_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN payment_details_json LONGTEXT NULL AFTER payment_status', 'SELECT 1');
PREPARE order_payment_details_statement FROM @order_payment_details_upgrade;
EXECUTE order_payment_details_statement;
DEALLOCATE PREPARE order_payment_details_statement;

SET @order_due_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='payment_due_at');
SET @order_due_upgrade = IF(@order_due_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN payment_due_at DATETIME NULL DEFAULT NULL AFTER payment_details_json', 'SELECT 1');
PREPARE order_due_statement FROM @order_due_upgrade;
EXECUTE order_due_statement;
DEALLOCATE PREPARE order_due_statement;

SET @order_paid_at_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='payment_paid_at');
SET @order_paid_at_upgrade = IF(@order_paid_at_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN payment_paid_at DATETIME NULL DEFAULT NULL AFTER payment_due_at', 'SELECT 1');
PREPARE order_paid_at_statement FROM @order_paid_at_upgrade;
EXECUTE order_paid_at_statement;
DEALLOCATE PREPARE order_paid_at_statement;

SET @order_verified_by_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='payment_verified_by');
SET @order_verified_by_upgrade = IF(@order_verified_by_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN payment_verified_by BIGINT UNSIGNED NULL DEFAULT NULL AFTER payment_paid_at', 'SELECT 1');
PREPARE order_verified_by_statement FROM @order_verified_by_upgrade;
EXECUTE order_verified_by_statement;
DEALLOCATE PREPARE order_verified_by_statement;

SET @order_reference_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='provider_reference');
SET @order_reference_upgrade = IF(@order_reference_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN provider_reference VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER payment_verified_by', 'SELECT 1');
PREPARE order_reference_statement FROM @order_reference_upgrade;
EXECUTE order_reference_statement;
DEALLOCATE PREPARE order_reference_statement;

-- The delivery purchased by the customer stays in shipping_json. This optional
-- operational override changes only which carrier fulfills the same-address parcel.
SET @order_dispatch_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='dispatch_shipping_json');
SET @order_dispatch_upgrade = IF(@order_dispatch_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN dispatch_shipping_json LONGTEXT NULL DEFAULT NULL AFTER shipping_json', 'SELECT 1');
PREPARE order_dispatch_statement FROM @order_dispatch_upgrade;
EXECUTE order_dispatch_statement;
DEALLOCATE PREPARE order_dispatch_statement;

SET @order_vs_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='variable_symbol');
SET @order_vs_upgrade = IF(@order_vs_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN variable_symbol VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER provider_reference', 'SELECT 1');
PREPARE order_vs_statement FROM @order_vs_upgrade;
EXECUTE order_vs_statement;
DEALLOCATE PREPARE order_vs_statement;

SET @order_idempotency_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND COLUMN_NAME='idempotency_key');
SET @order_idempotency_upgrade = IF(@order_idempotency_exists=0,
  'ALTER TABLE shop_orders ADD COLUMN idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER variable_symbol', 'SELECT 1');
PREPARE order_idempotency_statement FROM @order_idempotency_upgrade;
EXECUTE order_idempotency_statement;
DEALLOCATE PREPARE order_idempotency_statement;

-- Historical placeholder rows predate checkout and cannot be reconciled as bank transfers.
UPDATE shop_orders SET payment_method='legacy', payment_status='unknown'
  WHERE order_token IS NULL AND variable_symbol IS NULL AND payment_details_json IS NULL
    AND (payment_method<>'legacy' OR payment_status<>'unknown');

SET @order_token_index = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND INDEX_NAME='orders_token');
SET @order_token_index_upgrade = IF(@order_token_index=0,
  'ALTER TABLE shop_orders ADD UNIQUE KEY orders_token (order_token)', 'SELECT 1');
PREPARE order_token_index_statement FROM @order_token_index_upgrade;
EXECUTE order_token_index_statement;
DEALLOCATE PREPARE order_token_index_statement;

SET @order_vs_index = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND INDEX_NAME='orders_variable_symbol');
SET @order_vs_index_upgrade = IF(@order_vs_index=0,
  'ALTER TABLE shop_orders ADD UNIQUE KEY orders_variable_symbol (variable_symbol)', 'SELECT 1');
PREPARE order_vs_index_statement FROM @order_vs_index_upgrade;
EXECUTE order_vs_index_statement;
DEALLOCATE PREPARE order_vs_index_statement;

SET @order_idempotency_index = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND INDEX_NAME='orders_idempotency_key');
SET @order_idempotency_index_upgrade = IF(@order_idempotency_index=0,
  'ALTER TABLE shop_orders ADD UNIQUE KEY orders_idempotency_key (idempotency_key)', 'SELECT 1');
PREPARE order_idempotency_index_statement FROM @order_idempotency_index_upgrade;
EXECUTE order_idempotency_index_statement;
DEALLOCATE PREPARE order_idempotency_index_statement;

SET @order_status_index = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND INDEX_NAME='orders_payment_status');
SET @order_status_index_upgrade = IF(@order_status_index=0,
  'ALTER TABLE shop_orders ADD KEY orders_payment_status (payment_status, id)', 'SELECT 1');
PREPARE order_status_index_statement FROM @order_status_index_upgrade;
EXECUTE order_status_index_statement;
DEALLOCATE PREPARE order_status_index_statement;

SET @fulfillment_index = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_orders' AND INDEX_NAME='orders_fulfillment_status');
SET @fulfillment_index_upgrade = IF(@fulfillment_index=0,
  'ALTER TABLE shop_orders ADD KEY orders_fulfillment_status (status, id)', 'SELECT 1');
PREPARE fulfillment_index_statement FROM @fulfillment_index_upgrade;
EXECUTE fulfillment_index_statement;
DEALLOCATE PREPARE fulfillment_index_statement;

-- Each save inserts a complete snapshot of a page or blog post.
-- Older inactive snapshots are pruned to config/site.php revision_limit after saving.
CREATE TABLE IF NOT EXISTS content_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  active_document_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  language CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision_number INT UNSIGNED NOT NULL,
  type ENUM('page', 'post') NOT NULL,
  slug VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  active_slug VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  summary TEXT NULL,
  body LONGTEXT NOT NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  visible_in_menu TINYINT(1) NOT NULL DEFAULT 0,
  menu_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY one_revision (document_key, language, revision_number),
  UNIQUE KEY one_current_document (active_document_key, language),
  UNIQUE KEY one_current_slug (type, language, active_slug),
  KEY published_content (type, language, published, active_document_key),
  KEY menu_content (type, language, published, visible_in_menu, menu_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product fields live together; saving creates a new row with all current values.
-- Options, technical specifications, content blocks and gallery live in details_json.
-- They are a complete snapshot, without extra tables or foreign keys.
CREATE TABLE IF NOT EXISTS product_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  active_product_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  language CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision_number INT UNSIGNED NOT NULL,
  slug VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  active_slug VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  name VARCHAR(255) NOT NULL,
  brand VARCHAR(120) NOT NULL DEFAULT '',
  summary TEXT NULL,
  description LONGTEXT NOT NULL,
  details_json LONGTEXT NULL,
  category VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  subcategory VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  price_czk INT UNSIGNED NOT NULL,
  image_path VARCHAR(1000) NOT NULL,
  sizes VARCHAR(255) NOT NULL DEFAULT '',
  stock_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'in_stock',
  published TINYINT(1) NOT NULL DEFAULT 0,
  saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY one_product_revision (product_key, language, revision_number),
  UNIQUE KEY one_current_product (active_product_key, language),
  UNIQUE KEY one_product_slug (language, active_slug),
  KEY products_for_catalog (language, published, active_product_key, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade installations with products created before details_json was introduced.
-- Prepared SQL keeps this single schema safe to re-import in both MySQL and MariaDB.
SET @details_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_revisions' AND COLUMN_NAME = 'details_json');
SET @details_upgrade = IF(@details_exists = 0,
  'ALTER TABLE product_revisions ADD COLUMN details_json LONGTEXT NULL AFTER description',
  'SELECT 1');
PREPARE details_statement FROM @details_upgrade;
EXECUTE details_statement;
DEALLOCATE PREPARE details_statement;

-- Expand the existing product fields to hold a root slug and an arbitrarily nested path.
SET @category_width = (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_revisions' AND COLUMN_NAME='category');
SET @category_upgrade = IF(@category_width < 190,
  'ALTER TABLE product_revisions MODIFY COLUMN category VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
  'SELECT 1');
PREPARE category_statement FROM @category_upgrade;
EXECUTE category_statement;
DEALLOCATE PREPARE category_statement;

SET @subcategory_width = (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_revisions' AND COLUMN_NAME='subcategory');
SET @subcategory_upgrade = IF(@subcategory_width < 500,
  'ALTER TABLE product_revisions MODIFY COLUMN subcategory VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''''',
  'SELECT 1');
PREPARE subcategory_statement FROM @subcategory_upgrade;
EXECUTE subcategory_statement;
DEALLOCATE PREPARE subcategory_statement;

-- A full path identifies each category; its parent is the path before the last slash.
-- Labels may be translated by adding the same path under another language.
CREATE TABLE IF NOT EXISTS catalog_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  language CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  path VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(160) NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY category_by_path (language, path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional overrides for named menu placements. A missing row uses config/menus.php.
-- Manual links live in one JSON list per placement and language; no foreign keys.
CREATE TABLE IF NOT EXISTS navigation_menus (
  language CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  slot VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  parent_path VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  include_blog TINYINT(1) NOT NULL DEFAULT 0,
  items_json LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (language, slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed once; re-importing the schema never overwrites edited titles or ordering.
INSERT IGNORE INTO catalog_categories (language, path, title, sort_order) VALUES
  ('cs', 'spani', 'Spaní', 1),
  ('cs', 'spani/spacaky', 'Spacáky', 1),
  ('cs', 'spani/quilty', 'Quilty', 2),
  ('cs', 'spani/karimatky', 'Karimatky', 3),
  ('cs', 'spani/stany', 'Stany', 4),
  ('cs', 'spani/tarpy', 'Tarpy', 5),
  ('cs', 'spani/vlozky-do-spacaku', 'Vložky do spacáků', 6),
  ('cs', 'spani/polstare', 'Polštáře', 7),
  ('cs', 'spani/moskytiery', 'Moskytiéry', 8),
  ('cs', 'spani/podlazky', 'Podlážky', 9),
  ('cs', 'spani/hamaky', 'Hamaky', 10),
  ('cs', 'spani/koliky-a-snury', 'Kolíky a šňůry', 11),
  ('cs', 'spani/ostatni', 'Ostatní', 12),
  ('cs', 'batohy', 'Batohy', 2),
  ('cs', 'batohy/batohy-do-25-l', 'Batohy do 25 l', 1),
  ('cs', 'batohy/batohy-25-50-l', 'Batohy 25–50 l', 2),
  ('cs', 'batohy/batohy-nad-50-l', 'Batohy nad 50 l', 3),
  ('cs', 'batohy/prislusenstvi-k-batohum', 'Příslušenství k batohům', 4),
  ('cs', 'vybaveni', 'Vybavení', 3),
  ('cs', 'vybaveni/vodni-filtry', 'Vodní filtry', 1),
  ('cs', 'vybaveni/celovky-a-svitilny', 'Čelovky a svítilny', 2),
  ('cs', 'vybaveni/powerbanky', 'Powerbanky', 3),
  ('cs', 'vybaveni/trekove-hole', 'Trekové hole', 4),
  ('cs', 'vybaveni/nepromokave-vaky-a-organizery', 'Nepromokavé vaky a organizéry', 5),
  ('cs', 'vybaveni/lahve-a-vaky-na-vodu', 'Láhve a vaky na vodu', 6),
  ('cs', 'vybaveni/osobni-hygiena-ochrana', 'Osobní hygiena, ochrana', 7),
  ('cs', 'vybaveni/pouzdra-a-obaly', 'Pouzdra a obaly', 8),
  ('cs', 'vybaveni/nesmeky', 'Nesmeky', 9),
  ('cs', 'vybaveni/udrzba-a-opravy', 'Údržba a opravy', 10),
  ('cs', 'vybaveni/ostatni', 'Ostatní', 11),
  ('cs', 'vareni', 'Vaření', 4),
  ('cs', 'vareni/nadobi', 'Nádobí', 1),
  ('cs', 'vareni/varice-a-prislusenstvi', 'Vařiče a příslušenství', 2),
  ('cs', 'vareni/kartuse-a-palivo', 'Kartuše a palivo', 3),
  ('cs', 'vareni/noze', 'Nože', 4),
  ('cs', 'vareni/pribory', 'Příbory', 5),
  ('cs', 'vareni/jidlo-a-napoje', 'Jídlo a nápoje', 6),
  ('cs', 'vareni/prislusenstvi', 'Příslušenství', 7),
  ('cs', 'obleceni', 'Oblečení', 5),
  ('cs', 'obleceni/muzi', 'Muži', 1),
  ('cs', 'obleceni/muzi/bundy', 'Bundy', 1),
  ('cs', 'obleceni/muzi/mikiny', 'Mikiny', 2),
  ('cs', 'obleceni/muzi/termo-pradlo-a-tricka', 'Termo prádlo a trička', 3),
  ('cs', 'obleceni/muzi/kalhoty', 'Kalhoty', 4),
  ('cs', 'obleceni/muzi/ponozky', 'Ponožky', 5),
  ('cs', 'obleceni/zeny', 'Ženy', 2),
  ('cs', 'obleceni/zeny/bundy', 'Bundy', 1),
  ('cs', 'obleceni/zeny/mikiny', 'Mikiny', 2),
  ('cs', 'obleceni/zeny/termo-pradlo-a-tricka', 'Termo prádlo a trička', 3),
  ('cs', 'obleceni/zeny/kalhoty', 'Kalhoty', 4),
  ('cs', 'obleceni/zeny/ponozky', 'Ponožky', 5),
  ('cs', 'obleceni/nepromokave-ponozky', 'Nepromokavé ponožky', 3),
  ('cs', 'obleceni/cepice-a-rukavice', 'Čepice a rukavice', 4),
  ('cs', 'obleceni/ponca-a-plastenky', 'Ponča a pláštěnky', 5),
  ('cs', 'obleceni/doplnky', 'Doplňky', 6),
  ('cs', 'boty', 'Boty', 6),
  ('cs', 'boty/panske-trailove-boty', 'Pánské trailové boty', 1),
  ('cs', 'boty/damske-trailove-boty', 'Dámské trailové boty', 2);

-- Sellable pieces have one count per product identity, shared by translations and options.
-- Existing products start at zero until an administrator enters a verified quantity.
CREATE TABLE IF NOT EXISTS shop_product_inventory (
  product_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  available_quantity INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO shop_product_inventory (product_key, available_quantity)
  SELECT DISTINCT product_key, 0 FROM product_revisions WHERE active_product_key IS NOT NULL;

-- A reservation records why pieces left the sellable count. Consumed pieces remain
-- deducted; cancelling an unshipped order may release its pieces exactly once.
CREATE TABLE IF NOT EXISTS shop_order_stock_reservations (
  order_id BIGINT UNSIGNED NOT NULL,
  product_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  quantity SMALLINT UNSIGNED NOT NULL,
  state VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'reserved',
  PRIMARY KEY (order_id, product_key),
  KEY stock_reservation_product (product_key, state),
  CONSTRAINT order_stock_order_fk FOREIGN KEY (order_id) REFERENCES shop_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Removing a shop order must not remove issued invoices or externally verified
-- payment attempts. Each payment attempt keeps an order/amount snapshot first.
SET @comgate_snapshot_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_comgate_payments' AND COLUMN_NAME='order_number');
SET @comgate_snapshot_upgrade = IF(@comgate_snapshot_exists=0,
  'ALTER TABLE shop_comgate_payments ADD COLUMN order_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER order_id', 'SELECT 1');
PREPARE comgate_snapshot_statement FROM @comgate_snapshot_upgrade;
EXECUTE comgate_snapshot_statement;
DEALLOCATE PREPARE comgate_snapshot_statement;

SET @comgate_amount_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_comgate_payments' AND COLUMN_NAME='total_czk');
SET @comgate_amount_upgrade = IF(@comgate_amount_exists=0,
  'ALTER TABLE shop_comgate_payments ADD COLUMN total_czk INT UNSIGNED NULL AFTER order_number', 'SELECT 1');
PREPARE comgate_amount_statement FROM @comgate_amount_upgrade;
EXECUTE comgate_amount_statement;
DEALLOCATE PREPARE comgate_amount_statement;

SET @comgate_link_nullable = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_comgate_payments' AND COLUMN_NAME='order_id' AND IS_NULLABLE='YES');
SET @comgate_link_upgrade = IF(@comgate_link_nullable=0,
  'ALTER TABLE shop_comgate_payments DROP FOREIGN KEY comgate_order_fk, MODIFY COLUMN order_id BIGINT UNSIGNED NULL, ADD CONSTRAINT comgate_detached_order_fk FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE comgate_link_statement FROM @comgate_link_upgrade;
EXECUTE comgate_link_statement;
DEALLOCATE PREPARE comgate_link_statement;

SET @gopay_snapshot_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_gopay_payments' AND COLUMN_NAME='order_number');
SET @gopay_snapshot_upgrade = IF(@gopay_snapshot_exists=0,
  'ALTER TABLE shop_gopay_payments ADD COLUMN order_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER order_id', 'SELECT 1');
PREPARE gopay_snapshot_statement FROM @gopay_snapshot_upgrade;
EXECUTE gopay_snapshot_statement;
DEALLOCATE PREPARE gopay_snapshot_statement;

SET @gopay_amount_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_gopay_payments' AND COLUMN_NAME='total_czk');
SET @gopay_amount_upgrade = IF(@gopay_amount_exists=0,
  'ALTER TABLE shop_gopay_payments ADD COLUMN total_czk INT UNSIGNED NULL AFTER order_number', 'SELECT 1');
PREPARE gopay_amount_statement FROM @gopay_amount_upgrade;
EXECUTE gopay_amount_statement;
DEALLOCATE PREPARE gopay_amount_statement;

SET @gopay_link_nullable = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_gopay_payments' AND COLUMN_NAME='order_id' AND IS_NULLABLE='YES');
SET @gopay_link_upgrade = IF(@gopay_link_nullable=0,
  'ALTER TABLE shop_gopay_payments DROP FOREIGN KEY gopay_order_fk, MODIFY COLUMN order_id BIGINT UNSIGNED NULL, ADD CONSTRAINT gopay_detached_order_fk FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE gopay_link_statement FROM @gopay_link_upgrade;
EXECUTE gopay_link_statement;
DEALLOCATE PREPARE gopay_link_statement;

SET @invoice_link_nullable = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shop_invoices' AND COLUMN_NAME='order_id' AND IS_NULLABLE='YES');
SET @invoice_link_upgrade = IF(@invoice_link_nullable=0,
  'ALTER TABLE shop_invoices DROP FOREIGN KEY invoice_order_fk, MODIFY COLUMN order_id BIGINT UNSIGNED NULL, ADD CONSTRAINT invoice_detached_order_fk FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE invoice_link_statement FROM @invoice_link_upgrade;
EXECUTE invoice_link_statement;
DEALLOCATE PREPARE invoice_link_statement;
