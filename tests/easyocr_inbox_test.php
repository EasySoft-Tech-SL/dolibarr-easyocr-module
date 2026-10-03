<?php
/**
 * EasyOcr — received documents (inbox) against a real Dolibarr.
 *
 * Exercises easyocr_recibir_documento(), the entry point other modules (EasyScan) use to
 * hand a PDF over. Never calls the AI service: the function under test does not talk to
 * the network, and this script does not either.
 *
 * Creates its own files and rows, all named with the prefix qa_easyscan_ocrq_, and deletes
 * them at the end by that exact prefix (never by a rowid range).
 *
 * Run:  php tests/easyocr_inbox_test.php
 */

if (php_sapi_name() !== 'cli') {
	die('This script is CLI-only.');
}

$confFile = __DIR__.'/../../../conf/conf.php';
if (is_readable($confFile)) {
	include $confFile;
	$dbHost = !empty($dolibarr_main_db_host) ? $dolibarr_main_db_host : '127.0.0.1';
	$dbPort = !empty($dolibarr_main_db_port) ? (int) $dolibarr_main_db_port : 3306;
	$probe = @fsockopen($dbHost === 'localhost' ? '127.0.0.1' : $dbHost, $dbPort, $errno, $errstr, 3);
	if ($probe === false) {
		fwrite(STDERR, "Database unreachable at ".$dbHost.':'.$dbPort." — ".$errstr."\n");
		exit(1);
	}
	fclose($probe);
}

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__.'/../../..');
if (!defined('NOSESSION')) define('NOSESSION', '1');
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
if (!defined('NOLOGIN')) define('NOLOGIN', '1');
if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', '1');

require_once __DIR__.'/../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
// The same include EasyScan does (it loads the inbox library and repairs the schema).
dol_include_once('/easyocr/lib/easyocr.lib.php');

$GLOBALS['eo_pass'] = 0;
$GLOBALS['eo_fail'] = 0;
function eo_group($name)
{
	echo "\n\033[1m".$name."\033[0m\n";
}
function eo_assert($name, $actual, $expected)
{
	if ($actual === $expected) {
		$GLOBALS['eo_pass']++;
		echo "  \033[32mPASS\033[0m  ".$name."\n";
		return true;
	}
	$GLOBALS['eo_fail']++;
	echo "  \033[31mFAIL\033[0m  ".$name."\n        expected: ".var_export($expected, true)."\n        actual:   ".var_export($actual, true)."\n";
	return false;
}
function eo_info($msg)
{
	echo "        \033[2m".$msg."\033[0m\n";
}

const QA = 'qa_easyscan_ocrq_';

/** Minimal valid PDF (one empty page). */
function eo_pdf_bytes($tag)
{
	return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
		."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n% ".$tag."\ntrailer<</Root 1 0 R>>\n%%EOF\n";
}

eo_group('environment');
eo_assert('easyocr enabled', !empty($conf->easyocr->enabled), true);
eo_assert('public function exists', function_exists('easyocr_recibir_documento'), true);
$resT = $db->query("SHOW TABLES LIKE '".MAIN_DB_PREFIX."easyocr_inbox'");
eo_assert('table llx_easyocr_inbox exists (schema repair)', $resT && $db->num_rows($resT) === 1, true);
eo_assert('schema revision recorded', (string) getDolGlobalString('EASYOCR_SCHEMA_REVISION'), (string) EASYOCR_SCHEMA_REVISION);
eo_info('entity='.$conf->entity.' DOL_DATA_ROOT='.DOL_DATA_ROOT.' inbox='.easyocrInboxDir());

$res = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE admin = 1 AND statut = 1 ORDER BY rowid ASC LIMIT 1");
$user = new User($db);
$user->fetch($db->fetch_object($res)->rowid);
$user->getrights();
if (!easyocrCheckRight($user, 'easyocr', 'write')) {
	// The admin does not inherit module rights; give them in memory only, for this run.
	if (empty($user->rights->easyocr)) {
		$user->rights->easyocr = new stdClass();
	}
	$user->rights->easyocr->read = 1;
	$user->rights->easyocr->write = 1;
	eo_info('easyocr write right granted in memory to '.$user->login);
}
eo_assert('test user can write in easyocr', (bool) easyocrCheckRight($user, 'easyocr', 'write'), true);

