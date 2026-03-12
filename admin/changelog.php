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
 *	    \file       admin/changelog.php
 *		\ingroup    easyocr
 *		\brief      ChangeLog page of easyocr module
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $db, $langs, $user, $conf;

// Libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once __DIR__ . '/../lib/easyocr.lib.php';

// Translations
$langs->loadLangs(array("errors", "admin", "easyocr@easyocr"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');


/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "EasyOcrChangeLog";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = easyocr_admin_prepare_head();
print dol_get_fiche_head($head, 'changelog', $langs->trans("Module402020Name"), 0, 'easyocr@easyocr');

// Get and process ChangeLog file content
$pathoffile = dol_buildpath("/easyocr/ChangeLog.md", 0);

if (file_exists($pathoffile)) {
    $content = file_get_contents($pathoffile);

    // Split content by version headers (lines starting with ##)
    $lines = explode("\n", $content);
    $versions = array();
    $current_version = array(
        'header' => '',
        'content' => ''
    );
    $in_version = false;
    $first_section = ''; // For content before first version

    foreach ($lines as $line) {
        // Detect version header (## [...] or ## Version ...)
        if (preg_match('/^##\s+(.+)$/m', $line, $matches)) {
            // Save previous version if exists
            if ($in_version && !empty($current_version['header'])) {
                $versions[] = $current_version;
            }
            // Start new version
            $current_version = array(
                'header' => trim($matches[1]),
                'content' => ''
            );
            $in_version = true;
        } elseif (preg_match('/^#\s+(.+)$/m', $line) && !$in_version) {
            // Main title (# ChangeLog or similar) - save as first section
            $first_section .= $line . "\n";
        } else {
            // Add content to current section
            if ($in_version) {
                $current_version['content'] .= $line . "\n";
            } else {
                $first_section .= $line . "\n";
            }
        }
    }

    // Save last version
    if ($in_version && !empty($current_version['header'])) {
        $versions[] = $current_version;
    }

    // Convert markdown to HTML if Dolibarr version supports it
    if ((float) DOL_VERSION >= 6.0) {
        require_once DOL_DOCUMENT_ROOT.'/core/lib/parsemd.lib.php';

        $replacearray = array(
            'doc/' => dol_buildpath('easyocr/doc/', 1),
            'img/' => dol_buildpath('easyocr/img/', 1),
            'images/' => dol_buildpath('easyocr/images/', 1),
        );

        // Convert first section
        if (!empty($first_section)) {
            $first_section = dolMd2Html($first_section, 'parsedown', $replacearray);
        }

        // Convert each version content
        foreach ($versions as &$version) {
            $version['content'] = dolMd2Html($version['content'], 'parsedown', $replacearray);
        }
    } else {
        $first_section = nl2br($first_section);
        foreach ($versions as &$version) {
            $version['content'] = nl2br($version['content']);
        }
    }

    // Print first section (title and intro) if exists
    if (!empty(trim($first_section))) {
        print '<div class="changelog-header" style="margin-bottom: 20px;">';
        print $first_section;
        print '</div>';
    }

    // Print versions as collapsible accordions
    if (!empty($versions)) {
        $first = true;
        foreach ($versions as $version) {
            print '<details style="margin-bottom: 15px;" class="changelog-collapsible"';
            // Open first version by default (most recent version)
            if ($first) {
                print ' open';
            }
            print '>';
            print '<summary style="cursor: pointer; font-weight: bold; font-size: 1.1em; padding: 10px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;">';
            print '<span class="collapsible-indicator">' . ($first ? '▼' : '▶') . '</span> ';
            print htmlspecialchars($version['header']);
            print '</summary>';
            print '<div style="padding: 15px; border: 1px solid #ddd; border-top: none; background-color: #fafafa;">';
            print $version['content'];
            print '</div>';
            print '</details>';
            $first = false;
        }

        // Script to animate the collapsible indicator
        print '<script type="text/javascript">';
        print 'document.addEventListener("DOMContentLoaded", function() {';
        print '  var details = document.querySelectorAll(".changelog-collapsible");';
        print '  details.forEach(function(detail) {';
        print '    detail.addEventListener("toggle", function() {';
        print '      var indicator = this.querySelector(".collapsible-indicator");';
        print '      if (this.open) {';
        print '        indicator.textContent = "▼";';
        print '      } else {';
        print '        indicator.textContent = "▶";';
        print '      }';
        print '    });';
        print '  });';
        print '});';
        print '</script>';
    } else {
        // Fallback: print all content if no versions detected
        print '<div class="changelog-content">';
        print $first_section;
        print '</div>';
    }
} else {
    print '<div class="info">';
    print $langs->trans("EasyOcrChangeLogFileNotFound");
    print '</div>';
}

// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
