<?php
/* Copyright (C) 2003      Rodolphe Quiedeville       <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2012 Laurent Destailleur        <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin              <regis.houssin@capnetworks.com>
 * Copyright (C) 2025-2026 EasySoft Tech S.L.         <info@easysoft.es>
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
 * \defgroup   easyocr     Module EasyOcr
 * \brief      EasyOcr module descriptor.
 *
 * \file       htdocs/custom/easyocr/core/modules/modEasyocr.class.php
 * \ingroup    easyocr
 * \brief      Description and activation file for module EasyOcr
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module EasyOcr
 *
 * EasyOcr is a PDF text extraction and invoice processing module for Dolibarr.
 * It allows you to extract text from PDF invoices using native PDF.js text layer
 * and automatically create supplier invoices from the extracted data.
 *
 * @package    EasyOcr
 * @author     EasySoft Tech S.L. <info@easysoft.es>
 * @copyright  2025-2026 EasySoft Tech S.L.
 * @license    GPL-3.0-or-later
 * @version    2.4.4
 */
class modEasyocr extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;
		$langs->load("easyocr@easyocr");

		$this->db = $db;

		// Id for module (must be unique).
		// See https://wiki.dolibarr.org/index.php/List_of_modules_id
		$this->numero = 402020;

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'easyocr';

		// Family can be 'crm','financial','hr','projects','products','ecm','technic','interface','other'
		$this->family = "other";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '50';

		// Module label (no space allowed), used if translation string 'ModuleXXXName' not found
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModuleXXXDesc' not found
		$this->description = "ModuleEasyocrDesc";

		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "ModuleEasyocrDescLong";

		// Author
		$this->editor_name = 'EasySoft Tech S.L.';
		$this->editor_url = 'https://easysoft.es';

		// Possible values for version are: 'development', 'experimental', 'dolibarr' or version
		$this->version = '2.4.4';
		$this->url_last_version = 'https://sdl.easysoft.es/getLastModuleVersion?module=easyocr&for_url_last_version=1&version=' . $this->version;


		// Key used in llx_const table to save module status enabled/disabled
		$this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);

		// Name of image file used for this module.
		$this->picto = 'easyocr@easyocr';

		// Define some features supported by module
		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(
				'/easyocr/css/easyocr.css.php'
			),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		// Data directories to create when module is enabled.
		$this->dirs = array("/easyocr/temp");

		// Config pages
		$this->config_page_url = array("setup.php@easyocr");

		// Dependencies
		$this->hidden = false;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("easyocr@easyocr");

		// Minimum version of PHP required by module
		$this->phpmin = array(7, 4);

		// Minimum version of Dolibarr required by module
		$this->need_dolibarr_version = array(14, 0, 0);

		// Messages at activation
		$this->warnings_activation = array(
			'always' => 'EasyOcrGDPRInformation'
		);
		$this->warnings_activation_ext = array();

		// Constants
		$this->const = array(
			array('EASYOCR_AI_ENABLED', 'chaine', '1', 'Enable AI OCR functionality', 0, 'current', 1),
			array('EASYOCR_AI_URL', 'chaine', 'https://app.easyocr.es', 'AI OCR service URL', 0, 'current', 1),
		);

		if (!isset($conf->easyocr) || !isset($conf->easyocr->enabled)) {
			$conf->easyocr = new stdClass();
			$conf->easyocr->enabled = 0;
		}

		// Array to add new pages in new tabs
		$this->tabs = array();

		// Dictionaries
		$this->dictionaries = array();

		// Boxes/Widgets
		$this->boxes = array();

		// Cronjobs
		$this->cronjobs = array();

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;

		// Permission to read (view templates and invoices)
		$this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'EasyOcrPermRead';
		$this->rights[$r][4] = 'read';
		$r++;

		// Permission to write (create/edit templates and process invoices)
		$this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'EasyOcrPermWrite';
		$this->rights[$r][4] = 'write';
		$r++;

		// Permission to delete (remove templates and invoice records)
		$this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'EasyOcrPermDelete';
		$this->rights[$r][4] = 'delete';
		$r++;

		// Main menu entries
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu'  => '',
			'type'     => 'top',
			'titre'    => 'EasyOcr',
			'mainmenu' => 'easyocr',
			'leftmenu' => '',
			'url'      => '/easyocr/index.php',
			'langs'    => 'easyocr@easyocr',
			'position' => 1000 + $r,
			'enabled'  => '$conf->easyocr->enabled',
			'perms'    => '$user->rights->easyocr->read',
			'target'   => '',
			'user'     => 2,
		);

		/*
		// Menús laterales desactivados — se usa index.php con tarjetas como landing
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=easyocr',
			'type'     => 'left',
			'titre'    => 'EasyOcrMenuUploadPdf',
			'prefix'   => '<img src="' . dol_buildpath('/easyocr/img/uploadpdf.png', 1) . '" width="40px" height="40px">',
			'mainmenu' => 'easyocr',
			'leftmenu' => 'easyocr_tool',
			'url'      => '/easyocr/extract.php',
			'langs'    => 'easyocr@easyocr',
			'position' => 1000 + $r,
			'enabled'  => '$conf->easyocr->enabled',
			'perms'    => '$user->rights->easyocr->write',
			'target'   => '',
			'user'     => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=easyocr',
			'type'     => 'left',
			'titre'    => 'EasyOcrMenuBatch',
			'prefix'   => '<img src="' . dol_buildpath('/easyocr/img/batch.png', 1) . '" width="40px" height="40px">',
			'mainmenu' => 'easyocr',
			'leftmenu' => 'easyocr_batch',
			'url'      => '/easyocr/batch.php',
			'langs'    => 'easyocr@easyocr',
			'position' => 1000 + $r,
			'enabled'  => '$conf->easyocr->enabled',
			'perms'    => '$user->rights->easyocr->write',
			'target'   => '',
			'user'     => 2,
		);

		// Submenú: Historial de lotes
		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=easyocr,fk_leftmenu=easyocr_batch',
			'type'     => 'left',
			'titre'    => 'EasyOcrBatchHistory',
			'mainmenu' => 'easyocr',
			'leftmenu' => 'easyocr_batch_history',
			'url'      => '/easyocr/batch.php?tab=history&frommenu=1',
			'langs'    => 'easyocr@easyocr',
			'position' => 1000 + $r,
			'enabled'  => '$conf->easyocr->enabled',
			'perms'    => '$user->rights->easyocr->write',
			'target'   => '',
			'user'     => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=easyocr',
			'type'     => 'left',
			'titre'    => 'EasyOcrMenuTemplates',
			'prefix'   => '<img src="' . dol_buildpath('/easyocr/img/templates.png', 1) . '" width="40px" height="40px">',
			'mainmenu' => 'easyocr',
			'leftmenu' => 'easyocr_templates',
			'url'      => '/easyocr/templates.php',
			'langs'    => 'easyocr@easyocr',
			'position' => 1000 + $r,
			'enabled'  => '$conf->easyocr->enabled',
			'perms'    => '$user->rights->easyocr->read',
			'target'   => '',
			'user'     => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu'  => 'fk_mainmenu=easyocr',
			'type'     => 'left',
			'titre'    => 'EasyOcrMenuInvoices',
			'prefix'   => '<img src="' . dol_buildpath('/easyocr/img/invoice.png', 1) . '" width="40px" height="40px">',
			'mainmenu' => 'easyocr',
			'leftmenu' => 'easyocr_invoices',
			'url'      => '/easyocr/invoices.php',
			'langs'    => 'easyocr@easyocr',
			'position' => 1000 + $r,
			'enabled'  => '$conf->easyocr->enabled',
			'perms'    => '$user->rights->easyocr->read',
			'target'   => '',
			'user'     => 2,
		);
		*/
	}

	/**
	 * Function called when module is enabled.
	 * The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 * It also creates data directories
	 *
	 * @param  string    $options    Options when enabling module ('', 'noboxes')
	 * @return int                   1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Las tablas se crean automáticamente desde los archivos SQL en /sql/
		$result = $this->_load_tables('/easyocr/sql/');
		if ($result < 0) {
			return -1;
		}

		// Permissions
		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled.
	 * Remove from database constants, boxes and permissions from Dolibarr database.
	 * Data directories are not deleted
	 *
	 * @param  string    $options    Options when enabling module ('', 'noboxes')
	 * @return int                   1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();

		return $this->_remove($sql, $options);
	}
}
