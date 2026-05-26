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
    if (!$manager->string_exists($identifier, 'local_multitenancy')) {
        return $fallback;
    }
    $value = get_string($identifier, 'local_multitenancy');
    if ($value === "[[$identifier]]") {
        return $fallback;
    }
    return $value;
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
    function relocateMidurimBanner() {
        var banner = document.querySelector('.local-multitenancy-midurim');
        if (!banner || banner.getAttribute('data-local-multitenancy-placed') === '1') {
            return;
        }
        var regionmain = document.getElementById('region-main');
        if (regionmain) {
            regionmain.insertBefore(banner, regionmain.firstChild);
            banner.setAttribute('data-local-multitenancy-placed', '1');
            return;
        }
        var pageinner = document.querySelector('#page .main-inner');
        if (pageinner) {
            var toggles = pageinner.querySelector('.drawer-toggles');
            if (toggles) {
                toggles.after(banner);
            } else {
                pageinner.prepend(banner);
            }
            banner.setAttribute('data-local-multitenancy-placed', '1');
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', relocateMidurimBanner);
    } else {
        relocateMidurimBanner();
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
 * Resolve tenant display fields for the footer context card.
 *
 * @param string $shortcode
 * @return array{name:string,shortcode:string,id:int}|null
 */
function local_multitenancy_tenant_display_by_shortcode(string $shortcode): ?array {
    if ($shortcode === '') {
        return null;
    }

    $row = local_multitenancy_registry_tenant_by_shortcode($shortcode);
    if ($row !== null) {
        $name = trim((string) ($row['name'] ?? ''));
        return [
            'name' => $name !== '' ? $name : $shortcode,
            'shortcode' => $shortcode,
            'id' => !empty($row['id']) ? (int) $row['id'] : 0,
        ];
    }

    global $DB;
    if ($DB->get_manager()->table_exists('local_multitenancy_tenant')) {
        $record = $DB->get_record('local_multitenancy_tenant', ['shortcode' => $shortcode], 'id,shortcode,name', IGNORE_MISSING);
        if ($record) {
            $name = trim((string) ($record->name ?? ''));
            return [
                'name' => $name !== '' ? $name : $shortcode,
                'shortcode' => $shortcode,
                'id' => (int) $record->id,
            ];
        }
    }

    return [
        'name' => $shortcode,
        'shortcode' => $shortcode,
        'id' => 0,
    ];
}

/**
 * Active tenant shortcode for footer display (cookie or gateway entry).
 *
 * @return string
 */
function local_multitenancy_footer_tenant_shortcode(): string {
    return local_multitenancy_active_tenant_shortcode();
}

/**
 * Register CSS/JS for login page tenant UI (picker + current tenant card).
 *
 * @return void
 */
function local_multitenancy_login_register_assets(): void {
    static $registered = false;
    if ($registered || !local_multitenancy_path_is_login_page()) {
        return;
    }
    $registered = true;

    global $PAGE;
    $PAGE->requires->css('/local/multitenancy/styles/login_picker.css');
    $PAGE->requires->css('/local/multitenancy/styles/footer_context.css');
    $PAGE->requires->js_amd_inline(<<<'JS'
(function() {
    function placeLoginTenantExtras() {
        var extras = document.querySelector('.local-multitenancy-login-extras');
        var logincontainer = document.querySelector('.login-container');
        if (!extras || !logincontainer || extras.getAttribute('data-local-multitenancy-placed') === '1') {
            return;
        }
        logincontainer.insertBefore(extras, logincontainer.firstChild);
        extras.setAttribute('data-local-multitenancy-placed', '1');
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', placeLoginTenantExtras);
    } else {
        placeLoginTenantExtras();
    }
})();
JS
    );
}

/**
 * @deprecated Use local_multitenancy_login_register_assets().
 * @return void
 */
function local_multitenancy_footer_context_register_assets(): void {
    local_multitenancy_login_register_assets();
}

/**
 * @deprecated Use local_multitenancy_login_register_assets().
 * @return void
 */
function local_multitenancy_login_tenant_picker_register_assets(): void {
    local_multitenancy_login_register_assets();
}

/**
 * Build current-tenant card HTML (shared markup).
 *
 * @return string
 */
function local_multitenancy_tenant_context_card_html(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $code = local_multitenancy_footer_tenant_shortcode();
    if ($code === '') {
        $cached = '';
        return $cached;
    }

    $tenant = local_multitenancy_tenant_display_by_shortcode($code);
    if ($tenant === null) {
        $cached = '';
        return $cached;
    }

    global $OUTPUT;

    $titlelabel = local_multitenancy_string_or_default('footertenantcurrent', 'Current tenant:');
    $regionlabel = local_multitenancy_string_or_default('footertenantregion', 'Active tenant context');
    if (!empty($tenant['id'])) {
        $meta = get_string('footertenantmeta', 'local_multitenancy', (object) [
            'id' => $tenant['id'],
            'code' => $tenant['shortcode'],
        ]);
        if ($meta === '[[footertenantmeta]]') {
            $meta = 'Tenant ID: ' . $tenant['id'] . ' · ' . $tenant['shortcode'];
        }
    } else {
        $meta = get_string('footertenantmeta_code', 'local_multitenancy', $tenant['shortcode']);
        if ($meta === '[[footertenantmeta_code]]') {
            $meta = 'Tenant code: ' . $tenant['shortcode'];
        }
    }

    $badgeicon = html_writer::empty_tag('img', [
        'src' => $OUTPUT->image_url('i/cohort', 'core'),
        'class' => 'icon',
        'alt' => '',
        'aria-hidden' => 'true',
    ]);
    $leaveicon = html_writer::empty_tag('img', [
        'src' => $OUTPUT->image_url('logout', 'core'),
        'class' => 'icon',
        'alt' => '',
        'aria-hidden' => 'true',
    ]);
    $leaveurl = new moodle_url('/local/multitenancy/leave.php');
    $leavelabel = local_multitenancy_string_or_default('footerleavetenant', 'Leave tenant');
    $leavelink = html_writer::link(
        $leaveurl,
        $leaveicon . html_writer::span(s($leavelabel)),
        ['class' => 'local-multitenancy-footer-context__leave']
    );

    $info = html_writer::div($badgeicon, 'local-multitenancy-footer-context__badge') .
        html_writer::div(
            html_writer::tag(
                'p',
                s($titlelabel) . ' ' . html_writer::span(s($tenant['name']), 'local-multitenancy-footer-context__name'),
                ['class' => 'local-multitenancy-footer-context__title']
            ) .
            html_writer::tag('p', s($meta), ['class' => 'local-multitenancy-footer-context__meta']),
            'local-multitenancy-footer-context__text'
        );

    $card = html_writer::div(
        html_writer::div($info, 'local-multitenancy-footer-context__info') .
        html_writer::div($leavelink, 'local-multitenancy-footer-context__action'),
        'local-multitenancy-footer-context__card'
    );

    $cached = html_writer::div($card, 'local-multitenancy-footer-context', [
        'role' => 'region',
        'aria-label' => $regionlabel,
    ]);
    return $cached;
}

/**
 * Current-tenant card on the login page only (below the tenant dropdown).
 *
 * @return string
 */
function local_multitenancy_login_tenant_context_html_once(): string {
    if (!local_multitenancy_path_is_login_page()) {
        return '';
    }
    return local_multitenancy_tenant_context_card_html();
}

/**
 * @deprecated Use local_multitenancy_login_tenant_context_html_once().
 * @return string
 */
function local_multitenancy_footer_context_html_once(): string {
    return local_multitenancy_login_tenant_context_html_once();
}

/**
 * Tenant picker and current-tenant card for login/index.php.
 *
 * @return string
 */
function local_multitenancy_login_page_html(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!local_multitenancy_path_is_login_page()) {
        $cached = '';
        return $cached;
    }

    $picker = local_multitenancy_login_tenant_picker_html();
    $context = local_multitenancy_login_tenant_context_html_once();
    if ($picker === '' && $context === '') {
        $cached = '';
        return $cached;
    }

    $html = $picker . $context;
    if ($html !== '') {
        $html .= html_writer::div('', 'login-divider');
    }

    $cached = html_writer::div($html, 'local-multitenancy-login-extras w-100');
    return $cached;
}

/**
 * Legacy standard_footer_html callback (login UI is injected via top-of-body hook).
 *
 * @return string
 */
function local_multitenancy_standard_footer_html(): string {
    return '';
}

/**
 * Legacy before_footer callback (login UI uses top-of-body hook only).
 *
 * @return void
 */
function local_multitenancy_before_footer(): void {
}

/**
 * Whether the current page is the main login screen.
 *
 * @return bool
 */
function local_multitenancy_path_is_login_page(): bool {
    global $PAGE;

    $path = '';
    if (!empty($PAGE->url)) {
        $path = (string) $PAGE->url->get_path();
    }
    if ($path === '' && !empty($_SERVER['SCRIPT_NAME'])) {
        $path = (string) $_SERVER['SCRIPT_NAME'];
    }
    return (bool) preg_match('#/login/index\.php$#', $path);
}

/**
 * Render tenant picker on login page.
 *
 * @return string
 */
function local_multitenancy_login_tenant_picker_html(): string {
    global $DB, $OUTPUT;
    static $alreadyrendered = false;

    if ($alreadyrendered || !local_multitenancy_path_is_login_page()) {
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

    $urls = [];
    foreach ($records as $tenant) {
        $shortcode = trim((string) $tenant->shortcode);
        if ($shortcode === '') {
            continue;
        }
        $name = trim((string) $tenant->name);
        if ($name === '') {
            $name = $shortcode;
        }
        $url = new moodle_url('/local/multitenancy/users/' . rawurlencode($shortcode) . '/');
        $urls[$url->out(false)] = format_string($name);
    }

    if ($urls === []) {
        return '';
    }

    $placeholder = local_multitenancy_string_or_default('logintenantpicker_placeholder', 'Select a site…');
    $heading = local_multitenancy_string_or_default('logintenantpicker_label', 'Sign in to your site');

    if (!class_exists(\url_select::class, false)) {
        require_once($GLOBALS['CFG']->dirroot . '/lib/classes/output/url_select.php');
    }
    $urlselect = new \url_select(
        $urls,
        '',
        ['' => $placeholder],
        'local_multitenancy_tenant_jump'
    );
    $urlselect->class = 'local-multitenancy-urlselect w-100';
    $urlselect->attributes['id'] = 'local-multitenancy-tenant-select';

    $content = html_writer::tag('h2', $heading, ['class' => 'login-heading']) .
        $OUTPUT->render($urlselect);

    $alreadyrendered = true;
    return html_writer::div($content, 'local-multitenancy-login-picker w-100');
}

/**
 * Legacy head callback (Moodle 4.0–4.3): register midurim CSS/JS before &lt;head&gt; is sent.
 *
 * @return string
 */
function local_multitenancy_before_standard_html_head() {
    local_multitenancy_midurim_banner_register_assets();
    local_multitenancy_login_register_assets();
    return '';
}

/**
 * Legacy top-of-body callback (Moodle 4.0–4.3): midurim banner and login tenant picker.
 *
 * @return string
 */
function local_multitenancy_before_standard_top_of_body_html() {
    $html = local_multitenancy_midurim_banner_html();
    if (!class_exists(\core\hook\output\before_standard_top_of_body_html_generation::class)) {
        $html .= local_multitenancy_login_page_html();
    }
    return $html;
}
