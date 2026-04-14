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

/** Cookie name for sticky tenant when using path gateways (must match gateway_manager). */
define('LOCAL_MULTITENANCY_COOKIE', 'local_mt_sc');

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
 * @param stdClass $cfg
 * @param array $tenant
 * @param bool $setcookiefromentry
 * @return void
 */
function local_multitenancy_apply_tenant(stdClass $cfg, array $tenant, bool $setcookiefromentry): void {
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

    if ($setcookiefromentry && !headers_sent()) {
        $secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        if (PHP_VERSION_ID >= 70300) {
            setcookie(LOCAL_MULTITENANCY_COOKIE, (string) $tenant['shortcode'], [
                'expires' => 0,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie(LOCAL_MULTITENANCY_COOKIE, (string) $tenant['shortcode'], 0, '/', '', $secure, true);
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

    // Path gateways (/local/multitenancy/users/{shortcode}/) and cookie — before HTTP_HOST so many
    // tenants can share the same public host as the parent.
    if (defined('LOCAL_MULTITENANCY_ENTRY_SHORTCODE')) {
        $code = (string) LOCAL_MULTITENANCY_ENTRY_SHORTCODE;
        if ($code !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
            $candidate = local_multitenancy_registry_row_by_shortcode($map, $code);
            if ($candidate && !empty($candidate['enabled'])) {
                $tenant = $candidate;
                $setcookie = true;
            }
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