// Source files inside DOL_DATA_ROOT (where EasyScan keeps its tray)
$srcDir = DOL_DATA_ROOT.($conf->entity > 1 ? '/'.$conf->entity : '').'/easyocr/'.QA.'src';
dol_mkdir($srcDir);
$valid = $srcDir.'/'.QA.'valida.pdf';
file_put_contents($valid, eo_pdf_bytes('valid-'.uniqid()));
$fake = $srcDir.'/'.QA.'falsa.pdf';
file_put_contents($fake, "Esto no es un PDF, aunque se llame .pdf\n");
$outside = rtrim(sys_get_temp_dir(), '/\\').'/'.QA.'fuera.pdf';
file_put_contents($outside, eo_pdf_bytes('outside'));
$created = array();

eo_group('receive a valid PDF');
$r = easyocr_recibir_documento($valid, $user, array('origen' => 'easyscan', 'nombre' => QA.'valida.pdf', 'fk_facture_fourn' => 999999999));
eo_assert('ok', $r['ok'], true);
eo_assert('id > 0', $r['id'] > 0, true);
eo_assert('url points at inbox.php', strpos($r['url'], '/easyocr/inbox.php?id='.$r['id']) !== false, true);
eo_assert('error empty', $r['error'], '');
$created[] = $r['id'];
$row = easyocrInboxFetch($r['id']);
eo_assert('row stored in this entity', $row && (int) $row->entity === (int) $conf->entity, true);
eo_assert('origin stored', $row->origin, 'easyscan');
eo_assert('visible name stored', $row->label, QA.'valida.pdf');
eo_assert('status pending', (int) $row->status, EASYOCR_INBOX_PENDING);
eo_assert('non-existing supplier invoice not linked', $row->fk_facture_fourn, null);
eo_assert('file copied to the inbox', is_file(easyocrInboxFilePath($row)), true);
eo_assert('copy is byte-identical', hash_file('sha256', easyocrInboxFilePath($row)), hash_file('sha256', $valid));
eo_assert('source left in place', is_file($valid), true);
eo_assert('pending counter sees it', easyocrInboxCountPending() >= 1, true);

eo_group('same PDF again');
$r2 = easyocr_recibir_documento($valid, $user, array('origen' => 'easyscan', 'nombre' => QA.'valida.pdf'));
eo_assert('ok', $r2['ok'], true);
eo_assert('same row, not queued twice', $r2['id'], $r['id']);
eo_assert('flagged as duplicate', !empty($r2['duplicado']), true);

eo_group('reject a file that is not a PDF');
$r = easyocr_recibir_documento($fake, $user, array('origen' => 'easyscan', 'nombre' => QA.'falsa.pdf'));
eo_assert('ok false', $r['ok'], false);
eo_assert('reason notpdf', $r['codigo'], 'notpdf');
eo_assert('error text', $r['error'] !== '', true);
eo_info($r['error']);

eo_group('reject a path outside DOL_DATA_ROOT');
$r = easyocr_recibir_documento($outside, $user, array('origen' => 'easyscan', 'nombre' => QA.'fuera.pdf'));
eo_assert('ok false', $r['ok'], false);
eo_assert('reason path', $r['codigo'], 'path');
eo_info($r['error']);
$r = easyocr_recibir_documento(DOL_DATA_ROOT.'/../'.basename(DOL_DATA_ROOT).'/../conf.php', $user, array('nombre' => QA.'trav.pdf'));
eo_assert('traversal with .. refused', $r['codigo'], 'path');
$r = easyocr_recibir_documento($srcDir.'/'.QA.'no-existe.pdf', $user, array('nombre' => QA.'x.pdf'));
eo_assert('missing file refused', $r['codigo'], 'path');

eo_group('reject a user without permission');
$noperm = clone $user;
$noperm->rights = new stdClass();
$noperm->rights->easyocr = new stdClass();
$noperm->rights->easyocr->read = 1;
$noperm->rights->easyocr->write = 0;
$r = easyocr_recibir_documento($valid, $noperm, array('origen' => 'easyscan', 'nombre' => QA.'valida.pdf'));
eo_assert('ok false', $r['ok'], false);
eo_assert('reason permission', $r['codigo'], 'permission');
eo_info($r['error']);

