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

namespace local_multitenancy;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only checks for tenant gateway / white-screen issues (used by CLI).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gateway_diagnostic {

    public const LEVEL_OK = 'ok';
    public const LEVEL_WARN = 'warn';
    public const LEVEL_ERROR = 'error';

    /**
     * @param string $shortcode
     * @return array[] list of ['level'=>string,'key'=>string,'detail'=>string]
     */
    public static function run(string $shortcode): array {
        global $CFG;

        $out = [];
        $shortcode = trim($shortcode);
        if ($shortcode === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $shortcode)) {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'invalidshortcode',
                'detail' => $shortcode,
            ];
            return $out;
        }

        $registrydir = '';
        if (function_exists('local_multitenancy_resolve_registry_dir')) {
            global $CFG;
            $registrydir = local_multitenancy_resolve_registry_dir($CFG);
        } else if (defined('MULTITENANCY_REGISTRY_DIR') && MULTITENANCY_REGISTRY_DIR) {
            $registrydir = rtrim((string) MULTITENANCY_REGISTRY_DIR, '/\\');
        }
        if ($registrydir === '') {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'registrydirundefined',
                'detail' => '',
            ];
            return $out;
        }

        $registryfile = $registrydir . '/registry.php';

        if (!is_dir($registrydir)) {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'registrydirmissing',
                'detail' => $registrydir,
            ];
            return $out;
        }
        if (!file_exists($registryfile)) {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'registryfilenotfound',
                'detail' => $registryfile,
            ];
            return $out;
        }
        if (!is_readable($registryfile)) {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'registryfilenotreadable',
                'detail' => $registryfile,
            ];
            return $out;
        }

        $out[] = [
            'level' => self::LEVEL_OK,
            'key' => 'registryfileok',
            'detail' => $registryfile,
        ];

        /** @var array $map */
        $map = include $registryfile;
        if (!is_array($map) || $map === []) {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'registryempty',
                'detail' => '',
            ];
            return $out;
        }

        $tenant = null;
        foreach ($map as $row) {
            if (is_array($row) && !empty($row['shortcode']) && $row['shortcode'] === $shortcode) {
                $tenant = $row;
                break;
            }
        }

        if ($tenant === null) {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'tenantnotinregistry',
                'detail' => $shortcode,
            ];
            return $out;
        }

        if (empty($tenant['enabled'])) {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'tenantdisabled',
                'detail' => $shortcode,
            ];
        } else {
            $out[] = [
                'level' => self::LEVEL_OK,
                'key' => 'tenantenabled',
                'detail' => $shortcode,
            ];
        }

        $wwwrootraw = isset($tenant['wwwroot']) ? (string) $tenant['wwwroot'] : '';
        $wwwroot = \local_multitenancy_normalize_public_wwwroot($wwwrootraw);
        if (!preg_match('#\Ahttps?://.#iu', $wwwroot)) {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'wwwrootinvalid',
                'detail' => $wwwrootraw,
            ];
        } else {
            $out[] = [
                'level' => self::LEVEL_OK,
                'key' => 'wwwrootok',
                'detail' => $wwwroot,
            ];
        }

        $dataroot = isset($tenant['dataroot']) ? (string) $tenant['dataroot'] : '';
        if (!self::is_absolute_path($dataroot)) {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'datarootnotabsolute',
                'detail' => $dataroot,
            ];
        } else {
            $out[] = [
                'level' => self::LEVEL_OK,
                'key' => 'datarootabsolute',
                'detail' => $dataroot,
            ];
            if (!is_dir($dataroot)) {
                $out[] = [
                    'level' => self::LEVEL_ERROR,
                    'key' => 'datarootmissing',
                    'detail' => $dataroot,
                ];
            } else if (!is_writable($dataroot)) {
                $out[] = [
                    'level' => self::LEVEL_WARN,
                    'key' => 'datarootnotwritable',
                    'detail' => $dataroot,
                ];
            } else {
                $out[] = [
                    'level' => self::LEVEL_OK,
                    'key' => 'datarootok',
                    'detail' => $dataroot,
                ];
            }
        }

        $gdir = $CFG->dirroot . '/local/multitenancy/users/' . $shortcode;
        $gindex = $gdir . '/index.php';
        if (!is_readable($gindex)) {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'gatewayindexmissing',
                'detail' => $gindex,
            ];
        } else {
            $out[] = [
                'level' => self::LEVEL_OK,
                'key' => 'gatewayindexok',
                'detail' => $gindex,
            ];
            $expected = gateway_manager::index_file_contents();
            $actual = file_get_contents($gindex);
            if ($actual !== $expected) {
                $out[] = [
                    'level' => self::LEVEL_WARN,
                    'key' => 'gatewaystuboutdated',
                    'detail' => '',
                ];
            }
        }

        $dbmsg = self::test_database($tenant);
        if ($dbmsg === null) {
            $out[] = [
                'level' => self::LEVEL_OK,
                'key' => 'dbconnectok',
                'detail' => (string) ($tenant['dbname'] ?? ''),
            ];
        } else if (strpos($dbmsg, 'skip:') === 0) {
            $out[] = [
                'level' => self::LEVEL_WARN,
                'key' => 'dbskipped',
                'detail' => substr($dbmsg, 6),
            ];
        } else {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'dbconnectfail',
                'detail' => $dbmsg,
                'fix' => 'fix_dbconnect',
            ];
        }

        // Deep checks using the parent DB tenant row when available.
        $dbtenant = self::load_tenant_record($shortcode);
        if ($dbtenant) {
            $status = (string) ($dbtenant->provisionstatus ?? '');
            $out[] = [
                'level' => ($status === tenant_provisioner::STATUS_COMPLETE) ? self::LEVEL_OK : self::LEVEL_WARN,
                'key' => 'provisionstatus',
                'detail' => $status !== '' ? $status : '(empty)',
            ];
            if (!empty($dbtenant->provisionerror)) {
                $out[] = [
                    'level' => self::LEVEL_ERROR,
                    'key' => 'provisionerror',
                    'detail' => (string) $dbtenant->provisionerror,
                    'fix' => 'fix_reprovision',
                ];
            }

            $installed = database_provisioner::moodle_is_installed($dbtenant);
            if ($installed) {
                $out[] = [
                    'level' => self::LEVEL_OK,
                    'key' => 'moodleinstalled',
                    'detail' => (string) $dbtenant->dbname,
                ];
            } else {
                $out[] = [
                    'level' => self::LEVEL_ERROR,
                    'key' => 'moodlenotinstalled',
                    'detail' => (string) $dbtenant->dbname,
                    'fix' => 'fix_moodlenotinstalled',
                ];
                if ($status === tenant_provisioner::STATUS_COMPLETE) {
                    $out[] = [
                        'level' => self::LEVEL_ERROR,
                        'key' => 'redirectlooprisk',
                        'detail' => $shortcode,
                        'fix' => 'fix_redirectloop',
                    ];
                }
            }

            $cliok = self::cli_tools_available((string) ($dbtenant->dbtype ?? ''));
            if (!$cliok && in_array((string) ($dbtenant->dbtype ?? ''), ['pgsql'], true)) {
                $out[] = [
                    'level' => self::LEVEL_WARN,
                    'key' => 'pgdumpmissing',
                    'detail' => 'pg_dump/psql',
                    'fix' => 'fix_pgdump',
                ];
            } else if ($cliok) {
                $out[] = [
                    'level' => self::LEVEL_OK,
                    'key' => 'clitoolsok',
                    'detail' => (string) ($dbtenant->dbtype ?? ''),
                ];
            }
        } else {
            $out[] = [
                'level' => self::LEVEL_WARN,
                'key' => 'tenantdbrowmissing',
                'detail' => $shortcode,
            ];
        }

        $out[] = [
            'level' => self::LEVEL_OK,
            'key' => 'hintweb',
            'detail' => $CFG->wwwroot . '/local/multitenancy/users/' . rawurlencode($shortcode) . '/',
        ];
        $out[] = [
            'level' => self::LEVEL_OK,
            'key' => 'hintleave',
            'detail' => $CFG->wwwroot . '/local/multitenancy/leave.php',
        ];

        return $out;
    }

    /**
     * @param string $shortcode
     * @return \stdClass|null
     */
    protected static function load_tenant_record(string $shortcode): ?\stdClass {
        global $DB;
        try {
            $rec = $DB->get_record('local_multitenancy_tenant', ['shortcode' => $shortcode]);
            return $rec ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param string $dbtype
     * @return bool
     */
    protected static function cli_tools_available(string $dbtype): bool {
        if ($dbtype === 'pgsql') {
            return self::has_command('pg_dump') && self::has_command('psql');
        }
        if (in_array($dbtype, ['mysqli', 'mariadb', 'auroramysql'], true)) {
            return self::has_command('mysqldump') && self::has_command('mysql');
        }
        return false;
    }

    /**
     * @param string $cmd
     * @return bool
     */
    protected static function has_command(string $cmd): bool {
        $result = trim((string) shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null'));
        return $result !== '';
    }

    /**
     * @param string $path
     * @return bool
     */
    public static function is_absolute_path(string $path): bool {
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
     * @param array $tenant
     * @return string|null error message or null if OK / skipped
     */
    protected static function test_database(array $tenant): ?string {
        $dbtype = $tenant['dbtype'] ?? 'mysqli';
        if (in_array($dbtype, ['pgsql'], true)) {
            if (!function_exists('pg_connect')) {
                return 'pgsql extension not loaded';
            }
            // Prefer DB row (fresh credentials) when available.
            $dbtenant = null;
            if (!empty($tenant['shortcode'])) {
                $dbtenant = self::load_tenant_record((string) $tenant['shortcode']);
            }
            if ($dbtenant) {
                $result = \local_multitenancy\db\postgres_helper::connect_tenant_with_error(
                    $dbtenant,
                    (string) $dbtenant->dbname
                );
            } else {
                $host = (string) ($tenant['dbhost'] ?? '');
                $user = (string) ($tenant['dbuser'] ?? '');
                $pass = (string) ($tenant['dbpass'] ?? '');
                $name = (string) ($tenant['dbname'] ?? '');
                $opts = [];
                if (!empty($tenant['dboptions']) && is_array($tenant['dboptions'])) {
                    $opts = $tenant['dboptions'];
                }
                $result = \local_multitenancy\db\postgres_helper::connect_with_error($host, $user, $pass, $name, $opts);
            }
            if (!$result['conn']) {
                // Also probe maintenance DBs to explain TEMPLATE failures.
                $host = (string) ($dbtenant->dbhost ?? $tenant['dbhost'] ?? '');
                $user = (string) ($dbtenant->dbuser ?? $tenant['dbuser'] ?? '');
                $pass = (string) ($dbtenant->dbpass ?? $tenant['dbpass'] ?? '');
                $opts = $dbtenant
                    ? \local_multitenancy\db\postgres_helper::dboptions_for_tenant($dbtenant)
                    : (is_array($tenant['dboptions'] ?? null) ? $tenant['dboptions'] : []);
                $maint = \local_multitenancy\db\postgres_helper::connect_maintenance($host, $user, $pass, $opts, null);
                $extra = $maint['conn']
                    ? ('Maintenance OK via ' . $maint['dbname'] . ' — tenant DB may be missing.')
                    : ('Maintenance also failed: ' . $maint['error']);
                if ($maint['conn']) {
                    pg_close($maint['conn']);
                }
                return $result['error'] . ' || ' . $extra;
            }
            pg_close($result['conn']);
            return null;
        }

        if (!in_array($dbtype, ['mysqli', 'mariadb', 'auroramysql'], true)) {
            return 'skip:' . $dbtype;
        }
        if (!extension_loaded('mysqli')) {
            return 'mysqli extension not loaded';
        }

        $host = $tenant['dbhost'] ?? 'localhost';
        $user = $tenant['dbuser'] ?? '';
        $pass = $tenant['dbpass'] ?? '';
        $name = $tenant['dbname'] ?? '';
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

        mysqli_report(MYSQLI_REPORT_OFF);
        if ($socket) {
            $mysqli = @new \mysqli(null, $user, $pass, $name, $port, $socket);
        } else {
            $mysqli = @new \mysqli($host, $user, $pass, $name, $port);
        }
        if ($mysqli->connect_errno) {
            return $mysqli->connect_error . ' (errno ' . $mysqli->connect_errno . ')';
        }
        $mysqli->close();
        return null;
    }
}
