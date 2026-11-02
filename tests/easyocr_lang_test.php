<?php
/**
 * Language files lint: runs without Dolibarr or a database.
 *
 *   php tests/easyocr_lang_test.php
 *
 * Catches what broke screens before: a literal "%" that sprintf() turns into a
 * ValueError on PHP 8 (HTTP 500), two entries glued on one line (the second key
 * shows raw), keys missing in one locale, and a different number of %s between
 * locales (the translated text then drops or misplaces a value).
 */

if (php_sapi_name() !== 'cli') {
	die('This script is CLI-only.');
}

$pass = 0;
$fail = 0;
function lt_fail($msg)
{
	global $fail;
	$fail++;
	echo "  \033[31mFAIL\033[0m  " . $msg . "\n";
}

$locales = array('es_ES', 'en_US', 'ca_ES', 'de_DE', 'fr_FR', 'gl_ES', 'it_IT', 'pt_PT');
$dir = __DIR__ . '/../langs';
$all = array();

foreach ($locales as $loc) {
	$file = $dir . '/' . $loc . '/easyocr.lang';
	if (!is_readable($file)) {
		lt_fail($loc . ': easyocr.lang missing');
		continue;
	}
	$raw = file_get_contents($file);
	if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
		lt_fail($loc . ': UTF-8 BOM');
	}
	if (!preg_match('//u', $raw)) {
		lt_fail($loc . ': not valid UTF-8');
	}
	$keys = array();
	foreach (preg_split('/\r\n|\n/', $raw) as $n => $line) {
		$line = rtrim($line, "\r");
		if ($line === '' || $line[0] === '#') {
			continue;
		}
		if (!preg_match('/^([A-Za-z0-9_.\-]+)\s*=\s?(.*)$/', $line, $m)) {
			lt_fail($loc . ':' . ($n + 1) . ' not a "Key = value" line');
			continue;
		}
		list(, $key, $val) = $m;
		// Two entries on one line ("...foundEasyOcrPermRead = ...")
		if (preg_match('/[^\s]EasyOcr[A-Za-z0-9_]+ = /', $val)) {
			lt_fail($loc . ':' . ($n + 1) . ' ' . $key . ' has another entry glued to it');
		}
		// A "%" that is neither "%%" nor a conversion: ValueError in sprintf on PHP 8
		$probe = preg_replace('/%%|%(\d+\$)?[-+ 0]*\d*(\.\d+)?[sdfuxXcbeEgGo]/', '', $val);
		if (strpos($probe, '%') !== false) {
			lt_fail($loc . ':' . ($n + 1) . ' ' . $key . ' has an unescaped % (use %%)');
		}
		if (isset($keys[$key]) && $keys[$key] !== $val) {
			lt_fail($loc . ': ' . $key . ' defined twice with different texts');
		}
		$keys[$key] = $val;
		$pass++;
	}
	$all[$loc] = $keys;
}

// Same keys and same placeholders in every locale, the Spanish file as reference
$ref = isset($all['es_ES']) ? $all['es_ES'] : array();
$count = function ($v) {
	return preg_match_all('/%(\d+\$)?[-+ 0]*\d*(\.\d+)?[sdf]/', str_replace('%%', '', $v));
};
foreach ($all as $loc => $keys) {
	foreach ($ref as $k => $v) {
		if (!array_key_exists($k, $keys)) {
			lt_fail($loc . ': missing ' . $k);
		} elseif ($count($v) !== $count($keys[$k])) {
			lt_fail($loc . ': ' . $k . ' has ' . $count($keys[$k]) . ' placeholders, es_ES has ' . $count($v));
		}
	}
	foreach ($keys as $k => $v) {
		if (!array_key_exists($k, $ref)) {
			lt_fail($loc . ': ' . $k . ' is not in es_ES');
		}
	}
}

// Every module key used in the code exists (core keys are not checked here)
$used = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
	$p = str_replace('\\', '/', $f->getPathname());
	if (!preg_match('/\.(php|js)$/', $p) || preg_match('#/(vendor|tests|dev|\.git)/#', $p)) {
		continue;
	}
	if (preg_match_all('/trans(?:noentities)?\(\s*[\'"](EasyOcr[A-Za-z0-9_]+)[\'"]/', file_get_contents($p), $mm)) {
		foreach ($mm[1] as $k) {
			$used[$k] = basename($p);
		}
	}
}
foreach ($used as $k => $where) {
	if (!array_key_exists($k, $ref)) {
		lt_fail('es_ES: ' . $k . ' used in ' . $where . ' but not defined');
	}
}

echo "\n" . str_repeat('─', 52) . "\n";
if ($fail === 0) {
	echo "\033[32mAll " . $pass . " language entries passed.\033[0m\n";
	exit(0);
}
echo "\033[31m" . $fail . " problem(s).\033[0m\n";
exit(1);
