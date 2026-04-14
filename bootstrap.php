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
 * Early bootstrap for config.php (before lib/setup.php).
 *
 * Define MULTITENANCY_REGISTRY_DIR in config.php to the directory that holds
 * registry.php (written by the plugin from the parent site).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Apply tenant overrides to the in-flight $CFG object.
 *
 * Call from config.php after $CFG->dirroot (and default DB settings) are set,
 * and before require_once($CFG->dirroot . '/lib/setup.php').
 *
 * @param stdClass $cfg The global $CFG object being constructed
 * @return void
 */
function local_multitenancy_bootstrap(stdClass $cfg): void {
    if (!defined('MULTITENANCY_REGISTRY_DIR') || !MULTITENANCY_REGISTRY_DIR) {
        return;
    }

    $registryfile = rtrim(MULTITENANCY_REGISTRY_DIR, '/\\') . '/registry.php';
    if (!is_readable($registryfile)) {
        return;
    }

    /** @var array $map */
    $map = include $registryfile;
    if (!is_array($map) || empty($map)) {
        return;
    }

    $tenant = null;

    if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
        $code = getenv('MOODLE_TENANT');
        if (is_string($code) && $code !== '') {
            foreach ($map as $entry) {
                if (!empty($entry['shortcode']) && $entry['shortcode'] === $code) {
                    $tenant = $entry;
                    break;
                }
            }
        }
    } else {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (function_exists('mb_strtolower')) {
            $host = mb_strtolower($host, 'UTF-8');
        } else {
            $host = strtolower($host);
        }
        if ($host !== '' && isset($map[$host])) {
            $tenant = $map[$host];
        }
    }

    if (!$tenant || empty($tenant['enabled'])) {
        return;
    }

    $stringfields = ['wwwroot', 'dataroot', 'dbhost', 'dbname', 'dbuser', 'dbprefix', 'dbtype', 'dblibrary'];
    foreach ($stringfields as $field) {
        if (!empty($tenant[$field])) {
            $cfg->{$field} = $tenant[$field];
        }
    }
    if (array_key_exists('dbpass', $tenant)) {
        $cfg->dbpass = (string) $tenant['dbpass'];
    }
    if (!empty($tenant['dboptions']) && is_array($tenant['dboptions'])) {
        $cfg->dboptions = $tenant['dboptions'];
    }
}
