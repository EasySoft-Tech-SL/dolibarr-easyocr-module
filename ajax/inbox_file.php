<?php
/* Copyright (C) 2026 EasySoft Tech S.L.         <info@easysoft.es>
 *                    Alberto Luque Rivas        <aluquerivasdev@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       ajax/inbox_file.php
 * \ingroup    easyocr
 * \brief      Serves the PDF of a received document (inbox) to the user of its entity.
 *
 * Used by inbox.php (preview and download), by the viewer (extract.php?inbox=N) and by
 * batch.php (?inbox[]=N) to load the file in the browser. Read only: nothing is changed and
 * nothing is sent to the AI service.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once __DIR__.'/../lib/easyocr.lib.php';

if (empty($conf->easyocr->enabled) || !easyocrCheckRight($user, 'easyocr', 'read')) {
	http_response_code(403);
	exit;
}

$row = easyocrInboxFetch(GETPOST('id', 'int'));
$file = $row ? easyocrInboxFilePath($row) : '';
if ($file === '' || !is_file($file)) {
	http_response_code(404);
	exit;
}

$name = easyocrInboxSanitizeName($row->label);
$disposition = GETPOST('download', 'int') ? 'attachment' : 'inline';

header('Content-Type: application/pdf');
header('Content-Length: '.filesize($file));
header('Content-Disposition: '.$disposition.'; filename="'.$name.'"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($file);
