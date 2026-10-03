<?php
/* Copyright (C) 2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                    Alberto Luque Rivas        <aluquerivasdev@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       lib/easyocr_inbox.lib.php
 * \ingroup    easyocr
 * \brief      Inbox of documents received from other modules (EasyScan, ...).
 *
 * The public entry point is easyocr_recibir_documento(), declared in lib/easyocr.lib.php.
 * Receiving a document ONLY copies it to a local folder and records it in
 * llx_easyocr_inbox: there is no call to the AI service here, on purpose. The user
 * processes it afterwards from EasyOcr (batch or viewer), at the normal cost.
 *
 * Nothing in this file talks to the network.
 */

/** Pending: received, waiting for the user. */
define('EASYOCR_INBOX_PENDING', 0);
/** Sent to the AI service inside a batch (batch_ref holds its id). */
define('EASYOCR_INBOX_BATCHED', 1);
/** Processed in the viewer: a supplier invoice was created from it. */
define('EASYOCR_INBOX_PROCESSED', 2);

/**
 * Folder of the inbox for the current entity.
 *
 * DOL_DATA_ROOT/easyocr/inbox on entity 1, DOL_DATA_ROOT/<entity>/easyocr/inbox on the others,
 * the same rule Dolibarr follows for every module (multidir_output).
 *
 * @param  int|null $entity Entity, current one when null
 * @return string           Absolute path, without trailing slash
 */
function easyocrInboxDir($entity = null)
{
	global $conf;

	$entity = ($entity === null) ? (int) $conf->entity : (int) $entity;
	$base = '';
	if (isset($conf->easyocr) && is_object($conf->easyocr) && !empty($conf->easyocr->multidir_output[$entity])) {
		$base = $conf->easyocr->multidir_output[$entity];
	}
	if ($base === '') {
		$base = DOL_DATA_ROOT.($entity > 1 ? '/'.$entity : '').'/easyocr';
	}

	return rtrim($base, '/\\').'/inbox';
}

/**
 * Largest document the inbox accepts, in bytes.
 *
 * The real limit belongs to the plan and only the AI service knows it (batch.php reads it
 * from /me, falling back to 10 MB). Receiving must not call the service, so the inbox uses
 * the same 10 MB fallback, overridable with the hidden constant EASYOCR_INBOX_MAX_SIZE_MB,
 * and never more than Dolibarr's own upload limit (MAIN_UPLOAD_DOC, in KB).
 *
 * @return int
 */
function easyocrInboxMaxBytes()
{
	$mb = (int) getDolGlobalString('EASYOCR_INBOX_MAX_SIZE_MB');
	if ($mb <= 0) {
		$mb = 10;
	}
	$bytes = $mb * 1024 * 1024;

	$doliKb = (int) getDolGlobalString('MAIN_UPLOAD_DOC');
	if ($doliKb > 0 && $doliKb * 1024 < $bytes) {
		$bytes = $doliKb * 1024;
	}

	return $bytes;
}

/**
 * The file is a real PDF, judged by its bytes and not by its name.
 *
 * Same tolerance as PDF readers: the "%PDF-" marker must appear within the first 1024 bytes.
 *
 * @param  string $path Absolute path
 * @return bool
 */
function easyocrInboxIsPdf($path)
{
	$fh = @fopen($path, 'rb');
	if (!$fh) {
		return false;
	}
	$head = (string) fread($fh, 1024);
	fclose($fh);

	return strpos($head, '%PDF-') !== false;
}

/**
 * Normalise a path for comparison: forward slashes, no trailing slash, and lower case on
 * Windows, where the file system does not care about case.
 *
 * @param  string $path Path
 * @return string
 */
function easyocrInboxNormPath($path)
{
	$path = rtrim(str_replace('\\', '/', (string) $path), '/');
	if (DIRECTORY_SEPARATOR === '\\') {
		$path = strtolower($path);
	}

	return $path;
}

/**
 * The source file lives inside DOL_DATA_ROOT and inside the area of the current entity.
 *
 * The path is resolved with realpath() first, so "..", symbolic links or a second spelling
 * cannot take it out of the data root. On entity N > 1 the file must be under
 * DOL_DATA_ROOT/N/; on entity 1, a file under another entity's numeric folder is refused.
 *
 * @param  string $path   Path to check
 * @param  int    $entity Current entity
 * @return string         '' when allowed, otherwise an error code (path, entity)
 */
