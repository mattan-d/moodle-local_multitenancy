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
    local_multitenancy_clear_tenant_cookies();
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "Multitenancy: no enabled tenant with code \"" . $code . "\" in registry.\n\n";
    echo "Add the tenant in Site administration → Multitenancy (Manage tenants) and save.\n";
    echo "(עברית) הוסף את הדייר בניהול האתר ושמור.\n\n";
    echo "Registry file: " . (defined('MULTITENANCY_REGISTRY_DIR') ? rtrim(MULTITENANCY_REGISTRY_DIR, '/\\') . '/registry.php' : '(not set)') . "\n";
    echo "Recovery: open /local/multitenancy/leave.php to clear tenant cookies.\n";
    exit(1);
}

/**
 * Stop when gateway/cookie points at a tenant that is not provisioned yet.
 *
 * @param string $code
 * @param string $status
 * @return void
 */
function local_multitenancy_abort_tenant_not_ready(string $code, string $status): void {
    local_multitenancy_clear_tenant_cookies();
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "Multitenancy: tenant \"" . $code . "\" is not ready yet (provision status: " . $status . ").\n\n";
    echo "Do not open the tenant gateway until provisioning is Complete.\n";
    echo "Check errors on the parent site: Site administration → Local plugins → Manage tenants.\n";
    echo "(עברית) הדייר עדיין לא מוכן. בדוק שגיאות בעמוד ניהול הדיירים באתר האב.\n\n";
    echo "Recovery: /local/multitenancy/leave.php\n";
    exit(1);
}

/**
 * Stop when registry says Complete but tenant DB is empty / not a Moodle install.
 * Prevents Moodle core from redirecting to /install.php (ERR_TOO_MANY_REDIRECTS).
 *
 * @param string $code
 * @return void
 */
function local_multitenancy_abort_tenant_db_not_installed(string $code): void {
    local_multitenancy_clear_tenant_cookies();
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "Multitenancy: tenant \"" . $code . "\" database is not a finished Moodle install.\n\n";
    echo "Opening the gateway would start /install.php and can cause too many redirects.\n";
    echo "Fix on the parent site: Manage tenants → Diagnose for this tenant, then re-provision.\n";
    echo "(עברית) מסד הדייר אינו התקנת Moodle תקינה. אל תפתח את ה־gateway — השתמש בדיאגנוזה ובהקמה מחדש.\n\n";
    echo "Recovery: /local/multitenancy/leave.php\n";
    exit(1);
}

/**
 * Lightweight check (no Moodle APIs): does tenant DB contain config.version?
 *
 * @param array $tenant Registry row
 * @return bool
 */
function local_multitenancy_tenant_db_is_installed(array $tenant): bool {
    $dbname = (string) ($tenant['dbname'] ?? '');
    $prefix = (string) ($tenant['prefix'] ?? 'mdl_');
    $dbtype = (string) ($tenant['dbtype'] ?? '');
    $dbhost = (string) ($tenant['dbhost'] ?? '');
    $dbuser = (string) ($tenant['dbuser'] ?? '');
    $dbpass = (string) ($tenant['dbpass'] ?? '');
    if ($dbname === '') {
        return false;
    }

    if ($dbtype === 'pgsql') {
        if (!function_exists('pg_connect')) {
            return false;
        }
        $parts = [];
        if ($dbhost !== '') {
            $parts[] = "host='" . str_replace("'", "\\'", $dbhost) . "'";
        }
        if (!empty($tenant['dboptions']['dbport'])) {
            $parts[] = 'port=' . (int) $tenant['dboptions']['dbport'];
        }
        if ($dbuser !== '') {
            $parts[] = "user='" . str_replace("'", "\\'", $dbuser) . "'";
        }
        if ($dbpass !== '') {
            $parts[] = "password='" . str_replace("'", "\\'", $dbpass) . "'";
        }
        $parts[] = "dbname='" . str_replace("'", "\\'", $dbname) . "'";
        $conn = @pg_connect(implode(' ', $parts));
        if (!$conn) {
            return false;
        }
        $table = '"' . str_replace('"', '""', $prefix . 'config') . '"';
        $res = @pg_query($conn, "SELECT value FROM {$table} WHERE name = 'version' LIMIT 1");
        $ok = false;
        if ($res && ($row = pg_fetch_assoc($res)) && isset($row['value']) && (string) $row['value'] !== '') {
            $ok = true;
        }
        if ($res) {
            pg_free_result($res);
        }
        pg_close($conn);
        return $ok;
    }

    if (!in_array($dbtype, ['mysqli', 'mariadb', 'auroramysql'], true)) {
        // Unknown driver — do not block (avoid false 503).
        return true;
    }
    if (!class_exists('mysqli', false) && !extension_loaded('mysqli')) {
        return false;
    }
    $port = 0;
    $socket = null;
    if (!empty($tenant['dboptions']) && is_array($tenant['dboptions'])) {
        if (!empty($tenant['dboptions']['dbport'])) {
            $port = (int) $tenant['dboptions']['dbport'];
        }
        if (!empty($tenant['dboptions']['dbsocket'])) {
            $socket = (string) $tenant['dboptions']['dbsocket'];
        }
    }
    if ($socket) {
        $conn = @new mysqli(null, $dbuser, $dbpass, $dbname, $port, $socket);
    } else {
        $conn = @new mysqli($dbhost, $dbuser, $dbpass, $dbname, $port);
    }
    if ($conn->connect_errno) {
        return false;
    }
    $table = '`' . str_replace('`', '``', $prefix . 'config') . '`';
    $res = @$conn->query("SELECT value FROM {$table} WHERE name = 'version' LIMIT 1");
    $ok = false;
    if ($res && ($row = $res->fetch_assoc()) && isset($row['value']) && (string) $row['value'] !== '') {
        $ok = true;
    }
    $conn->close();
    return $ok;
}

