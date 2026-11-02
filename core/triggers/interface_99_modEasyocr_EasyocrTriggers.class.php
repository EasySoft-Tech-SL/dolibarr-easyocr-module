<?php
/* Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                         Alberto Luque Rivas        <aluquerivasdev@gmail.com>
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
 * \file       core/triggers/interface_99_modEasyocr_EasyocrTriggers.class.php
 * \ingroup    easyocr
 * \brief      Keeps EasyOcr's own data in step with supplier invoices and third parties
 *
 * - BILL_SUPPLIER_DELETE: what EasyOcr kept about the invoice goes with it (GDPR). The
 *   webhook log loses the payload and the references, the anti-duplicate fingerprint is
 *   removed (the same file can be read again) and the inbox copy of the document, once
 *   processed into that invoice, is deleted.
 * - COMPANY_DELETE: templates of that supplier are deleted and the log forgets it.
 * - COMPANY_MODIFY coming from a merge: templates and log move to the third party kept.
 * - COMPANY_CREATE / COMPANY_MODIFY / COMPANY_DELETE: search keys of the third party.
 *
 * Never breaks the operation of the core: errors go to the log and the trigger returns 0.
 * No transaction is opened or rolled back here; the statements run inside the caller's.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Triggers of module EasyOcr
 */
class InterfaceEasyocrTriggers extends DolibarrTriggers
{
	/**
	 * @var array<string,bool> Existing EasyOcr tables, loaded once per request
	 */
	private static $tables = null;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "other";
		$this->description = "EasyOcr triggers (GDPR clean-up and supplier search keys).";
		$this->version = 'dolibarr';
		$this->picto = 'easyocr@easyocr';
	}

	/**
	 * Trigger name
	 *
	 * @return string
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Trigger description
	 *
	 * @return string
	 */
	public function getDesc()
	{
		return $this->description;
	}

	/**
	 * Function called on Dolibarr business events.
	 *
	 * @param string       $action Event action code
	 * @param CommonObject $object Object
	 * @param User         $user   Object user
	 * @param Translate    $langs  Object langs
	 * @param Conf         $conf   Object conf
	 * @return int                 0 always: EasyOcr never blocks the core
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (empty($conf->easyocr) || empty($conf->easyocr->enabled)) {
			return 0;
		}
		if (!in_array($action, array('BILL_SUPPLIER_DELETE', 'COMPANY_CREATE', 'COMPANY_MODIFY', 'COMPANY_DELETE'), true)) {
			return 0;
		}
		$id = (is_object($object) && !empty($object->id)) ? (int) $object->id : 0;
		if ($id <= 0) {
			return 0;
		}

		try {
			if ($action === 'BILL_SUPPLIER_DELETE') {
				$this->forgetSupplierInvoice($id);
				return 0;
			}

			if ($action === 'COMPANY_DELETE') {
				$this->forgetThirdparty($id);
			} elseif ($action === 'COMPANY_MODIFY' && !empty($object->context['merge']) && !empty($object->context['mergefromid'])) {
				$this->moveThirdparty((int) $object->context['mergefromid'], $id);
			}

			$this->refreshSupplierKeys($action, $id);
		} catch (Throwable $e) {
			dol_syslog('EasyOcr trigger '.$action.' id='.$id.': '.$e->getMessage(), LOG_ERR);
		}

		return 0;
	}

	/**
	 * Remove or anonymise what EasyOcr kept about a supplier invoice being deleted.
	 *
	 * @param  int $invoiceId Supplier invoice id
	 * @return int            Number of statements that failed
	 */
	public function forgetSupplierInvoice($invoiceId)
	{
		$invoiceId = (int) $invoiceId;
		$errors = 0;

		// Webhook log: the row stays for the batch statistics, without personal data
		if ($this->hasTable('easyocr_webhook_log')) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."easyocr_webhook_log SET payload = NULL, invoice_id = NULL, invoice_ref = NULL,";
			$sql .= " document_filename = NULL, processing_message = NULL";
			$sql .= " WHERE invoice_id = ".$invoiceId;
			$errors += $this->run($sql);
		}

		// Anti-duplicate fingerprint: name of the file and link to the invoice
		if ($this->hasTable('easyocr_processed_files')) {
			$errors += $this->run("DELETE FROM ".MAIN_DB_PREFIX."easyocr_processed_files WHERE fk_facture_fourn = ".$invoiceId);
		}

		// Legacy table, linked to the documents of the invoice
		if ($this->hasTable('easyocr_invoices')) {
			$sql = "DELETE FROM ".MAIN_DB_PREFIX."easyocr_invoices WHERE fk_file IN (";
			$sql .= "SELECT e.rowid FROM ".MAIN_DB_PREFIX."ecm_files as e";
			$sql .= " WHERE e.src_object_type IN ('facture_fourn', 'invoice_supplier') AND e.src_object_id = ".$invoiceId.")";
			$errors += $this->run($sql);
		}

		if ($this->hasTable('easyocr_inbox')) {
			// Documents already turned into this invoice: the inbox copy and its row go
			$sql = "SELECT rowid, entity, filename FROM ".MAIN_DB_PREFIX."easyocr_inbox WHERE fk_invoice_created = ".$invoiceId;
			$resql = $this->db->query($sql);
			if ($resql) {
				$rows = array();
				while ($obj = $this->db->fetch_object($resql)) {
					$rows[] = $obj;
				}
				$this->db->free($resql);
				if (!empty($rows)) {
					dol_include_once('/easyocr/lib/easyocr_inbox.lib.php');
				}
				foreach ($rows as $row) {
					$file = function_exists('easyocrInboxFilePath') ? easyocrInboxFilePath($row) : '';
					if ($file !== '' && is_file($file) && !@unlink($file)) {
						dol_syslog('EasyOcr trigger: could not delete inbox file '.$file, LOG_WARNING);
					}
					$errors += $this->run("DELETE FROM ".MAIN_DB_PREFIX."easyocr_inbox WHERE rowid = ".((int) $row->rowid));
				}
			} else {
				$errors++;
				dol_syslog('EasyOcr trigger: '.$this->db->lasterror(), LOG_ERR);
			}
			// Documents waiting to be attached to this invoice stay, unattached
			$errors += $this->run("UPDATE ".MAIN_DB_PREFIX."easyocr_inbox SET fk_facture_fourn = NULL WHERE fk_facture_fourn = ".$invoiceId);
		}

		return $errors;
	}

	/**
	 * Remove what EasyOcr kept about a third party being deleted.
	 *
	 * @param  int $socid Third party id
	 * @return int        Number of statements that failed
	 */
	public function forgetThirdparty($socid)
	{
		$socid = (int) $socid;
		$errors = 0;

		if ($this->hasTable('easyocr_template')) {
			if ($this->hasTable('easyocr_template_details')) {
				$sql = "DELETE FROM ".MAIN_DB_PREFIX."easyocr_template_details WHERE fk_template IN (";
				$sql .= "SELECT t.rowid FROM ".MAIN_DB_PREFIX."easyocr_template as t WHERE t.fk_soc = ".$socid.")";
				$errors += $this->run($sql);
			}
			$errors += $this->run("DELETE FROM ".MAIN_DB_PREFIX."easyocr_template WHERE fk_soc = ".$socid);
		}
		if ($this->hasTable('easyocr_webhook_log')) {
			$errors += $this->run("UPDATE ".MAIN_DB_PREFIX."easyocr_webhook_log SET supplier_id = NULL WHERE supplier_id = ".$socid);
		}

		return $errors;
	}

	/**
	 * Move EasyOcr data from a merged third party to the one kept.
	 *
	 * Dolibarr deletes the merged one right after (COMPANY_DELETE): without this, its
	 * templates would be lost instead of following the supplier.
	 *
	 * @param  int $fromId Third party merged (about to be deleted)
	 * @param  int $toId   Third party kept
	 * @return int         Number of statements that failed
	 */
	public function moveThirdparty($fromId, $toId)
	{
		$fromId = (int) $fromId;
		$toId = (int) $toId;
		if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
			return 0;
		}
		$errors = 0;
		if ($this->hasTable('easyocr_template')) {
			$errors += $this->run("UPDATE ".MAIN_DB_PREFIX."easyocr_template SET fk_soc = ".$toId." WHERE fk_soc = ".$fromId);
		}
		if ($this->hasTable('easyocr_webhook_log')) {
			$errors += $this->run("UPDATE ".MAIN_DB_PREFIX."easyocr_webhook_log SET supplier_id = ".$toId." WHERE supplier_id = ".$fromId);
		}

		return $errors;
	}

	/**
	 * Keep the search keys of the third party up to date (functions live in the lib).
	 *
	 * @param  string $action Event code
	 * @param  int    $socid  Third party id
	 * @return void
	 */
	private function refreshSupplierKeys($action, $socid)
	{
		dol_include_once('/easyocr/lib/easyocr.lib.php');
		if ($action === 'COMPANY_DELETE') {
			if (function_exists('easyocrSupplierKeyDelete')) {
				easyocrSupplierKeyDelete($this->db, $socid);
			}
		} elseif (function_exists('easyocrSupplierKeyRefresh')) {
			easyocrSupplierKeyRefresh($this->db, $socid);
		}
	}

	/**
	 * Run one statement; a failure is logged, never raised.
	 *
	 * @param  string $sql SQL
	 * @return int         0 when OK, 1 when it failed
	 */
	private function run($sql)
	{
		if ($this->db->query($sql)) {
			return 0;
		}
		dol_syslog('EasyOcr trigger: '.$this->db->lasterror().' - '.$sql, LOG_ERR);

		return 1;
	}

	/**
	 * Whether one EasyOcr table exists (an installation updated by files may lack some).
	 *
	 * On PostgreSQL a failed statement aborts the caller's whole transaction, so a missing
	 * table must be detected before querying it, not by its error.
	 *
	 * @param  string $name Table name without prefix
	 * @return bool
	 */
	private function hasTable($name)
	{
		if (self::$tables === null) {
			self::$tables = array();
			$list = $this->db->DDLListTables($this->db->database_name, MAIN_DB_PREFIX.'easyocr%');
			foreach ((array) $list as $t) {
				self::$tables[strtolower((string) $t)] = true;
			}
		}

		return !empty(self::$tables[strtolower(MAIN_DB_PREFIX.$name)]);
	}
}
