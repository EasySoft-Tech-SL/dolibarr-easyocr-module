-- Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
--                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
--
-- Migration: rename columns to snake_case convention

ALTER TABLE llx_easyocr_template_details CHANGE COLUMN objectNum page_index integer NOT NULL;
ALTER TABLE llx_easyocr_template_details CHANGE COLUMN startX pos_x integer NOT NULL;
ALTER TABLE llx_easyocr_template_details CHANGE COLUMN startY pos_y integer NOT NULL;
ALTER TABLE llx_easyocr_template_details CHANGE COLUMN width sel_w integer NOT NULL;
ALTER TABLE llx_easyocr_template_details CHANGE COLUMN height sel_h integer NOT NULL;