/**
 * Resolve registry directory (MULTITENANCY_REGISTRY_DIR constant or {dataroot}/multitenancy).
 *
 * @param stdClass|null $cfg
 * @return string Absolute path or empty string.
 */
function local_multitenancy_resolve_registry_dir(?stdClass $cfg = null): string {
    if (defined('MULTITENANCY_REGISTRY_DIR') && MULTITENANCY_REGISTRY_DIR) {
        return rtrim((string) MULTITENANCY_REGISTRY_DIR, "/\\\0");
    }
    $dataroot = '';
    if ($cfg !== null && !empty($cfg->dataroot)) {
        $dataroot = (string) $cfg->dataroot;
    } else if (!empty($GLOBALS['CFG']) && !empty($GLOBALS['CFG']->dataroot)) {
        $dataroot = (string) $GLOBALS['CFG']->dataroot;
    }
    if ($dataroot === '') {
        return '';
    }
    return rtrim($dataroot, "/\\\0") . '/multitenancy';
}

/**
 * Whether a registry tenant row may be applied (enabled + provision complete).
 *
 * @param array $tenant
 * @return bool
 */
function local_multitenancy_tenant_row_is_ready(array $tenant): bool {
    if (empty($tenant['enabled'])) {
        return false;
    }
    // Legacy registry files without provisionstatus remain usable.
    if (!array_key_exists('provisionstatus', $tenant)) {
        return true;
    }
    return (string) $tenant['provisionstatus'] === 'complete';
}

/**
 * @param string $requesturi
 * @return bool
 */
