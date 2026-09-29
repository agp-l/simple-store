-- This is the complete current schema. Re-running it never deletes existing content.
CREATE DATABASE IF NOT EXISTS simple_store
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE simple_store;

-- Administrator accounts. Only password hashes are stored, never plaintext passwords.
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  role VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'admin',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  password_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
