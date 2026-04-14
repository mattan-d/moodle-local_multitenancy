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
 * Tenant resolution order (web): gateway entry constant (/local/multitenancy/users/{code}/),
 * then sticky cookie, then HTTP_HOST map. Parent admin and plugin management URLs skip overrides.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Cookie: tenant shortcode (must match gateway_manager::COOKIE_NAME). */
define('LOCAL_MULTITENANCY_COOKIE', 'local_mt_sc');

/** Cookie: public wwwroot derived from the gateway request (scheme + host + Moodle path prefix). */
define('LOCAL_MULTITENANCY_WWWROOT_COOKIE', 'local_mt_wr');

/**
 * Whether the current request is served over HTTPS (incl. common reverse-proxy / MAMP cases).
 *
 * @return bool
 */
function local_multitenancy_request_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
            strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    return false;
}

/**
 * Stop bootstrap when the gateway URL names a tenant that is missing or disabled in registry.php.
 *
 * @param string $code
 * @return void
 */
function local_multitenancy_abort_unknown_gateway_tenant(string $code): void {
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "Multitenancy: no enabled tenant with code \"" . $code . "\" in registry.\n\n";
    echo "Add the tenant in Site administration → Multitenancy, then save or rebuild registry.\n";
    echo "(עברית) הוסף את הדייר בניהול האתר, ואז שמור או בנה מחדש את registry.\n\n";
    echo "Registry file: " . (defined('MULTITENANCY_REGISTRY_DIR') ? rtrim(MULTITENANCY_REGISTRY_DIR, '/\\') . '/registry.php' : '(not set)') . "\n";
    exit(1);
}

/**
 * @param string $message
 * @return void
 */
function local_multitenancy_abort_bad_tenant_config(string $message): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "Multitenancy configuration error:\n\n" . $message . "\n";
    exit(1);
}

/**
 * @param string $path
 * @return bool
 */
function local_multitenancy_is_absolute_dataroot(string $path): bool {
    $path = trim($path);
    if ($path === '') {
        return false;
    }
    if ($path[0] === '/' || $path[0] === '\\') {
        return true;
    }
    if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[a-zA-Z]:[\/\\\\]/', $path)) {
        return true;
    }
    return false;
}

/**
 * @param string $wwwroot
 * @return bool
 */
function local_multitenancy_is_valid_public_wwwroot(string $wwwroot): bool {
    return (bool) preg_match('#\Ahttps?://.#iu', $wwwroot);
}

/**
 * @param string $pathprefix Path on server before /local/multitenancy/... (e.g. /moodle or empty).
 * @return string|null
 */
function local_multitenancy_build_public_wwwroot_from_prefix(string $pathprefix): ?string {
    $https = local_multitenancy_request_is_https();
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return null;
    }
    return rtrim($scheme . '://' . $host . $pathprefix, '/');
}

/**
 * @param string $requesturi
 * @return bool True if this request must use parent site config only.
 */
function local_multitenancy_request_is_parent_admin(string $requesturi): bool {
    $path = parse_url($requesturi, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return false;
    }
    if (preg_match('#/(?:install|upgrade)\.php$#', $path)) {
        return true;
    }
    if (strpos($path, '/admin/') !== false || preg_match('#/admin\.php$#', $path)) {
        return true;
    }
    if (preg_match('#/local/multitenancy/(?:manage|edit|delete)\.php#', $path)) {
        return true;
    }
    return false;
}

/**
 * @param array $map registry.php host => tenant row
 * @param string $shortcode
 * @return array|null
 */
function local_multitenancy_registry_row_by_shortcode(array $map, string $shortcode): ?array {
    foreach ($map as $row) {
        if (!empty($row['shortcode']) && $row['shortcode'] === $shortcode) {
            return $row;
        }
    }
    return null;
}

/**
 * Derive public wwwroot for gateway requests (SCRIPT_NAME and/or REQUEST_URI), for initialise_fullme().
 *
 * @param string $shortcode
 * @return string|null
 */
