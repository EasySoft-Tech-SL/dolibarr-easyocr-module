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

-- Search keys of every third party: its tax ids under every stored form and its
-- name, normalized. The supplier of an OCR document is looked up here through an
-- index instead of scanning llx_societe with REPLACE() on seven columns.
-- Derived data only: it is rebuilt from llx_societe whenever it is missing or stale
-- (rows whose tms moved since the last sync), so deleting it loses nothing.
-- kind: T = tax id, C = name with spaces removed, N = name without the legal form.

CREATE TABLE IF NOT EXISTS llx_easyocr_tpkey (
    rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_soc integer NOT NULL,
    kind varchar(1) NOT NULL,
    keyval varchar(128) NOT NULL
) ENGINE=innodb;
