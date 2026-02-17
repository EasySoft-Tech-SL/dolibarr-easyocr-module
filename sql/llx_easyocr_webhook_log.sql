-- ============================================================================
-- Copyright (C) 2025-2026 EasySoft Tech S.L.  <info@easysoft.es>
-- Webhook log table for EasyOCR batch notifications
-- ============================================================================

CREATE TABLE llx_easyocr_webhook_log (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    batch_id        VARCHAR(64)  DEFAULT NULL,
    event           VARCHAR(64)  DEFAULT NULL,
    document_id     VARCHAR(64)  DEFAULT NULL,
    document_filename VARCHAR(255) DEFAULT NULL,
    document_status VARCHAR(32)  DEFAULT NULL,
    batch_status    VARCHAR(32)  DEFAULT NULL,
    batch_progress  INTEGER      DEFAULT 0,
    payload         MEDIUMTEXT,
    datec           DATETIME     DEFAULT NULL
) ENGINE=InnoDB;
