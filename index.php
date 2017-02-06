<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Are you Ok? heartbeat for load balancers
 *
 * @package    tool_heartbeat
 * @copyright  2014 Brendan Heywood <brendan@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Make sure varnish doesn't cache this. But it still might so go check it!
header('Pragma: no-cache');
header('Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate, proxy-revalidate');
header('Expires: Tue, 04 Sep 2012 05:32:29 GMT');

// Set this manually to true as needed.
if (false) {
    print "Server is in MAINTENANCE";
    exit;
}


$fullcheck = false;

if (isset($argv) && $argv[0]) {
    define('CLI_SCRIPT', true);
    $fullcheck = count($argv) > 1 && $argv[1] === 'fullcheck';
} else {
    define('NO_MOODLE_COOKIES', true);
    $fullcheck = isset($_GET['fullcheck']);
}
define('NO_UPGRADE_CHECK', true);
define('ABORT_AFTER_CONFIG', true);

$configfile = '../../../config.php';

/**
 * Checks if the command line maintenance mode has been enabled. Skip the config bootstrapping.
 *
 * @param string $configfile The relative path for config.php
 * @return bool|string False if not found, or the $CFG->dataroot path if found.
 */
function checkclimaintenance($configfile) {
    $content = file_get_contents($configfile);
    $content = preg_replace("/\/\//", "\n//", $content);  // Set comments to be on newlines, replace '//' with '\n//'
    $content = preg_replace("/;/", ";\n", $content);      // Split up statements, replace ';' with ';\n'
    $content = preg_replace("/^[\s]+/m", "", $content);   // Removes all initial whitespace and newlines.

    $lines = explode(PHP_EOL, $content);
    foreach ($lines as $line) {
        $line = trim($line);
        $re = '/^\$CFG->dataroot\s+=\s+["\'](.*)["\'];/';  // Lines starting with $CFG->dataroot
        preg_match($re, $line, $matches);
        if (!empty($matches) && count($matches) == 2) {
            $climaintenance = $matches[1] . '/climaintenance.html';

            if (file_exists($climaintenance)) {
                return $matches[1];
            }
        }
    }
    return false;
}

$climaintenaceon = checkclimaintenance($configfile);
if ($climaintenaceon === false) {
    require_once('../../../config.php');
    global $CFG;
} else {
    $CFG = new stdClass();
    $CFG->dataroot = $climaintenaceon;
    // Cannot fullcheck with cli maintenance enabled.
    $fullcheck = false;
}

$status = "";

/**
 * Return an error that ELB will pick up
 *
 * @param string $reason
 */
function failed($reason) {
    // Status for ELB, will cause ELB to remove instance.
    header("HTTP/1.0 503 Service unavailable: failed $reason check");
    // Status for the humans.
    print "Server is DOWN<br>\n";
    echo "Failed: $reason";
    exit;
}

$testfile = $CFG->dataroot . "/tool_heartbeat.test";
$size = file_put_contents($testfile, '1');
if ($size !== 1) {
    failed('sitedata not writable');
}

if (file_exists($testfile)) {
    $status .= "sitedata OK<br>\n";
} else {
    failed('sitedata not readable');
}


$sessionhandler = (property_exists($CFG, 'session_handler_class') && $CFG->session_handler_class == '\core\session\memcached');

if ($sessionhandler) {

    $memcache = explode(':', $CFG->session_memcached_save_path );
    try {
        memcache_connect($memcache[0], $memcache[1], 3);
        $status .= "session memcache OK<br>\n";
    } catch (Exception $e) {
        failed('sessions memcache');
    }
}

// Optionally check database configuration and access (slower).
if ($fullcheck) {
    try {
        define('ABORT_AFTER_CONFIG_CANCEL', true);
        require($CFG->dirroot . '/lib/setup.php');
        global $DB;

        // Try to get the first record from the user table.
        $user = $DB->get_record_sql('SELECT id FROM {user} WHERE 0 < id ', null, IGNORE_MULTIPLE);
        if ($user) {
            $status .= "database OK<br>\n";
        } else {
            failed('no users in database');
        }
    } catch (Exception $e) {
        failed('database error');
    }
}

if ($climaintenaceon) {
    print "Server is in MAINTENANCE<br>\n";
} else {
    print "Server is ALIVE<br>\n";
}
print $status;

