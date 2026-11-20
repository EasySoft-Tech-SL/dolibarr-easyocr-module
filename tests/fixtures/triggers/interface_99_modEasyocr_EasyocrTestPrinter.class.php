<?php
/**
 * Test fixture (not shipped: tests/ is left out of the release ZIP).
 *
 * A trigger that prints on COMPANY_CREATE the way a third-party module did on a real
 * install: "print var_dump($res);" once per default password group. Dolibarr only loads
 * it when a test adds this folder to $conf->modules_parts['triggers'], and it only prints
 * while $GLOBALS['easyocr_test_print_on_company_create'] is set.
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

class InterfaceEasyocrTestPrinter extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = 'EasyocrTestPrinter';
		$this->family = 'other';
		$this->description = 'EasyOcr test fixture: prints on COMPANY_CREATE';
		$this->version = 'development';
		$this->picto = 'generic';
	}

	/**
	 * @param string    $action Event code
	 * @param object    $object Object concerned
	 * @param User      $user   User
	 * @param Translate $langs  Translations
	 * @param Conf      $conf   Configuration
	 * @return int
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action !== 'COMPANY_CREATE' || empty($GLOBALS['easyocr_test_print_on_company_create'])) {
			return 0;
		}
		$GLOBALS['easyocr_test_printer_ran'] = (isset($GLOBALS['easyocr_test_printer_ran']) ? $GLOBALS['easyocr_test_printer_ran'] : 0) + 1;
		foreach (array(1, 2, 3, 4, 5, 6) as $res) {
			print var_dump($res);
		}
		return 0;
	}
}
