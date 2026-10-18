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
 * Registry path helpers (safe before and after Moodle setup).
 *
 * Included from bootstrap.php (pre-setup) and lib.php (plugin load).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Resolve registry directory (MULTITENANCY_REGISTRY_DIR, parent dataroot, or {dataroot}/multitenancy).
 *
 * Prefer the parent-site path captured at bootstrap so admin tools still work when a
 * sticky tenant cookie has temporarily switched $CFG->dataroot.
 *
 * @param object|null $cfg
 * @return string Absolute path or empty string.
 */
if (!function_exists('local_multitenancy_resolve_registry_dir')) {
    /**
     * @param object|null $cfg
     * @return string
     */
    function local_multitenancy_resolve_registry_dir($cfg = null): string {
        if (defined('MULTITENANCY_REGISTRY_DIR') && MULTITENANCY_REGISTRY_DIR) {
            return rtrim((string) MULTITENANCY_REGISTRY_DIR, "/\\\0");
        }

        $dataroot = '';
        if (defined('LOCAL_MULTITENANCY_PARENT_DATAROOT') && LOCAL_MULTITENANCY_PARENT_DATAROOT) {
            $dataroot = (string) LOCAL_MULTITENANCY_PARENT_DATAROOT;
        } else if ($cfg !== null && !empty($cfg->dataroot)) {
            $dataroot = (string) $cfg->dataroot;
        } else if (!empty($GLOBALS['CFG']) && !empty($GLOBALS['CFG']->dataroot)) {
            $dataroot = (string) $GLOBALS['CFG']->dataroot;
        }

        if ($dataroot === '') {
            return '';
        }
        return rtrim($dataroot, "/\\\0") . '/multitenancy';
    }
}
