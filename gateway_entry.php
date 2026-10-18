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
 * Lightweight gateway entry: set tenant cookies and redirect to public wwwroot.
 *
 * Intentionally does NOT load Moodle lib/setup.php — loading full Moodle from
 * /local/multitenancy/users/{code}/index.php leaves SCRIPT mismatched and yields
 * a blank HTTP 200 on many installs (initialise_fullme).
 *
 * Included from users/{code}/index.php (auto-generated stub).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/wwwroot_lib.php');
require_once(__DIR__ . '/registry_lib.php');
require_once(__DIR__ . '/upgrade_lib.php');

// Cookie constant names (same as bootstrap.php; avoid loading full bootstrap here).
if (!defined('LOCAL_MULTITENANCY_COOKIE')) {
    define('LOCAL_MULTITENANCY_COOKIE', 'local_mt_sc');
}
if (!defined('LOCAL_MULTITENANCY_WWWROOT_COOKIE')) {
    define('LOCAL_MULTITENANCY_WWWROOT_COOKIE', 'local_mt_wr');
}

/**
 * @param string $message
 * @param int $code
 * @return void
 */
function local_multitenancy_gateway_fail(string $message, int $code = 500): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo $message;
    if (substr($message, -1) !== "\n") {
        echo "\n";
    }
    exit(1);
}

/**
 * Walk up from a path to find Moodle config.php.
 *
 * @param string $start
 * @return string Empty when not found.
 */
function local_multitenancy_gateway_find_cfgroot(string $start): string {
    $d = $start;
    while ($d !== '/' && $d !== '' && $d !== '.') {
        if (is_readable($d . DIRECTORY_SEPARATOR . 'config.php')) {
            return $d;
        }
        $parent = dirname($d);
        if ($parent === $d) {
            break;
        }
        $d = $parent;
    }
    return '';
}

/**
 * Read a simple $CFG->name = 'value'; assignment from config.php source.
 *
 * @param string $configsrc
 * @param string $name
 * @return string
 */
function local_multitenancy_gateway_parse_cfg_string(string $configsrc, string $name): string {
    $pattern = '/\$CFG\s*->\s*' . preg_quote($name, '/') . '\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/';
    if (preg_match($pattern, $configsrc, $m)) {
        return (string) $m[1];
    }
    return '';
}

/**
 * @return bool
 */
function local_multitenancy_gateway_is_https(): bool {
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
 * @param string $name
 * @param string $value
 * @return void
 */
function local_multitenancy_gateway_set_cookie(string $name, string $value): void {
    $secure = local_multitenancy_gateway_is_https();
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $value, [
            'expires' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie($name, $value, 0, '/', '', $secure, true);
    }
}

$code = defined('LOCAL_MULTITENANCY_ENTRY_SHORTCODE')
    ? (string) LOCAL_MULTITENANCY_ENTRY_SHORTCODE
    : basename(dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__));

if ($code === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
    local_multitenancy_gateway_fail('Multitenancy gateway: invalid tenant code.', 400);
}

$cfgroot = local_multitenancy_gateway_find_cfgroot(dirname(__DIR__, 2));
if ($cfgroot === '') {
    $cfgroot = local_multitenancy_gateway_find_cfgroot(__DIR__);
}
if ($cfgroot === '') {
    local_multitenancy_gateway_fail('Multitenancy gateway: Moodle config.php not found.');
}

$configsrc = (string) @file_get_contents($cfgroot . '/config.php');
if ($configsrc === '') {
    local_multitenancy_gateway_fail('Multitenancy gateway: cannot read config.php.');
}

$dataroot = local_multitenancy_gateway_parse_cfg_string($configsrc, 'dataroot');
$wwwrootfromcfg = local_multitenancy_gateway_parse_cfg_string($configsrc, 'wwwroot');

$registrydir = '';
$pathsfile = '';
if ($dataroot !== '') {
    $pathsfile = rtrim($dataroot, "/\\\0") . '/multitenancy/gateway_paths.php';
    if (is_readable($pathsfile)) {
        /** @var array $paths */
        $paths = include $pathsfile;
        if (is_array($paths)) {
            if (!empty($paths['registrydir'])) {
                $registrydir = rtrim((string) $paths['registrydir'], "/\\\0");
            }
            if (!empty($paths['wwwroot']) && $wwwrootfromcfg === '') {
                $wwwrootfromcfg = (string) $paths['wwwroot'];
            }
        }
    }
    if ($registrydir === '') {
        $registrydir = rtrim($dataroot, "/\\\0") . '/multitenancy';
    }
}

