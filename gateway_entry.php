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
 * Does NOT load Moodle setup.php or version.php (version.php dies without
 * MOODLE_INTERNAL — that was the blank HTTP 200 on ?code=...).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatals, true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "\n[PHP fatal in gateway_entry] ", $err['message'],
        ' in ', $err['file'], ':', $err['line'], "\n";
});

require_once(__DIR__ . '/wwwroot_lib.php');

/**
 * @param string $message
 * @param int $status
 * @return void
 */
function local_multitenancy_gateway_fail(string $message, int $status = 500): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo $message;
    if (substr($message, -1) !== "\n") {
        echo "\n";
    }
    exit(1);
}

/**
 * @param string $step
 * @return void
 */
function local_multitenancy_gateway_step(string $step): void {
    if (!headers_sent()) {
        header('X-MT-Gateway-Step: ' . $step);
    }
}

/**
 * @param string $start
 * @return string
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
 * Read a simple quoted $CFG->name = '...'; assignment.
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
 * Evaluate config.php only up to Moodle setup / multitenancy bootstrap.
 * Handles dataroot built with __DIR__, concatenation, or getenv() — not only a quoted literal.
 *
 * @param string $cfgroot Directory that contains config.php
 * @return array{dataroot:string, wwwroot:string, error:string}
 */
