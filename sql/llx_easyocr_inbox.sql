-- Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
--                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

-- Inbox of documents handed over by other modules (EasyScan, ...) through
-- easyocr_recibir_documento(). Receiving a document never calls the AI service:
-- the file waits here until the user processes it from EasyOcr (batch or viewer),
-- at the normal cost and when he decides.
--
-- status: 0 = pending, 1 = sent in a batch (batch_ref), 2 = processed in the viewer
-- (fk_invoice_created). A discarded document is deleted, row and file.

CREATE TABLE IF NOT EXISTS llx_easyocr_inbox (
    rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    entity integer DEFAULT 1 NOT NULL,
    filename varchar(255) NOT NULL,
    label varchar(255),
    origin varchar(32) DEFAULT 'manual' NOT NULL,
    file_hash varchar(64),
    file_size integer,
    fk_facture_fourn integer NULL,
    status smallint DEFAULT 0 NOT NULL,
    batch_ref varchar(64) NULL,
    fk_invoice_created integer NULL,
    date_processed datetime NULL,
    fk_user_creat integer NULL,
    fk_user_modif integer NULL,
    datec datetime NOT NULL,
    tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