function easyocrInboxCheckSourcePath($path, $entity)
{
	$real = realpath((string) $path);
	$root = realpath(DOL_DATA_ROOT);
	if ($real === false || $root === false || !is_file($real)) {
		return 'path';
	}

	$nreal = easyocrInboxNormPath($real);
	$nroot = easyocrInboxNormPath($root);
	if (strpos($nreal, $nroot.'/') !== 0) {
		return 'path';
	}

	$relative = substr($nreal, strlen($nroot) + 1);
	$first = strtok($relative, '/');
	$entity = (int) $entity;
	if ($entity > 1) {
		if ($first !== (string) $entity) {
			return 'entity';
		}
	} elseif ($first !== false && ctype_digit($first) && strpos($relative, '/') !== false) {
		// A numeric first folder is another entity's data root.
		return 'entity';
	}

	return '';
}

/**
 * Safe file name for the inbox folder: no path, no odd characters, .pdf extension.
 *
 * @param  string $name Name proposed by the caller
 * @return string
 */
function easyocrInboxSanitizeName($name)
{
	$name = basename(str_replace('\\', '/', (string) $name));
	$name = preg_replace('/\.pdf$/i', '', $name);
	$name = dol_sanitizeFileName($name);
	$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $name);
	$name = trim((string) $name, '._-');
	if ($name === '') {
		$name = 'documento';
	}
	if (strlen($name) > 120) {
		$name = substr($name, 0, 120);
	}

	return $name.'.pdf';
}

/**
 * Visible label: plain text, one line, 255 characters at most.
 *
 * @param  string $label Label proposed by the caller
 * @return string
 */
function easyocrInboxSanitizeLabel($label)
{
	$label = dol_string_nohtmltag((string) $label);
	$label = trim(preg_replace('/\s+/u', ' ', $label));
	if (function_exists('dol_trunc')) {
		$label = dol_trunc($label, 250, 'right', 'UTF-8', 1);
	}

	return $label;
}

/**
 * Origin code: lower case letters, digits, "_" and "-", 32 characters at most.
 *
 * @param  string $origin Origin proposed by the caller
 * @return string
 */
function easyocrInboxSanitizeOrigin($origin)
{
	$origin = strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '', (string) $origin));
	$origin = substr($origin, 0, 32);

	return $origin === '' ? 'manual' : $origin;
}

/**
 * Human label of an origin code.
 *
 * @param  string $origin Origin code
 * @return string
 */
function easyocrInboxOriginLabel($origin)
{
	$known = array('easyscan' => 'EasyScan', 'manual' => 'EasyOcr');

	return isset($known[$origin]) ? $known[$origin] : ucfirst((string) $origin);
}

/**
 * Page where a received document is reviewed.
 *
 * @param  int $id Inbox row id
 * @return string  Relative URL
 */
function easyocrInboxUrl($id)
{
	return dol_buildpath('/easyocr/inbox.php', 1).'?id='.((int) $id).'#eo-inbox-'.((int) $id);
}

/**
 * Receive a document into the inbox. See easyocr_recibir_documento() for the contract.
 *
 * @param  string               $path     Absolute path of the PDF
 * @param  User                 $user     User handing it over
 * @param  array<string,mixed>  $options  origen, nombre, fk_facture_fourn
 * @return array{ok:bool,id:int,url:string,error:string,codigo:string,duplicado?:bool}
 */
