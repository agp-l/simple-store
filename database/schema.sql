-- This is the complete current schema. Re-running it never deletes existing content.
CREATE DATABASE IF NOT EXISTS simple_store
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE simple_store;

-- Each save inserts a complete snapshot of a page or blog post.
-- Only the active_* columns on the previous row are cleared when saving.
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
  category VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  subcategory VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
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
