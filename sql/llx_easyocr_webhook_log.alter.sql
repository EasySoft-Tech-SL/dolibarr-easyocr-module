-- ============================================================================
-- Migration: Add columns to llx_easyocr_webhook_log for invoice tracking
-- This file is executed when the module is installed/upgraded
--
-- Keep every statement on ONE line. Dolibarr 14 and 15 read this file statement by
-- statement, and an ALTER TABLE wrapped over two lines reaches MySQL in halves: the
-- second half starts at ADD and the query is rejected with a syntax error on any
-- database that already had the column. Measured on Dolibarr 14.0.5; from 16 on, the
-- same file goes through unharmed.
-- ============================================================================

ALTER TABLE llx_easyocr_webhook_log ADD COLUMN invoice_id INTEGER DEFAULT NULL AFTER batch_progress;
ALTER TABLE llx_easyocr_webhook_log ADD COLUMN invoice_ref VARCHAR(128) DEFAULT NULL AFTER invoice_id;
ALTER TABLE llx_easyocr_webhook_log ADD COLUMN supplier_id INTEGER DEFAULT NULL AFTER invoice_ref;
ALTER TABLE llx_easyocr_webhook_log ADD COLUMN processing_status VARCHAR(32) DEFAULT NULL AFTER supplier_id;
ALTER TABLE llx_easyocr_webhook_log ADD COLUMN processing_message TEXT DEFAULT NULL AFTER processing_status;