function easyocrInboxReceive($path, $user, array $options = array())
{
	global $conf, $db, $langs;

	$fail = function ($code, $text) {
		dol_syslog('EasyOCR inbox: rejected ('.$code.') '.$text, LOG_WARNING);
		return array('ok' => false, 'id' => 0, 'url' => '', 'error' => $text, 'codigo' => $code);
	};

	if (is_object($langs)) {
		$langs->load('easyocr@easyocr');
	}
	$t = function ($key, $param = '') use ($langs) {
		return is_object($langs) ? $langs->transnoentitiesnoconv($key, $param) : $key;
	};

	// Module and permissions, in the entity where the call runs.
	if (empty($conf->easyocr->enabled)) {
		return $fail('module', $t('EasyOcrInboxErrModule'));
	}
	if (!is_object($user) || empty($user->id) || !easyocrCheckRight($user, 'easyocr', 'write')) {
		return $fail('permission', $t('EasyOcrInboxErrPermission'));
	}
	$entity = (int) $conf->entity;
	// Rights are already loaded for the current entity. On top of that, a user who belongs
	// to another entity is refused unless Multicompany shares users (transverse mode);
	// entity 0 is the superadmin of every entity.
	if (!empty($user->entity) && (int) $user->entity !== $entity && !getDolGlobalString('MULTICOMPANY_TRANSVERSE_MODE')) {
		return $fail('entity', $t('EasyOcrInboxErrEntity'));
	}

	// Source path: inside DOL_DATA_ROOT and inside this entity's area.
	$check = easyocrInboxCheckSourcePath($path, $entity);
	if ($check !== '') {
		return $fail($check, $t($check === 'entity' ? 'EasyOcrInboxErrEntity' : 'EasyOcrInboxErrPath'));
	}
	$real = realpath($path);

	// Size and content.
	$size = (int) @filesize($real);
	if ($size <= 0) {
		return $fail('empty', $t('EasyOcrInboxErrEmpty'));
	}
	$max = easyocrInboxMaxBytes();
	if ($size > $max) {
		return $fail('size', $t('EasyOcrInboxErrSize', (string) round($max / 1048576, 1)));
	}
	if (!easyocrInboxIsPdf($real)) {
		return $fail('notpdf', $t('EasyOcrInboxErrNotPdf'));
	}

	$hash = (string) @hash_file('sha256', $real);

	// The same document still pending: answer with the row that already holds it.
	if ($hash !== '') {
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."easyocr_inbox";
		$sql .= " WHERE entity = ".$entity." AND status = ".EASYOCR_INBOX_PENDING;
		$sql .= " AND file_hash = '".$db->escape($hash)."' LIMIT 1";
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return array('ok' => true, 'id' => (int) $obj->rowid, 'url' => easyocrInboxUrl($obj->rowid), 'error' => '', 'codigo' => 'ok', 'duplicado' => true);
		}
	}

	$origin = easyocrInboxSanitizeOrigin(isset($options['origen']) ? $options['origen'] : '');
	$label = easyocrInboxSanitizeLabel(isset($options['nombre']) && $options['nombre'] !== '' ? $options['nombre'] : basename($real));
	if ($label === '') {
		$label = basename($real);
	}

	// Supplier invoice already linked: only kept when it exists in this entity.
	$fkInvoice = 0;
	if (!empty($options['fk_facture_fourn'])) {
		$candidate = (int) $options['fk_facture_fourn'];
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture_fourn WHERE rowid = ".$candidate;
		$sql .= " AND entity IN (".getEntity('facture_fourn').")";
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			$fkInvoice = $candidate;
		}
	}

	// Copy into the inbox folder under a unique name.
	$dir = easyocrInboxDir($entity);
	if (!is_dir($dir) && dol_mkdir($dir) < 0) {
		return $fail('directory', $t('EasyOcrInboxErrCopy'));
	}
	$stored = dol_print_date(dol_now(), '%Y%m%d%H%M%S').'_'.substr(md5(uniqid('', true)), 0, 8).'_'.easyocrInboxSanitizeName($label);
	$dest = $dir.'/'.$stored;
	if (dol_copy($real, $dest, 0, 0) <= 0 || !is_file($dest)) {
		return $fail('copy', $t('EasyOcrInboxErrCopy'));
	}

	$now = dol_now();
	$sql = "INSERT INTO ".MAIN_DB_PREFIX."easyocr_inbox";
	$sql .= " (entity, filename, label, origin, file_hash, file_size, fk_facture_fourn, status, fk_user_creat, datec)";
	$sql .= " VALUES (".$entity.", '".$db->escape($stored)."', '".$db->escape($label)."', '".$db->escape($origin)."',";
	$sql .= " ".($hash !== '' ? "'".$db->escape($hash)."'" : "NULL").", ".$size.", ".($fkInvoice > 0 ? $fkInvoice : "NULL").",";
	$sql .= " ".EASYOCR_INBOX_PENDING.", ".((int) $user->id).", '".$db->idate($now)."')";
	if (!$db->query($sql)) {
		@unlink($dest);
		dol_syslog('EasyOCR inbox: insert failed - '.$db->lasterror(), LOG_ERR);
		return $fail('database', $t('EasyOcrInboxErrCopy'));
	}
	$id = (int) $db->last_insert_id(MAIN_DB_PREFIX.'easyocr_inbox');

	dol_syslog('EasyOCR inbox: received #'.$id.' from '.$origin.' ('.$size.' bytes)', LOG_INFO);

	return array('ok' => true, 'id' => $id, 'url' => easyocrInboxUrl($id), 'error' => '', 'codigo' => 'ok');
}

/**
 * Load one inbox row of the current entity.
 *
 * @param  int $id Row id
 * @return object|null
 */
function easyocrInboxFetch($id)
{
	global $conf, $db;

	$sql = "SELECT i.*, f.ref as invoice_ref, fc.ref as created_ref";
	$sql .= " FROM ".MAIN_DB_PREFIX."easyocr_inbox as i";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture_fourn as f ON f.rowid = i.fk_facture_fourn";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture_fourn as fc ON fc.rowid = i.fk_invoice_created";
	$sql .= " WHERE i.rowid = ".((int) $id)." AND i.entity = ".((int) $conf->entity);
	$resql = $db->query($sql);
	if (!$resql) {
		return null;
	}
	$obj = $db->fetch_object($resql);

	return $obj ? $obj : null;
}

