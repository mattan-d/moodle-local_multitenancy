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
 * Shared wwwroot helpers (safe before and after Moodle setup).
 *
 * Included from bootstrap.php (pre-setup) and lib.php (plugin load).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Strip /local/multitenancy/users/{code} from a stored tenant wwwroot so $CFG->wwwroot is the real Moodle site base.
 * Tenants may store the full gateway URL; this returns the derived public base.
 *
 * @param string $url
 * @return string
 */
if (!function_exists('local_multitenancy_normalize_public_wwwroot')) {
    /**
     * @param string $url
     * @return string
     */
    function local_multitenancy_normalize_public_wwwroot(string $url): string {
        $url = rtrim(trim($url), '/');
        if ($url === '' || !preg_match('#\Ahttps?://#iu', $url)) {
            return $url;
        }
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }
        $path = isset($parts['path']) ? $parts['path'] : '';
        $newpath = preg_replace('#/local/multitenancy/users/[a-zA-Z0-9_-]+$#', '', $path);
        if ($newpath === $path) {
            return $url;
        }
        $newpath = rtrim($newpath, '/');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return rtrim($parts['scheme'] . '://' . $parts['host'] . $port . ($newpath === '' ? '' : $newpath), '/');
    }
}
