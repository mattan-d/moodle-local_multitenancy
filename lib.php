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
 * Resolve active tenant shortcode for the current request (cookie or gateway entry).
 *
 * @return string Empty when not in a tenant context.
 */
function local_multitenancy_active_tenant_shortcode(): string {
    if (!empty($_COOKIE['local_mt_sc'])) {
        $code = (string) $_COOKIE['local_mt_sc'];
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
            return $code;
        }
    }
    if (defined('LOCAL_MULTITENANCY_ENTRY_SHORTCODE')) {
        $code = (string) LOCAL_MULTITENANCY_ENTRY_SHORTCODE;
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
            return $code;
        }
    }
    return '';
}

/**
 * Load tenant row fragment from registry.php by shortcode.
 *
 * @param string $shortcode
 * @return array<string, mixed>|null
 */
function local_multitenancy_registry_tenant_by_shortcode(string $shortcode): ?array {
    if ($shortcode === '' || !defined('MULTITENANCY_REGISTRY_DIR') || !MULTITENANCY_REGISTRY_DIR) {
        return null;
    }
    $registryfile = rtrim((string) MULTITENANCY_REGISTRY_DIR, '/\\') . '/registry.php';
    if (!is_readable($registryfile)) {
        return null;
    }
    $map = include $registryfile;
    if (!is_array($map)) {
        return null;
    }
    foreach ($map as $row) {
        if (is_array($row) && !empty($row['shortcode']) && (string) $row['shortcode'] === $shortcode) {
            return $row;
        }
    }
    return null;
}

/**
 * Whether the midurim banner should be shown and its raw tenant data.
 *
 * @return array{midurim:string,midurimformat:int}|null
 */
function local_multitenancy_midurim_banner_context(): ?array {
    static $resolved = false;
    static $result = null;

    if ($resolved) {
        return $result;
    }
    $resolved = true;

    global $PAGE;

    $path = '';
    if (!empty($PAGE->url)) {
        $path = (string) $PAGE->url->get_path();
    }
    if ($path === '' && !empty($_SERVER['SCRIPT_NAME'])) {
        $path = (string) $_SERVER['SCRIPT_NAME'];
    }
    if (function_exists('local_multitenancy_path_is_parent_admin') &&
            local_multitenancy_path_is_parent_admin($path)) {
        return null;
    }

    if (in_array($PAGE->pagelayout, ['embedded', 'popup', 'print', 'maintenance'], true)) {
        return null;
    }

    $shortcode = local_multitenancy_active_tenant_shortcode();
    if ($shortcode === '') {
        return null;
    }

    $tenantrow = local_multitenancy_registry_tenant_by_shortcode($shortcode);
    if ($tenantrow === null) {
        global $DB;
        $record = $DB->get_record('local_multitenancy_tenant', ['shortcode' => $shortcode]);
        if (!$record || empty($record->enabled)) {
            return null;
        }
        $tenantrow = [
            'enabled' => (int) $record->enabled,
            'midurim' => (string) ($record->midurim ?? ''),
            'midurimformat' => (int) ($record->midurimformat ?? FORMAT_HTML),
        ];
    }

    if (empty($tenantrow['enabled'])) {
        return null;
    }

    $content = trim((string) ($tenantrow['midurim'] ?? ''));
    if ($content === '') {
        return null;
    }

    $result = [
        'midurim' => $content,
        'midurimformat' => (int) ($tenantrow['midurimformat'] ?? FORMAT_HTML),
    ];
    return $result;
}

/**
 * Register CSS/JS for the midurim banner (must run before head is printed).
 *
 * @return void
 */
function local_multitenancy_midurim_banner_register_assets(): void {
    static $registered = false;
    if ($registered || local_multitenancy_midurim_banner_context() === null) {
        return;
    }
    $registered = true;

    global $PAGE;
    $PAGE->requires->css('/local/multitenancy/styles/midurim.css');
    $PAGE->requires->js_amd_inline(<<<'JS'
(function() {
    var banner = document.querySelector('#page-wrapper > .local-multitenancy-midurim');
    if (!banner) {
        return;
    }
    var pageinner = document.querySelector('#page .main-inner');
    if (pageinner) {
        var toggles = pageinner.querySelector('.drawer-toggles');
        if (toggles) {
            toggles.after(banner);
            return;
        }
        pageinner.prepend(banner);
        return;
    }
    var regionmain = document.getElementById('region-main');
    if (regionmain) {
        regionmain.prepend(banner);
    }
})();
JS
    );
}

/**
 * HTML banner (jumbotron-style) with tenant "midurim" content at the top of each page.
 *
 * @return string
 */
function local_multitenancy_midurim_banner_html(): string {
    static $alreadyrendered = false;
    if ($alreadyrendered) {
        return '';
    }

    $bannercontext = local_multitenancy_midurim_banner_context();
    if ($bannercontext === null) {
        return '';
    }

    $body = format_text($bannercontext['midurim'], $bannercontext['midurimformat'], [
        'context' => context_system::instance(),
        'noclean' => true,
        'overflowdiv' => true,
    ]);

    $alreadyrendered = true;
    return html_writer::div(
        $body,
        'local-multitenancy-midurim rounded-3 p-4 mb-3 bg-light border w-100',
        [
            'role' => 'region',
            'aria-label' => get_string('midurim', 'local_multitenancy'),
        ]
    );
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

    $records = $DB->get_records_select(
        'local_multitenancy_tenant',
        'enabled = 1 AND showonlogin = 1',
        null,
        'sortorder ASC, id ASC',
        'id,shortcode,name'
    );
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