/**
 * Absolute path of the stored file of a row ('' when the name is not trustworthy).
 *
 * @param  object $row Inbox row
 * @return string
 */
function easyocrInboxFilePath($row)
{
	$name = (string) $row->filename;
	if ($name === '' || $name !== basename($name) || strpos($name, '..') !== false) {
		return '';
	}

	return easyocrInboxDir((int) $row->entity).'/'.$name;
}

/**
 * List inbox rows of the current entity.
 *
 * @param  bool $onlyPending Only pending rows
 * @param  int  $limit       Max rows
 * @return array<int,object>
 */
function easyocrInboxList($onlyPending = true, $limit = 500)
{
	global $conf, $db;

	$rows = array();
	$sql = "SELECT i.*, f.ref as invoice_ref, fc.ref as created_ref, u.login, u.firstname, u.lastname";
	$sql .= " FROM ".MAIN_DB_PREFIX."easyocr_inbox as i";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture_fourn as f ON f.rowid = i.fk_facture_fourn";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture_fourn as fc ON fc.rowid = i.fk_invoice_created";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = i.fk_user_creat";
	$sql .= " WHERE i.entity = ".((int) $conf->entity);
	if ($onlyPending) {
		$sql .= " AND i.status = ".EASYOCR_INBOX_PENDING;
	}
	$sql .= " ORDER BY i.datec DESC, i.rowid DESC";
	$sql .= $db->plimit((int) $limit, 0);
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = $obj;
		}
	}

	return $rows;
}

/**
 * Number of pending documents in the current entity (0 when the table is not there yet).
 *
 * @return int
 */
function easyocrInboxCountPending()
{
	global $conf, $db;

	$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."easyocr_inbox";
	$sql .= " WHERE entity = ".((int) $conf->entity)." AND status = ".EASYOCR_INBOX_PENDING;
	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		return (int) $obj->nb;
	}

	return 0;
}

/**
 * Mark pending rows as sent in a batch.
 *
 * @param  int[]  $ids      Row ids
 * @param  string $batchRef Batch id returned by the service
 * @param  User   $user     User
 * @return int              Rows updated
 */
function easyocrInboxMarkBatched(array $ids, $batchRef, $user)
{
	global $conf, $db;

	$ids = array_filter(array_map('intval', $ids));
	if (empty($ids)) {
		return 0;
	}
	$sql = "UPDATE ".MAIN_DB_PREFIX."easyocr_inbox SET status = ".EASYOCR_INBOX_BATCHED;
	$sql .= ", batch_ref = ".($batchRef !== '' ? "'".$db->escape(substr((string) $batchRef, 0, 64))."'" : "NULL");
	$sql .= ", date_processed = '".$db->idate(dol_now())."', fk_user_modif = ".((int) $user->id);
	$sql .= " WHERE entity = ".((int) $conf->entity)." AND status = ".EASYOCR_INBOX_PENDING;
	$sql .= " AND rowid IN (".implode(',', $ids).")";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog('EasyOCR inbox: mark batched failed - '.$db->lasterror(), LOG_ERR);
		return 0;
	}

	return (int) $db->affected_rows($resql);
}

/**
 * Mark a row as processed in the viewer, linked to the invoice it produced.
 *
 * @param  int  $id        Row id
 * @param  int  $invoiceId Supplier invoice created
 * @param  User $user      User
 * @return bool
 */
function easyocrInboxMarkProcessed($id, $invoiceId, $user)
{
	global $conf, $db;

	if ((int) $id <= 0) {
		return false;
	}
	$sql = "UPDATE ".MAIN_DB_PREFIX."easyocr_inbox SET status = ".EASYOCR_INBOX_PROCESSED;
	$sql .= ", fk_invoice_created = ".((int) $invoiceId > 0 ? (int) $invoiceId : "NULL");
	$sql .= ", date_processed = '".$db->idate(dol_now())."', fk_user_modif = ".((int) $user->id);
	$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity)." AND status = ".EASYOCR_INBOX_PENDING;

	return (bool) $db->query($sql);
}

/**
 * Discard a document: deletes its file and its row.
 *
 * @param  object $row Inbox row (from easyocrInboxFetch)
 * @return bool
 */
function easyocrInboxDelete($row)
{
	global $conf, $db;

	if (!is_object($row) || (int) $row->entity !== (int) $conf->entity) {
		return false;
	}
	$file = easyocrInboxFilePath($row);
	if ($file !== '' && is_file($file) && !@unlink($file)) {
		return false;
	}
	$sql = "DELETE FROM ".MAIN_DB_PREFIX."easyocr_inbox WHERE rowid = ".((int) $row->rowid)." AND entity = ".((int) $conf->entity);

	return (bool) $db->query($sql);
}