// Optional override in config.php: define('MULTITENANCY_REGISTRY_DIR', '...');
if (preg_match("/define\s*\(\s*['\"]MULTITENANCY_REGISTRY_DIR['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configsrc, $m)) {
    $registrydir = rtrim((string) $m[1], "/\\\0");
}

if ($registrydir === '' || !is_dir($registrydir)) {
    local_multitenancy_gateway_fail(
        "Multitenancy gateway: registry directory missing.\n" .
        "dataroot=" . ($dataroot !== '' ? $dataroot : '(not parsed)') . "\n" .
        "Open parent Manage tenants to regenerate registry.\n",
        503
    );
}

$registryfile = $registrydir . '/registry.php';
if (!is_readable($registryfile)) {
    local_multitenancy_gateway_fail(
        "Multitenancy gateway: registry.php not readable:\n{$registryfile}\n",
        503
    );
}

/** @var array $map */
$map = include $registryfile;
if (!is_array($map)) {
    local_multitenancy_gateway_fail('Multitenancy gateway: registry.php is invalid.', 503);
}

$tenant = null;
foreach ($map as $row) {
    if (is_array($row) && !empty($row['shortcode']) && (string) $row['shortcode'] === $code) {
        $tenant = $row;
        break;
    }
}
if ($tenant === null || empty($tenant['enabled'])) {
    local_multitenancy_gateway_fail(
        "Multitenancy: no enabled tenant \"{$code}\" in registry.\n" .
        "Add/enable the tenant on the parent site and save.\n",
        404
    );
}

$status = (string) ($tenant['provisionstatus'] ?? 'complete');
if ($status !== '' && $status !== 'complete') {
    local_multitenancy_gateway_fail(
        "Multitenancy: tenant \"{$code}\" is not ready yet (status: {$status}).\n" .
        "Wait for provisioning to complete on the parent site.\n",
        503
    );
}

// Optional: block entry when DB is behind codebase (same rules as bootstrap).
try {
    if (function_exists('local_multitenancy_tenant_needs_code_upgrade')
            && local_multitenancy_tenant_needs_code_upgrade($tenant)) {
        if (function_exists('local_multitenancy_queue_tenant_upgrade_flag')) {
            local_multitenancy_queue_tenant_upgrade_flag($code);
        }
        local_multitenancy_gateway_fail(
            "Multitenancy: tenant \"{$code}\" is being upgraded automatically.\n" .
            "Run on parent: php local/multitenancy/cli/upgrade_tenants.php --run\n" .
            "(עברית) הדייר משודרג אוטומטית — המתינו או הריצו שדרוג דיירים מהאב.\n",
            503
        );
    }
} catch (Throwable $e) {
    // Ignore probe failures; still allow cookie redirect.
}

$publicwww = null;
// Derive from this request path (handles subdirectory installs).
$needle = '/local/multitenancy/users/' . $code;
$suffix = $needle . '/index.php';
$pathprefix = null;
foreach (['SCRIPT_NAME', 'PHP_SELF'] as $key) {
    if (empty($_SERVER[$key])) {
        continue;
    }
    $script = str_replace('\\', '/', (string) $_SERVER[$key]);
    if (($pos = strpos($script, $suffix)) !== false) {
        $pathprefix = $pos > 0 ? substr($script, 0, $pos) : '';
        break;
    }
}
if ($pathprefix === null) {
    foreach (['REQUEST_URI', 'REDIRECT_URL'] as $key) {
        $path = parse_url($_SERVER[$key] ?? '', PHP_URL_PATH);
        $path = str_replace('\\', '/', is_string($path) ? $path : '');
        if ($path !== '' && ($pos = strpos($path, $needle)) !== false) {
            $pathprefix = $pos > 0 ? substr($path, 0, $pos) : '';
            break;
        }
    }
}
if ($pathprefix !== null) {
    $https = local_multitenancy_gateway_is_https();
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '') {
        $publicwww = rtrim($scheme . '://' . $host . $pathprefix, '/');
    }
}

if ($publicwww === null && $wwwrootfromcfg !== '') {
    $publicwww = local_multitenancy_normalize_public_wwwroot($wwwrootfromcfg);
}
if ($publicwww === null && !empty($tenant['wwwroot'])) {
    $publicwww = local_multitenancy_normalize_public_wwwroot((string) $tenant['wwwroot']);
}
if ($publicwww === null || !preg_match('#\Ahttps?://.#iu', $publicwww)) {
    local_multitenancy_gateway_fail(
        "Multitenancy gateway: could not determine public wwwroot for \"{$code}\".\n" .
        "Check \$CFG->wwwroot in config.php and tenant wwwroot in Manage tenants.\n",
        500
    );
}
$publicwww = rtrim($publicwww, '/');

local_multitenancy_gateway_set_cookie(LOCAL_MULTITENANCY_COOKIE, $code);
local_multitenancy_gateway_set_cookie(LOCAL_MULTITENANCY_WWWROOT_COOKIE, rawurlencode($publicwww));

$target = $publicwww . '/';
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Location: ' . $target, true, 303);
    exit(0);
}

$safe = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
echo '<meta http-equiv="refresh" content="0;url=' . $safe . '">';
echo '<title>Redirecting</title></head><body>';
echo '<p><a href="' . $safe . '">Continue to ' . $safe . '</a></p>';
echo '</body></html>';
exit(0);
