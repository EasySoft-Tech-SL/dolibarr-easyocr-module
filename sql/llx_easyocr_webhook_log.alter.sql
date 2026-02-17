-- ============================================================================
-- Migration: Add columns to llx_easyocr_webhook_log for invoice tracking
-- This file is executed when the module is installed/upgraded
-- ============================================================================

ALTER TABLE llx_easyocr_webhook_log 
ADD COLUMN invoice_id INTEGER DEFAULT NULL AFTER batch_progress;

ALTER TABLE llx_easyocr_webhook_log 
ADD COLUMN invoice_ref VARCHAR(128) DEFAULT NULL AFTER invoice_id;

ALTER TABLE llx_easyocr_webhook_log 
ADD COLUMN supplier_id INTEGER DEFAULT NULL AFTER invoice_ref;

ALTER TABLE llx_easyocr_webhook_log 
ADD COLUMN processing_status VARCHAR(32) DEFAULT NULL AFTER supplier_id;

ALTER TABLE llx_easyocr_webhook_log 
ADD COLUMN processing_message TEXT DEFAULT NULL AFTER processing_status;
