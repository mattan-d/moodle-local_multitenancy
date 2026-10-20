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
 * Early helpers for tenant upgrade detection (safe before Moodle setup).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Moodle root (directory that contains version.php).
 *
 * @return string
 */
function local_multitenancy_moodle_root(): string {
    return dirname(__DIR__, 2);
}

/**
 * Core $version from disk version.php.
 *
 * @return string Empty when unavailable.
 */
function local_multitenancy_moodle_code_version(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $path = local_multitenancy_moodle_root() . '/version.php';
    if (!is_readable($path)) {
        $cached = '';
        return $cached;
    }
    // Never include version.php without MOODLE_INTERNAL — it calls die() with no output (blank page).
    $src = (string) @file_get_contents($path);
    if (preg_match('/\$version\s*=\s*([0-9.]+)\s*;/', $src, $m)) {
        $cached = (string) $m[1];
        return $cached;
    }
    $cached = '';
    return $cached;
}

/**
 * Path to parent allversionshash snapshot written by the parent site.
 *
 * @return string
 */
function local_multitenancy_parent_versions_hash_file(): string {
    $dir = '';
    if (defined('LOCAL_MULTITENANCY_PARENT_DATAROOT') && LOCAL_MULTITENANCY_PARENT_DATAROOT) {
        $dir = rtrim((string) LOCAL_MULTITENANCY_PARENT_DATAROOT, "/\\\0") . '/multitenancy';
    } else if (defined('MULTITENANCY_REGISTRY_DIR') && MULTITENANCY_REGISTRY_DIR) {
        $dir = rtrim((string) MULTITENANCY_REGISTRY_DIR, "/\\\0");
    } else if (function_exists('local_multitenancy_resolve_registry_dir')) {
        $dir = local_multitenancy_resolve_registry_dir(null);
    }
    if ($dir === '') {
        return '';
    }
    return $dir . '/parent_allversionshash.txt';
}

/**
 * @return string
 */
function local_multitenancy_read_parent_versions_hash(): string {
    $file = local_multitenancy_parent_versions_hash_file();
    if ($file === '' || !is_readable($file)) {
        return '';
    }
    return trim((string) @file_get_contents($file));
}

/**
 * Directory for upgrade request flags (parent moodledata).
 *
 * @return string
 */
function local_multitenancy_upgrade_queue_dir(): string {
    $file = local_multitenancy_parent_versions_hash_file();
    if ($file === '') {
        return '';
    }
    return dirname($file) . '/upgrade_queue';
}

/**
 * Request an automatic CLI upgrade for a tenant (picked up by parent cron).
 *
 * @param string $shortcode
 * @return void
 */
function local_multitenancy_queue_tenant_upgrade_flag(string $shortcode): void {
    $dir = local_multitenancy_upgrade_queue_dir();
    if ($dir === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $shortcode)) {
        return;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    @file_put_contents($dir . '/' . $shortcode . '.flag', (string) time());
}

/**
 * Read a single config value from the tenant database (no Moodle $DB).
 *
 * @param array $tenant Registry-like tenant row
 * @param string $name
 * @return string|null
 */