eo_group('reject a file in another entity');
$otherEntity = ((int) $conf->entity === 2) ? 3 : 2;
$otherDir = DOL_DATA_ROOT.'/'.$otherEntity.'/easyocr/'.QA.'src';
dol_mkdir($otherDir);
$otherFile = $otherDir.'/'.QA.'otra.pdf';
file_put_contents($otherFile, eo_pdf_bytes('other'));
$r = easyocr_recibir_documento($otherFile, $user, array('nombre' => QA.'otra.pdf'));
eo_assert('reason entity', $r['codigo'], 'entity');
@unlink($otherFile);
@rmdir($otherDir);
@rmdir(dirname($otherDir));
@rmdir(dirname(dirname($otherDir)));

eo_group('reject a file above the size limit');
$big = $srcDir.'/'.QA.'grande.pdf';
file_put_contents($big, eo_pdf_bytes('big').str_repeat('0', 1100 * 1024));
$conf->global->EASYOCR_INBOX_MAX_SIZE_MB = 1;
$r = easyocr_recibir_documento($big, $user, array('nombre' => QA.'grande.pdf'));
unset($conf->global->EASYOCR_INBOX_MAX_SIZE_MB);
eo_assert('reason size', $r['codigo'], 'size');
eo_info($r['error']);

eo_group('name sanitised');
$named = $srcDir.'/'.QA.'nombre.pdf';
file_put_contents($named, eo_pdf_bytes('named-'.uniqid()));
$r = easyocr_recibir_documento($named, $user, array('origen' => 'Easy Scan!', 'nombre' => QA.'<b>x</b>/../../evil name.pdf'));
eo_assert('ok', $r['ok'], true);
$created[] = $r['id'];
$row = easyocrInboxFetch($r['id']);
eo_assert('no HTML in the label', strpos($row->label, '<') === false, true);
eo_assert('stored file name has no path', $row->filename === basename($row->filename) && strpos($row->filename, '..') === false, true);
eo_assert('stored file name is plain ASCII .pdf', (bool) preg_match('/^[A-Za-z0-9._-]+\.pdf$/', $row->filename), true);
eo_assert('origin code sanitised', $row->origin, 'easyscan');
eo_info('label='.$row->label.' file='.$row->filename);

eo_group('state changes used by batch and viewer');
eo_assert('mark batched', easyocrInboxMarkBatched(array($created[0]), 'qa-batch-uuid', $user), 1);
$row = easyocrInboxFetch($created[0]);
eo_assert('status batched + ref', (int) $row->status === EASYOCR_INBOX_BATCHED && $row->batch_ref === 'qa-batch-uuid', true);
eo_assert('a batched row is not marked again', easyocrInboxMarkBatched(array($created[0]), 'other', $user), 0);
eo_assert('mark processed', easyocrInboxMarkProcessed($created[1], 0, $user), true);
eo_assert('status processed', (int) easyocrInboxFetch($created[1])->status, EASYOCR_INBOX_PROCESSED);

eo_group('cleanup (by exact prefix '.QA.')');
$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."easyocr_inbox WHERE entity = ".((int) $conf->entity)." AND label LIKE '".$db->escape(QA)."%'";
$resC = $db->query($sql);
$deleted = 0;
while ($resC && ($o = $db->fetch_object($resC))) {
	if (easyocrInboxDelete(easyocrInboxFetch($o->rowid))) {
		$deleted++;
	}
}
eo_assert('every test row deleted', $deleted, count(array_unique($created)));
foreach (array($valid, $fake, $outside, $big, $named) as $f) {
	@unlink($f);
}
@rmdir($srcDir);
$resC = $db->query($sql);
eo_assert('no test row left', $resC ? $db->num_rows($resC) : -1, 0);

echo "\n".($GLOBALS['eo_fail'] ? "\033[31m" : "\033[32m").$GLOBALS['eo_pass']." passed, ".$GLOBALS['eo_fail']." failed\033[0m\n";
exit($GLOBALS['eo_fail'] ? 1 : 0);
