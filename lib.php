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
 * Return plugin string with safe fallback.
 *
 * @param string $identifier
 * @param string $fallback
 * @return string
 */
function local_multitenancy_string_or_default(string $identifier, string $fallback): string {
    $manager = get_string_manager();
    if ($manager->string_exists($identifier, 'local_multitenancy')) {
        return get_string($identifier, 'local_multitenancy');
    }
    return $fallback;
}

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
    $html = local_multitenancy_footer_context_html_once();
    $html .= local_multitenancy_login_tenant_picker_html();
    return $html;
}

/**
 * Legacy fallback for themes/flows that skip standard_footer_html hook.
 *
 * @return void
 */
function local_multitenancy_before_footer(): void {
    echo local_multitenancy_footer_context_html_once();
    echo local_multitenancy_login_tenant_picker_html();
}

/**
 * Render tenant picker on login page.
 *
 * @return string
 */
function local_multitenancy_login_tenant_picker_html(): string {
    global $PAGE, $DB;
    static $alreadyrendered = false;

    if ($alreadyrendered) {
        return '';
    }

    $path = '';
    if (!empty($PAGE->url)) {
        $path = (string) $PAGE->url->get_path();
    }
    if ($path === '' && !empty($_SERVER['SCRIPT_NAME'])) {
        $path = (string) $_SERVER['SCRIPT_NAME'];
    }
    if (!preg_match('#/login/index\.php$#', $path)) {
        return '';
    }

    $records = $DB->get_records('local_multitenancy_tenant', ['enabled' => 1], 'sortorder ASC, id ASC', 'id,shortcode,name');
    if (!$records) {
        return '';
    }

    $items = [];
    foreach ($records as $tenant) {
        $shortcode = trim((string) $tenant->shortcode);
        if ($shortcode === '') {
            continue;
        }
        $name = trim((string) $tenant->name);
        if ($name === '') {
            $name = $shortcode;
        }
        $label = format_string($name) . ' (' . s($shortcode) . ')';
        $url = new moodle_url('/local/multitenancy/users/' . rawurlencode($shortcode) . '/');
        $items[] = html_writer::tag('li', html_writer::link($url, $label), ['style' => 'margin: 0.25rem 0;']);
    }

    if (!$items) {
        return '';
    }

    $title = local_multitenancy_string_or_default('logintenantpicker_title', 'Tenant selection');
    $desc = local_multitenancy_string_or_default('logintenantpicker_desc', 'Choose a tenant to continue.');
    $list = html_writer::tag('ul', implode('', $items), ['style' => 'margin: 0.5rem 0 0 1.25rem;']);
    $content = html_writer::tag('strong', s($title)) .
        html_writer::div(s($desc), '') .
        $list;

    $alreadyrendered = true;
    return html_writer::div(
        $content,
        'local-multitenancy-login-picker',
        [
            'style' => 'margin-top:12px;padding:12px;border:1px solid #d0d7de;border-radius:6px;background:#f8f9fa;',
        ]
    );
}
