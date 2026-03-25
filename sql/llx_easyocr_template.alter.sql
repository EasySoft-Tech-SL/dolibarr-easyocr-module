-- Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
--                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
--
-- Migration: Add entity column for Multicompany support

ALTER TABLE llx_easyocr_template ADD COLUMN entity integer DEFAULT 1 NOT NULL AFTER rowid;
