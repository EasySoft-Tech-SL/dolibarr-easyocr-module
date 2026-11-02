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
--
-- Indexes of the webhook log: the list and the retention sweep go by (entity, datec),
-- the batch filter by (entity, batch_id) and the idempotency check of every notification
-- by (entity, document_id).
-- Keep every statement on ONE line (Dolibarr 14 and 15 read these files line by line).
ALTER TABLE llx_easyocr_webhook_log ADD INDEX idx_easyocr_webhook_log_datec (entity, datec);
ALTER TABLE llx_easyocr_webhook_log ADD INDEX idx_easyocr_webhook_log_batch (entity, batch_id);
ALTER TABLE llx_easyocr_webhook_log ADD INDEX idx_easyocr_webhook_log_document (entity, document_id);