function local_multitenancy_read_tenant_config_value(array $tenant, string $name): ?string {
    $dbname = (string) ($tenant['dbname'] ?? '');
    $prefix = (string) ($tenant['prefix'] ?? 'mdl_');
    $dbtype = (string) ($tenant['dbtype'] ?? '');
    $dbhost = (string) ($tenant['dbhost'] ?? '');
    $dbuser = (string) ($tenant['dbuser'] ?? '');
    $dbpass = (string) ($tenant['dbpass'] ?? '');
    $name = (string) $name;
    if ($dbname === '' || $name === '') {
        return null;
    }
    $dboptions = [];
    if (!empty($tenant['dboptions']) && is_array($tenant['dboptions'])) {
        $dboptions = $tenant['dboptions'];
    }

    if ($dbtype === 'pgsql') {
        if (!function_exists('pg_connect')) {
            return null;
        }
        $passescaped = addcslashes($dbpass, "'\\");
        $userescaped = addcslashes($dbuser, "'\\");
        $dbnameescaped = addcslashes($dbname, "'\\");
        $dbsocket = $dboptions['dbsocket'] ?? '';
        if (!empty($dbsocket) && ($dbhost === 'localhost' || $dbhost === '127.0.0.1')) {
            $connection = "user='{$userescaped}' password='{$passescaped}' dbname='{$dbnameescaped}'";
            if (is_string($dbsocket) && strpos($dbsocket, '/') !== false) {
                $connection .= " host='" . addcslashes($dbsocket, "'\\") . "'";
            }
            if (!empty($dboptions['dbport'])) {
                $connection .= " port='" . (int) $dboptions['dbport'] . "'";
            }
        } else {
            $port = empty($dboptions['dbport']) ? "port='5432'" : ("port='" . (int) $dboptions['dbport'] . "'");
            $connection = "host='" . addcslashes($dbhost, "'\\") . "' {$port} user='{$userescaped}' password='{$passescaped}' dbname='{$dbnameescaped}'";
        }
        $conn = @pg_connect($connection);
        if (!$conn) {
            return null;
        }
        $table = '"' . str_replace('"', '""', $prefix . 'config') . '"';
        $res = @pg_query_params($conn, 'SELECT value FROM ' . $table . ' WHERE name = $1 LIMIT 1', [$name]);
        $value = null;
        if ($res && ($row = pg_fetch_assoc($res)) && isset($row['value'])) {
            $value = (string) $row['value'];
        }
        if ($res) {
            pg_free_result($res);
        }
        pg_close($conn);
        return $value;
    }

    if (!in_array($dbtype, ['mysqli', 'mariadb', 'auroramysql'], true)) {
        return null;
    }
    if (!class_exists('mysqli', false) && !extension_loaded('mysqli')) {
        return null;
    }
    $port = 0;
    $socket = null;
    if (!empty($dboptions['dbport'])) {
        $port = (int) $dboptions['dbport'];
    }
    if (!empty($dboptions['dbsocket'])) {
        $socket = (string) $dboptions['dbsocket'];
    }
    if ($socket) {
        $conn = @new mysqli(null, $dbuser, $dbpass, $dbname, $port, $socket);
    } else {
        $conn = @new mysqli($dbhost, $dbuser, $dbpass, $dbname, $port);
    }
    if ($conn->connect_errno) {
        return null;
    }
    $table = '`' . str_replace('`', '``', $prefix . 'config') . '`';
    $stmt = $conn->prepare('SELECT value FROM ' . $table . ' WHERE name = ? LIMIT 1');
    if (!$stmt) {
        $conn->close();
        return null;
    }
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->bind_result($value);
    $found = $stmt->fetch();
    $stmt->close();
    $conn->close();
    return $found ? (string) $value : null;
}

/**
 * Moodle stores $version as 2024100704 or 2024100704.00 — treat those as the same release.
 *
 * @param string $left
 * @param string $right
 * @return bool
 */
function local_multitenancy_versions_match(string $left, string $right): bool {
    $left = trim($left);
    $right = trim($right);
    if ($left === '' || $right === '') {
        return false;
    }
    if ($left === $right) {
        return true;
    }
    if (is_numeric($left) && is_numeric($right)) {
        return abs((float) $left - (float) $right) < 0.001;
    }
    return false;
}

/**
 * Whether the tenant DB is behind the shared codebase (needs admin/cli/upgrade.php).
 *
 * @param array $tenant
 * @return bool
 */
function local_multitenancy_tenant_needs_code_upgrade(array $tenant): bool {
    $codeversion = local_multitenancy_moodle_code_version();
    if ($codeversion === '') {
        return false;
    }
    $dbversion = local_multitenancy_read_tenant_config_value($tenant, 'version');
    if ($dbversion === null || $dbversion === '') {
        // Not installed / unreadable — leave to provision/diagnose, not upgrade UI.
        return false;
    }
    if (!local_multitenancy_versions_match((string) $dbversion, (string) $codeversion)) {
        return true;
    }

    $parenthash = local_multitenancy_read_parent_versions_hash();
    if ($parenthash === '') {
        return false;
    }
    $tenanthash = local_multitenancy_read_tenant_config_value($tenant, 'allversionshash');
    if ($tenanthash === null || $tenanthash === '') {
        return true;
    }
    return $tenanthash !== $parenthash;
}

/**
 * Stop the request instead of showing Moodle's upgrade / plugins-check UI.
 *
 * @param string $code
 * @return void
 */
function local_multitenancy_abort_tenant_upgrade_pending(string $code): void {
    if (!headers_sent()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: 120');
    }
    echo "Multitenancy: tenant \"" . $code . "\" is waiting for automatic upgrade.\n\n";
    echo "Upgrades run on the parent site via the main Moodle cron:\n";
    echo "  php admin/cli/cron.php\n\n";
    echo "The scheduled task local_multitenancy\\task\\sync_parent_admins runs tenant\n";
    echo "upgrades (MOODLE_TENANT=… php admin/cli/upgrade.php --non-interactive).\n\n";
    echo "Please wait for cron, then refresh. Optional on parent:\n";
    echo "  php local/multitenancy/cli/upgrade_tenants.php --run\n\n";
    echo "(עברית) הדייר ממתין לשדרוג אוטומטי דרך cron.php של האב.\n";
    echo "הריצו: php admin/cli/cron.php  ואז רעננו את הדף.\n";
    exit(0);
}
