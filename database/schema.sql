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