function local_multitenancy_gateway_load_cfg(string $cfgroot): array {
    $empty = ['dataroot' => '', 'wwwroot' => '', 'error' => ''];
    $path = $cfgroot . DIRECTORY_SEPARATOR . 'config.php';
    $src = @file_get_contents($path);
    if (!is_string($src) || $src === '') {
        $empty['error'] = 'cannot read config.php';
        return $empty;
    }

    $src = preg_replace('/^\s*<\?php\s*/', '', $src, 1) ?? $src;
    $src = preg_replace('/\bdeclare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', '', $src) ?? $src;
    $src = preg_replace('/\b__DIR__\b/', var_export($cfgroot, true), $src) ?? $src;
    $src = preg_replace('/\b__FILE__\b/', var_export($path, true), $src) ?? $src;

    // Stop before bootstrap/setup. [^;]* crosses newlines so a require split over
    // several lines is removed too. Do not define cookie constants before this eval:
    // bootstrap.php defines them, and a warning used to abort before $CFG was kept.
    $cut = null;
    $markers = [
        '/\b(?:require|include)(?:_once)?\b[^;]*bootstrap\.php/s',
        '/\blocal_multitenancy_bootstrap\s*\(/',
        '/\b(?:require|include)(?:_once)?\b[^;]*\/lib\/setup\.php/s',
    ];
    foreach ($markers as $marker) {
        if (!preg_match($marker, $src, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $pos = (int) $m[0][1];
        if ($cut === null || $pos < $cut) {
            $cut = $pos;
        }
    }
    if ($cut !== null) {
        $src = substr($src, 0, $cut);
    }

    $cfg = null;
    $error = '';
    $handler = static function (int $severity, string $message): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity);
    };
    set_error_handler($handler);
    try {
        eval($src);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    restore_error_handler();
    // Keep assignments made before a later warning (for example a redefined constant).
    if (isset($CFG) && is_object($CFG)) {
        $cfg = $CFG;
    }

    $dataroot = ($cfg && !empty($cfg->dataroot)) ? (string) $cfg->dataroot : '';
    $wwwroot = ($cfg && !empty($cfg->wwwroot)) ? (string) $cfg->wwwroot : '';
    if ($dataroot === '' && $error === '') {
        $error = 'dataroot was not set before setup.php';
    }
    return ['dataroot' => $dataroot, 'wwwroot' => $wwwroot, 'error' => $error];
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

local_multitenancy_gateway_step('start');

$code = '';
if (!empty($_GET['code']) && is_string($_GET['code'])) {
    $code = (string) $_GET['code'];
} else if (defined('LOCAL_MULTITENANCY_ENTRY_SHORTCODE')) {
    $code = (string) LOCAL_MULTITENANCY_ENTRY_SHORTCODE;
} else if (!empty($_SERVER['SCRIPT_FILENAME'])) {
    $base = basename(dirname((string) $_SERVER['SCRIPT_FILENAME']));
    if ($base !== 'users' && $base !== 'multitenancy') {
        $code = $base;
    }
}
$code = trim($code);

if ($code === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
    local_multitenancy_gateway_fail(
        "Multitenancy gateway: invalid or missing tenant code.\n" .
        "Use /local/multitenancy/users/{code}/ or gateway_entry.php?code={code}\n",
        400
    );
}

local_multitenancy_gateway_step('code:' . $code);

$cfgroot = local_multitenancy_gateway_find_cfgroot(dirname(__DIR__, 2));
if ($cfgroot === '') {
    $cfgroot = local_multitenancy_gateway_find_cfgroot(__DIR__);
}
if ($cfgroot === '') {
    local_multitenancy_gateway_fail('Multitenancy gateway: Moodle config.php not found.');
}

$configsrc = (string) @file_get_contents($cfgroot . '/config.php');
if ($configsrc === '') {
    local_multitenancy_gateway_fail('Multitenancy gateway: cannot read config.php at ' . $cfgroot . '/config.php');
}

$loadedcfg = local_multitenancy_gateway_load_cfg($cfgroot);
$dataroot = $loadedcfg['dataroot'];
$wwwrootfromcfg = $loadedcfg['wwwroot'];
if ($dataroot === '') {
    $dataroot = local_multitenancy_gateway_parse_cfg_string($configsrc, 'dataroot');
}
if ($wwwrootfromcfg === '') {
    $wwwrootfromcfg = local_multitenancy_gateway_parse_cfg_string($configsrc, 'wwwroot');
}
$cfgloaderror = ($dataroot === '') ? $loadedcfg['error'] : '';

local_multitenancy_gateway_step('config-parsed');

$registrydir = '';
if ($dataroot !== '') {
    $pathsfile = rtrim($dataroot, "/\\\0") . '/multitenancy/gateway_paths.php';
    if (is_readable($pathsfile)) {
        /** @var mixed $paths */
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

if (preg_match("/define\s*\(\s*['\"]MULTITENANCY_REGISTRY_DIR['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $configsrc, $m)) {
    $registrydir = rtrim((string) $m[1], "/\\\0");
}

if ($registrydir === '' || !is_dir($registrydir)) {
    local_multitenancy_gateway_fail(
        "Multitenancy gateway: registry directory missing.\n" .
        "config.php=" . $cfgroot . "/config.php\n" .
        "dataroot=" . ($dataroot !== '' ? $dataroot : '(not parsed from config.php)') . "\n" .
        ($cfgloaderror !== '' ? "detail=" . $cfgloaderror . "\n" : '') .
        "registrydir=" . ($registrydir !== '' ? $registrydir : '(empty)') . "\n" .
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

local_multitenancy_gateway_step('registry');

/** @var mixed $map */
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

local_multitenancy_gateway_step('tenant-ok');

// Do NOT call upgrade_lib / include Moodle version.php here — version.php does
// defined('MOODLE_INTERNAL') || die(); which produces a blank HTTP 200.

$publicwww = null;
if ($wwwrootfromcfg !== '') {
    $publicwww = local_multitenancy_normalize_public_wwwroot($wwwrootfromcfg);
}
if (($publicwww === null || $publicwww === '') && !empty($tenant['wwwroot'])) {
    $publicwww = local_multitenancy_normalize_public_wwwroot((string) $tenant['wwwroot']);
}
if ($publicwww === null || $publicwww === '' || !preg_match('#\Ahttps?://.#iu', $publicwww)) {
    // Last resort: current host (gateway_entry.php lives at site root path).
    $https = local_multitenancy_gateway_is_https();
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '') {
        $publicwww = $scheme . '://' . $host;
    }
}
if ($publicwww === null || $publicwww === '' || !preg_match('#\Ahttps?://.#iu', $publicwww)) {
    local_multitenancy_gateway_fail(
        "Multitenancy gateway: could not determine public wwwroot for \"{$code}\".\n" .
        "Check \$CFG->wwwroot in config.php.\n",
        500
    );
}
$publicwww = rtrim($publicwww, '/');

local_multitenancy_gateway_step('redirect');

if (!defined('LOCAL_MULTITENANCY_COOKIE')) {
    define('LOCAL_MULTITENANCY_COOKIE', 'local_mt_sc');
}
if (!defined('LOCAL_MULTITENANCY_WWWROOT_COOKIE')) {
    define('LOCAL_MULTITENANCY_WWWROOT_COOKIE', 'local_mt_wr');
}

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
