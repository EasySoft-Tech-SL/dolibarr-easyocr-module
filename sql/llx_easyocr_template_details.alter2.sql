-- Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
--                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
--
-- Migration: Add entity column for Multicompany support and backfill from parent template

ALTER TABLE llx_easyocr_template_details ADD COLUMN entity integer DEFAULT 1 NOT NULL AFTER rowid;

UPDATE llx_easyocr_template_details d INNER JOIN llx_easyocr_template t ON t.rowid = d.fk_template SET d.entity = t.entity;
