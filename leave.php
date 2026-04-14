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
 * Clear path-based tenant cookie and return to the parent site (same wwwroot URL).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_multitenancy\gateway_manager;

$secure = function_exists('local_multitenancy_request_is_https') ?
    local_multitenancy_request_is_https() : is_https();

if (PHP_VERSION_ID >= 70300) {
    $opts = [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie(gateway_manager::COOKIE_NAME, '', $opts);
    setcookie(gateway_manager::WWWROOT_COOKIE_NAME, '', $opts);
    if (!empty($CFG->sessioncookie)) {
        setcookie('MoodleSession' . $CFG->sessioncookie, '', $opts);
    }
} else {
    setcookie(gateway_manager::COOKIE_NAME, '', time() - 3600, '/', '', $secure, true);
    setcookie(gateway_manager::WWWROOT_COOKIE_NAME, '', time() - 3600, '/', '', $secure, true);
    if (!empty($CFG->sessioncookie)) {
        setcookie('MoodleSession' . $CFG->sessioncookie, '', time() - 3600, '/', '', $secure, true);
    }
}

redirect(new moodle_url('/'));
