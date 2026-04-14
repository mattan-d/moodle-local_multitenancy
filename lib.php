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
 * Library hooks for local_multitenancy.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Build current tenant indicator and leave link HTML.
 *
 * @return string
 */
function local_multitenancy_footer_context_html_once(): string {
    static $alreadyrendered = false;
    if ($alreadyrendered) {
     //   return '';
    }
    if (empty($_COOKIE['local_mt_sc'])) {
        return '';
    }
    $code = (string) $_COOKIE['local_mt_sc'];
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
        return '';
    }

    $tenantname = $code;
    if (defined('MULTITENANCY_REGISTRY_DIR') && MULTITENANCY_REGISTRY_DIR) {
        $registryfile = rtrim((string) MULTITENANCY_REGISTRY_DIR, '/\\') . '/registry.php';
        if (is_readable($registryfile)) {
            $map = include $registryfile;
            if (is_array($map)) {
                foreach ($map as $row) {
                    if (is_array($row) && !empty($row['shortcode']) && (string) $row['shortcode'] === $code) {
                        if (!empty($row['name'])) {
                            $tenantname = (string) $row['name'];
                        }
                        break;
                    }
                }
            }
        }
    }

    $context = get_string('footertenantcontext', 'local_multitenancy', (object) [
        'name' => $tenantname,
        'code' => $code,
    ]);
    $leaveurl = new moodle_url('/local/multitenancy/leave.php');
    $leavelink = html_writer::link($leaveurl, get_string('footerleavetenant', 'local_multitenancy'));

    $alreadyrendered = true;
    return html_writer::div(
        html_writer::tag('span', s($context)) . ' ' . $leavelink,
        'local-multitenancy-footer-context',
        ['style' => 'margin-top:8px;font-size:.9rem;']
    );
}

/**
 * Add current tenant indicator and leave link to standard footer HTML.
 *
 * @return string
 */
function local_multitenancy_standard_footer_html(): string {
    return local_multitenancy_footer_context_html_once();
}

/**
 * Legacy fallback for themes/flows that skip standard_footer_html hook.
 *
 * @return void
 */
function local_multitenancy_before_footer(): void {
    echo local_multitenancy_footer_context_html_once();
}
