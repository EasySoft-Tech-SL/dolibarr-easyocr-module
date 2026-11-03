-- Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
--                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
--
-- Migration: Add entity column for Multicompany support and backfill from parent template

ALTER TABLE llx_easyocr_template_details ADD COLUMN entity integer DEFAULT 1 NOT NULL AFTER rowid;

-- Portable form (MySQL and PostgreSQL): UPDATE ... JOIN only exists in MySQL
UPDATE llx_easyocr_template_details SET entity = (SELECT t.entity FROM llx_easyocr_template t WHERE t.rowid = llx_easyocr_template_details.fk_template) WHERE fk_template IN (SELECT t2.rowid FROM llx_easyocr_template t2);
