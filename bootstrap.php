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
 * Tenant code from Apache RewriteRule [E=MOODLE_TENANT:...] (also REDIRECT_ after internal redirect).
 *
 * @return string empty if unset
 */
function local_multitenancy_request_tenant_env(): string {
    foreach (['MOODLE_TENANT', 'REDIRECT_MOODLE_TENANT'] as $key) {
        if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
    }
    foreach (['MOODLE_TENANT', 'REDIRECT_MOODLE_TENANT'] as $key) {
        $v = getenv($key);
        if (is_string($v) && $v !== '') {
            return $v;
        }
    }
    return '';
}

/**
 * Parent (hub) plugin admin — never switch DB; clear tenant cookie.
 *
 * @return bool
 */
function local_multitenancy_is_hub_request(): bool {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path)) {
        $path = $uri;
    }
    if (strpos($path, '/local/multitenancy/') === 0) {
        return true;
    }
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    return strpos($sn, '/local/multitenancy/') !== false;
}

/**
 * @return void
 */
function local_multitenancy_clear_tenant_cookie(): void {
    $cn = 'local_multitenancy_sc';
    if (PHP_VERSION_ID >= 70300) {
        setcookie($cn, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' && $_SERVER['HTTPS'] !== 'OFF'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie($cn, '', time() - 3600, '/', '', false, true);
    }
    unset($_COOKIE[$cn]);
}

/**
 * Normalise registry.php payload (new format or legacy host-keyed map).
 *
 * @param array $map Raw included data
 * @return array
 */
function local_multitenancy_normalise_registry(array $map): array {
    if (isset($map['by_shortcode']) && is_array($map['by_shortcode'])) {
        $pp = $map['path_prefix'] ?? '/multitenancy';
        $pp = '/' . trim((string) $pp, "/\\\0");
        if ($pp === '/') {
            $pp = '/multitenancy';
        }
        $routing = $map['routing'] ?? 'stub';
        if ($routing !== 'rewrite' && $routing !== 'stub') {
            $routing = 'stub';
        }
        return [
            'routing' => $routing,
            'path_prefix' => $pp,
            'by_shortcode' => $map['by_shortcode'],
            'by_host' => is_array($map['by_host'] ?? null) ? $map['by_host'] : [],
        ];
    }
    $byhost = [];
    $byshort = [];
    foreach ($map as $key => $row) {
        if (!is_array($row) || empty($row['shortcode'])) {
            continue;
        }
        if (function_exists('mb_strtolower')) {
            $hkey = mb_strtolower((string) $key, 'UTF-8');
        } else {
            $hkey = strtolower((string) $key);
        }
        $byhost[$hkey] = $row;
        $byshort[$row['shortcode']] = $row;
    }
    return [
        'routing' => 'stub',
        'path_prefix' => '/multitenancy',
        'by_shortcode' => $byshort,
        'by_host' => $byhost,
    ];
}

/**
 * Extract tenant shortcode from request path: {path_prefix}/{shortcode}/...
 *
 * @param string $parentwwwroot $CFG->wwwroot of the parent site (from config.php)
 * @param string $pathprefix e.g. /multitenancy
 * @return string|null shortcode or null
 */
function local_multitenancy_shortcode_from_path(string $parentwwwroot, string $pathprefix): ?string {
    $ru = $_SERVER['REQUEST_URI'] ?? '';
    if ($ru === '') {
        return null;
    }
    $parts = parse_url($ru);
    $path = $parts['path'] ?? '';
    if ($path === '') {
        return null;
    }
    $parentpath = parse_url(rtrim($parentwwwroot, '/') . '/', PHP_URL_PATH);
    $parentpath = is_string($parentpath) ? rtrim($parentpath, '/') : '';
    if ($parentpath !== '' && strpos($path, $parentpath) === 0) {
        $path = substr($path, strlen($parentpath));
        if ($path === '') {
            $path = '/';
        } else if ($path[0] !== '/') {
            $path = '/' . $path;
        }
    }
    $pp = '/' . trim($pathprefix, "/\\\0");
    if ($pp === '/') {
        $pp = '/multitenancy';
    }
    $needle = $pp . '/';
    if (strpos($path, $needle) !== 0) {
        return null;
    }
    $rest = substr($path, strlen($needle));
    if ($rest === '') {
        return null;
    }
    if (!preg_match('/^([A-Za-z0-9_]+)(\/|$)/', $rest, $m)) {
        return null;
    }
    return $m[1];
}

/**
 * Prefix SCRIPT_NAME and REQUEST_URI so Moodle fullme matches subdirectory wwwroot.
 *
 * @param string $tenantwwwroot Full tenant wwwroot (no trailing slash)
 * @return void
 */
function local_multitenancy_fix_server_paths(string $tenantwwwroot): void {
    $path = parse_url(rtrim($tenantwwwroot, '/') . '/', PHP_URL_PATH);
    if (!is_string($path) || $path === '' || $path === '/') {
        return;
    }
    $basepath = rtrim($path, '/');

    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($sn !== '' && strpos($sn, $basepath) !== 0) {
        $_SERVER['SCRIPT_NAME'] = $basepath . $sn;
    }

    $ru = $_SERVER['REQUEST_URI'] ?? '';
    if ($ru === '') {
        return;
    }
    $rparts = parse_url($ru);
    $rpath = $rparts['path'] ?? '';
    if ($rpath === '' || strpos($rpath, $basepath) === 0) {
        return;
    }
    $query = isset($rparts['query']) ? '?' . $rparts['query'] : '';
    $_SERVER['REQUEST_URI'] = $basepath . $rpath . $query;
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

    /** @var array $raw */
    $raw = include $registryfile;
    if (!is_array($raw) || empty($raw)) {
        return;
    }

    $parentwwwroot = rtrim($cfg->wwwroot ?? '', '/');
    $registry = local_multitenancy_normalise_registry($raw);
    $routing = $registry['routing'] ?? 'stub';
    $pathprefix = $registry['path_prefix'];
    $byshort = $registry['by_shortcode'];
    $byhost = $registry['by_host'];

    $tenant = null;
    $shortcode = null;

    if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
        $code = getenv('MOODLE_TENANT');
        if (is_string($code) && $code !== '' && isset($byshort[$code])) {
            $tenant = $byshort[$code];
        }
    } else {
        if (local_multitenancy_is_hub_request()) {
            local_multitenancy_clear_tenant_cookie();
            return;
        }

        $code = local_multitenancy_request_tenant_env();
        if ($code !== '' && isset($byshort[$code])) {
            $tenant = $byshort[$code];
        }
        // Cookie before path so / and /index.php pick tenant after stub redirect.
        if (!$tenant) {
            $cval = $_COOKIE['local_multitenancy_sc'] ?? '';
            if (is_string($cval) && $cval !== '') {
                $cval = rawurldecode($cval);
            }
            if ($cval !== '' && preg_match('/^[A-Za-z0-9_]+$/', $cval) && isset($byshort[$cval])) {
                $ten = $byshort[$cval];
                if (!array_key_exists('enabled', $ten) || (int) $ten['enabled'] === 1) {
                    $tenant = $ten;
                }
            }
        }
        if (!$tenant) {
            $shortcode = local_multitenancy_shortcode_from_path($parentwwwroot, $pathprefix);
            if ($shortcode !== null && isset($byshort[$shortcode])) {
                $tenant = $byshort[$shortcode];
            }
        }
        if (!$tenant) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if (function_exists('mb_strtolower')) {
                $host = mb_strtolower($host, 'UTF-8');
            } else {
                $host = strtolower($host);
            }
            if ($host !== '' && isset($byhost[$host])) {
                $tenant = $byhost[$host];
            }
        }
    }

    if (!$tenant) {
        return;
    }
    if (array_key_exists('enabled', $tenant) && (int) $tenant['enabled'] !== 1) {
        return;
    }

    $stringfields = ['wwwroot', 'dataroot', 'dbhost', 'dbname', 'dbuser', 'dbtype', 'dblibrary'];
    foreach ($stringfields as $field) {
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

    if (!defined('CLI_SCRIPT') || !CLI_SCRIPT) {
        if ($routing === 'rewrite') {
            local_multitenancy_fix_server_paths($cfg->wwwroot);
        }
    }
}
