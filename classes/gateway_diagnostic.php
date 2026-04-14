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

        if (!defined('MULTITENANCY_REGISTRY_DIR') || !MULTITENANCY_REGISTRY_DIR) {
            $out[] = [
                'level' => self::LEVEL_ERROR,
                'key' => 'registrydirundefined',
                'detail' => '',
            ];
            return $out;
        }

        $registrydir = rtrim(MULTITENANCY_REGISTRY_DIR, '/\\');
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
            ];
        }

        $out[] = [
            'level' => self::LEVEL_OK,
            'key' => 'hintweb',
            'detail' => $CFG->wwwroot . '/local/multitenancy/users/' . rawurlencode($shortcode) . '/',
        ];

        return $out;
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