function local_multitenancy_gateway_derived_wwwroot(string $shortcode): ?string {
    if ($shortcode === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $shortcode)) {
        return null;
    }
    $needle = '/local/multitenancy/users/' . $shortcode;
    $pathprefix = null;

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $suffix = $needle . '/index.php';
    if (($pos = strpos($script, $suffix)) !== false) {
        $pathprefix = $pos > 0 ? substr($script, 0, $pos) : '';
    }
    if ($pathprefix === null) {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $path = str_replace('\\', '/', is_string($path) ? $path : '');
        if ($path !== '' && (($pos = strpos($path, $needle)) !== false)) {
            $pathprefix = $pos > 0 ? substr($path, 0, $pos) : '';
        }
    }
    if ($pathprefix !== null) {
        return local_multitenancy_build_public_wwwroot_from_prefix($pathprefix);
    }
    return null;
}

/**
 * @param string $wwwroot
 * @return bool
 */
function local_multitenancy_wwwroot_cookie_is_safe(string $wwwroot): bool {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return false;
    }
    $expectedhost = preg_replace('/:\d+$/', '', explode(':', $host, 2)[0]);
    $parsed = parse_url($wwwroot);
    if (empty($parsed['host'])) {
        return false;
    }
    $wh = preg_replace('/:\d+$/', '', $parsed['host']);
    if (function_exists('mb_strtolower')) {
        $wh = mb_strtolower($wh, 'UTF-8');
        $expectedhost = mb_strtolower($expectedhost, 'UTF-8');
    } else {
        $wh = strtolower($wh);
        $expectedhost = strtolower($expectedhost);
    }
    return $wh === $expectedhost;
}

/**
 * @param stdClass $cfg
 * @param array $tenant
 * @param bool $setcookiefromentry
 * @return void
 */
