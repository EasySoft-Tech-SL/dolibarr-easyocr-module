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

CREATE TABLE IF NOT EXISTS llx_easyocr_template_details (
    rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_template integer NOT NULL,
    objectNum integer NOT NULL,
    startX integer NOT NULL,
    startY integer NOT NULL,
    width integer NOT NULL,
    height integer NOT NULL,
    color varchar(250),
    label varchar(250),
    date_creation timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=innodb;