function local_multitenancy_request_is_install_or_upgrade(string $requesturi): bool {
    $path = parse_url($requesturi, PHP_URL_PATH);
    if (is_string($path) && preg_match('#/(?:install|upgrade)\.php$#', $path)) {
        return true;
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    return is_string($script) && (bool) preg_match('#/(?:install|upgrade)\.php$#', $script);
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
 * Strip /local/multitenancy/users/{code} from a stored tenant wwwroot so $CFG->wwwroot is the real Moodle site base.
 * Tenants may store the full gateway URL; this returns the derived public base.
 * Moodle must use the real site base (e.g. https://host or https://host/moodle).
 *
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
 * Whether a URL path must always use the parent (hub) site config.
 *
 * @param string $path
 * @return bool
 */
function local_multitenancy_path_is_parent_admin(string $path): bool {
    if (!is_string($path) || $path === '') {
        return false;
    }
    if (preg_match('#/(?:install|upgrade)\.php$#', $path)) {
        return true;
    }
    // Any plugin PHP script except tenant gateway stubs under users/{code}/.
    if (preg_match('#/local/multitenancy/(?!users/)[a-zA-Z0-9_-]+\.php$#', $path)) {
        return true;
    }
    return false;
}

/**
 * @param string $requesturi
 * @return bool True if this request must use parent site config only.
 */
function local_multitenancy_request_is_parent_admin(string $requesturi): bool {
    $path = parse_url($requesturi, PHP_URL_PATH);
    $query = parse_url($requesturi, PHP_URL_QUERY);
    if (local_multitenancy_path_is_parent_admin($path)) {
        return true;
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (local_multitenancy_path_is_parent_admin($script)) {
        return true;
    }
    // Keep plugin management/settings on parent site even when tenant cookie is set.
    if (is_string($path) && strpos($path, '/admin/settings.php') !== false && is_string($query)) {
        parse_str($query, $q);
        if (!empty($q['section']) && (string) $q['section'] === 'local_multitenancy') {
            return true;
        }
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
    $suffix = $needle . '/index.php';
    $pathprefix = null;

    $candidates = [];
    foreach (['SCRIPT_NAME', 'PHP_SELF'] as $key) {
        if (!empty($_SERVER[$key])) {
            $candidates[] = str_replace('\\', '/', (string) $_SERVER[$key]);
        }
    }
    foreach ($candidates as $script) {
        if (($pos = strpos($script, $suffix)) !== false) {
            $pathprefix = $pos > 0 ? substr($script, 0, $pos) : '';
            break;
        }
    }
    if ($pathprefix === null) {
        foreach (['REQUEST_URI', 'REDIRECT_URL'] as $key) {
            $path = parse_url($_SERVER[$key] ?? '', PHP_URL_PATH);
            $path = str_replace('\\', '/', is_string($path) ? $path : '');
            if ($path !== '' && (($pos = strpos($path, $needle)) !== false)) {
                $pathprefix = $pos > 0 ? substr($path, 0, $pos) : '';
                break;
            }
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
 * @param string $requesturi
 * @return bool
 */
function local_multitenancy_request_is_logout(string $requesturi): bool {
    $path = parse_url($requesturi, PHP_URL_PATH);
    return is_string($path) && (bool) preg_match('#/login/logout\.php$#', $path);
}

/**
 * Clear tenant selection + tenant session cookies on logout,
 * so the user returns to the parent Moodle context.
 *
 * @return void
 */
function local_multitenancy_clear_tenant_cookies(): void {
    if (headers_sent()) {
        return;
    }
    $secure = local_multitenancy_request_is_https();
    $opts = [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        setcookie(LOCAL_MULTITENANCY_COOKIE, '', $opts);
        setcookie(LOCAL_MULTITENANCY_WWWROOT_COOKIE, '', $opts);
        foreach (array_keys($_COOKIE) as $cookiename) {
            if (strpos((string) $cookiename, 'MoodleSessionMT_') === 0) {
                setcookie((string) $cookiename, '', $opts);
            }
        }
    } else {
        setcookie(LOCAL_MULTITENANCY_COOKIE, '', time() - 3600, '/', '', $secure, true);
        setcookie(LOCAL_MULTITENANCY_WWWROOT_COOKIE, '', time() - 3600, '/', '', $secure, true);
        foreach (array_keys($_COOKIE) as $cookiename) {
            if (strpos((string) $cookiename, 'MoodleSessionMT_') === 0) {
                setcookie((string) $cookiename, '', time() - 3600, '/', '', $secure, true);
            }
        }
    }
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
    if (!empty($tenant['shortcode']) && preg_match('/^[a-zA-Z0-9_-]+$/', (string) $tenant['shortcode'])) {
        // Keep parent and tenant sessions separate on same host.
        // Moodle prepends "MoodleSession" itself in its session manager.
        $cfg->sessioncookie = 'MT_' . (string) $tenant['shortcode'];
    }

    $publicwww = null;
    if ($setcookiefromentry && !empty($tenant['shortcode'])) {
        $publicwww = local_multitenancy_gateway_derived_wwwroot((string) $tenant['shortcode']);
    }
    if ($publicwww === null && !empty($_COOKIE[LOCAL_MULTITENANCY_WWWROOT_COOKIE])) {
        $decoded = rawurldecode((string) $_COOKIE[LOCAL_MULTITENANCY_WWWROOT_COOKIE]);
        $decoded = local_multitenancy_normalize_public_wwwroot($decoded);
        if ($decoded !== '' && local_multitenancy_wwwroot_cookie_is_safe($decoded)) {
            $publicwww = rtrim($decoded, '/');
        }
    }
    // Prefer $CFG->wwwroot from config.php (correct for subdirectory installs); avoid host-only
    // guess that drops /moodle and breaks initialise_fullme() ($SCRIPT becomes null → blank page).
    if ($publicwww === null && $setcookiefromentry) {
        $fromcfg = local_multitenancy_normalize_public_wwwroot((string) $cfg->wwwroot);
        if (local_multitenancy_is_valid_public_wwwroot($fromcfg)) {
            $publicwww = rtrim($fromcfg, '/');
        }
    }
    if ($publicwww === null && $setcookiefromentry) {
        $publicwww = local_multitenancy_build_public_wwwroot_from_prefix('');
    }
    if ($publicwww === null && !empty($tenant['wwwroot'])) {
        $tw = local_multitenancy_normalize_public_wwwroot((string) $tenant['wwwroot']);
        if (local_multitenancy_is_valid_public_wwwroot($tw)) {
            $publicwww = rtrim($tw, '/');
        }
    }
    if ($publicwww !== null) {
        $cfg->wwwroot = $publicwww;
    }

    $cfg->wwwroot = local_multitenancy_normalize_public_wwwroot((string) $cfg->wwwroot);

    if (!local_multitenancy_is_valid_public_wwwroot((string) $cfg->wwwroot)) {
        local_multitenancy_abort_bad_tenant_config(
            "Invalid \$CFG->wwwroot after normalisation: \"" . $cfg->wwwroot . "\".\n" .
            "In tenant settings set a full URL (gateway URL such as https://dev.moodle/local/multitenancy/users/code/ is OK, or the site base only)."
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
    $registrydir = local_multitenancy_resolve_registry_dir($cfg);
    if ($registrydir === '') {
        return;
    }
    if (!defined('MULTITENANCY_REGISTRY_DIR')) {
        define('MULTITENANCY_REGISTRY_DIR', $registrydir);
    }

    $registryfile = $registrydir . '/registry.php';
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
        if ($tenant && local_multitenancy_tenant_row_is_ready($tenant)) {
            local_multitenancy_apply_tenant($cfg, $tenant, false);
        }
        return;
    }

    $requesturi = $_SERVER['REQUEST_URI'] ?? '';
    if (local_multitenancy_request_is_logout($requesturi)) {
        local_multitenancy_clear_tenant_cookies();
        return;
    }

    // Break ERR_TOO_MANY_REDIRECTS: empty tenant DB → /install.php ↔ parent home.
    // Always use parent config for install/upgrade, and drop a sticky tenant cookie.
    if (local_multitenancy_request_is_install_or_upgrade($requesturi)) {
        if (!empty($_COOKIE[LOCAL_MULTITENANCY_COOKIE]) || defined('LOCAL_MULTITENANCY_ENTRY_SHORTCODE')) {
            local_multitenancy_clear_tenant_cookies();
        }
        return;
    }

    if (local_multitenancy_request_is_parent_admin($requesturi)) {
        return;
    }

    $tenant = null;
    $setcookie = false;

    // Gateway URL always names a tenant; if missing/not ready, abort (do not load parent Moodle
    // with a gateway SCRIPT_NAME — that yields a blank page in initialise_fullme()).
    if (defined('LOCAL_MULTITENANCY_ENTRY_SHORTCODE')) {
        $code = (string) LOCAL_MULTITENANCY_ENTRY_SHORTCODE;
        if ($code === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
            local_multitenancy_abort_unknown_gateway_tenant($code !== '' ? $code : '(invalid)');
        }
        $candidate = local_multitenancy_registry_row_by_shortcode($map, $code);
        if (!$candidate || empty($candidate['enabled'])) {
            local_multitenancy_abort_unknown_gateway_tenant($code);
        }
        if (!local_multitenancy_tenant_row_is_ready($candidate)) {
            $status = (string) ($candidate['provisionstatus'] ?? 'unknown');
            local_multitenancy_abort_tenant_not_ready($code, $status);
        }
        $tenant = $candidate;
        $setcookie = true;
    }

    if (!$tenant && !empty($_COOKIE[LOCAL_MULTITENANCY_COOKIE])) {
        $code = (string) $_COOKIE[LOCAL_MULTITENANCY_COOKIE];
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
            $candidate = local_multitenancy_registry_row_by_shortcode($map, $code);
            if ($candidate && local_multitenancy_tenant_row_is_ready($candidate)) {
                $tenant = $candidate;
            } else {
                // Stale cookie from a failed/incomplete provision must not break the parent site.
                local_multitenancy_clear_tenant_cookies();
            }
        }
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (function_exists('mb_strtolower')) {
        $host = mb_strtolower($host, 'UTF-8');
    } else {
        $host = strtolower($host);
    }
    if (!$tenant && $host !== '' && isset($map[$host]) && is_array($map[$host])) {
        if (local_multitenancy_tenant_row_is_ready($map[$host])) {
            $tenant = $map[$host];
        }
    }

    if (!$tenant || !local_multitenancy_tenant_row_is_ready($tenant)) {
        return;
    }

    // Safety net: registry may say Complete while DB is empty/broken (failed clone).
    // Applying tenant config would make Moodle redirect to /install.php → redirect loop.
    if (!local_multitenancy_tenant_db_is_installed($tenant)) {
        $code = (string) ($tenant['shortcode'] ?? '');
        local_multitenancy_abort_tenant_db_not_installed($code !== '' ? $code : '(unknown)');
    }

    local_multitenancy_apply_tenant($cfg, $tenant, $setcookie);
}