function local_multitenancy_apply_tenant(stdClass $cfg, array $tenant, bool $setcookiefromentry): void {
    $stringfields = ['wwwroot', 'dataroot', 'dbhost', 'dbname', 'dbuser', 'dbtype', 'dblibrary'];
    foreach ($stringfields as $field) {
        if ($setcookiefromentry && $field === 'wwwroot') {
            // Do not apply tenant wwwroot on gateway entry — it is often mis-set; we compute public URL below.
            continue;
        }
        if (!empty($tenant[$field])) {
            $cfg->{$field} = $tenant[$field];
        }
    }
    if (!empty($tenant['prefix'])) {
        $cfg->prefix = $tenant['prefix'];
    }
    if (array_key_exists('dbpass', $tenant)) {
        $cfg->dbpass = (string) $tenant['dbpass'];
    }
    if (!empty($tenant['dboptions']) && is_array($tenant['dboptions'])) {
        $cfg->dboptions = $tenant['dboptions'];
    }

    $publicwww = null;
    if ($setcookiefromentry && !empty($tenant['shortcode'])) {
        $publicwww = local_multitenancy_gateway_derived_wwwroot((string) $tenant['shortcode']);
    }
    if ($publicwww === null && !empty($_COOKIE[LOCAL_MULTITENANCY_WWWROOT_COOKIE])) {
        $decoded = rawurldecode((string) $_COOKIE[LOCAL_MULTITENANCY_WWWROOT_COOKIE]);
        if ($decoded !== '' && local_multitenancy_wwwroot_cookie_is_safe($decoded)) {
            $publicwww = rtrim($decoded, '/');
        }
    }
    if ($publicwww === null && $setcookiefromentry) {
        $publicwww = local_multitenancy_build_public_wwwroot_from_prefix('');
    }
    if ($publicwww === null && !empty($tenant['wwwroot']) && local_multitenancy_is_valid_public_wwwroot((string) $tenant['wwwroot'])) {
        $publicwww = rtrim((string) $tenant['wwwroot'], '/');
    }
    if ($publicwww !== null) {
        $cfg->wwwroot = $publicwww;
    }

    if (!local_multitenancy_is_valid_public_wwwroot((string) $cfg->wwwroot)) {
        local_multitenancy_abort_bad_tenant_config(
            "Invalid \$CFG->wwwroot: \"" . $cfg->wwwroot . "\".\n" .
            "Set the tenant wwwroot to a full URL (e.g. https://dev.moodle) in Multitenancy tenant settings."
        );
    }
    if (!local_multitenancy_is_absolute_dataroot((string) $cfg->dataroot)) {
        local_multitenancy_abort_bad_tenant_config(
            "Invalid tenant dataroot: \"" . $cfg->dataroot . "\".\n" .
            "It must be an absolute filesystem path (e.g. /var/moodledata/tenant1 on Unix).\n" .
            "(עברית) נתיב moodledata חייב להיות מוחלט, לא שם קצר כמו test01."
        );
    }

    if ($setcookiefromentry && !headers_sent()) {
        $secure = local_multitenancy_request_is_https();
        $opts = [
            'expires' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if (PHP_VERSION_ID >= 70300) {
            setcookie(LOCAL_MULTITENANCY_COOKIE, (string) $tenant['shortcode'], $opts);
            if ($publicwww !== null) {
                setcookie(LOCAL_MULTITENANCY_WWWROOT_COOKIE, rawurlencode($publicwww), $opts);
            }
        } else {
            setcookie(LOCAL_MULTITENANCY_COOKIE, (string) $tenant['shortcode'], 0, '/', '', $secure, true);
            if ($publicwww !== null) {
                setcookie(LOCAL_MULTITENANCY_WWWROOT_COOKIE, rawurlencode($publicwww), 0, '/', '', $secure, true);
            }
        }
    }
}

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

    $iscli = (defined('CLI_SCRIPT') && CLI_SCRIPT) || (PHP_SAPI === 'cli');

    if ($iscli) {
        $code = getenv('MOODLE_TENANT');
        if (!is_string($code) || $code === '') {
            return;
        }
        $tenant = local_multitenancy_registry_row_by_shortcode($map, $code);
        if ($tenant && !empty($tenant['enabled'])) {
            local_multitenancy_apply_tenant($cfg, $tenant, false);
        }
        return;
    }

    $requesturi = $_SERVER['REQUEST_URI'] ?? '';
    if (local_multitenancy_request_is_parent_admin($requesturi)) {
        return;
    }

    $tenant = null;
    $setcookie = false;

    // Gateway URL always names a tenant; if missing from registry, abort (do not load parent Moodle
    // with a gateway SCRIPT_NAME — that yields a blank page in initialise_fullme()).
    if (defined('LOCAL_MULTITENANCY_ENTRY_SHORTCODE')) {
        $code = (string) LOCAL_MULTITENANCY_ENTRY_SHORTCODE;
        if ($code === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
            local_multitenancy_abort_unknown_gateway_tenant($code !== '' ? $code : '(invalid)');
        }
        $candidate = local_multitenancy_registry_row_by_shortcode($map, $code);
        if ($candidate && !empty($candidate['enabled'])) {
            $tenant = $candidate;
            $setcookie = true;
        } else {
            local_multitenancy_abort_unknown_gateway_tenant($code);
        }
    }

    if (!$tenant && !empty($_COOKIE[LOCAL_MULTITENANCY_COOKIE])) {
        $code = (string) $_COOKIE[LOCAL_MULTITENANCY_COOKIE];
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
            $candidate = local_multitenancy_registry_row_by_shortcode($map, $code);
            if ($candidate && !empty($candidate['enabled'])) {
                $tenant = $candidate;
            }
        }
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (function_exists('mb_strtolower')) {
        $host = mb_strtolower($host, 'UTF-8');
    } else {
        $host = strtolower($host);
    }
    if (!$tenant && $host !== '' && isset($map[$host])) {
        $tenant = $map[$host];
    }

    if (!$tenant || empty($tenant['enabled'])) {
        return;
    }

    local_multitenancy_apply_tenant($cfg, $tenant, $setcookie);
}
