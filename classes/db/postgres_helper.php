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

namespace local_multitenancy\db;

defined('MOODLE_INTERNAL') || die();

/**
 * PostgreSQL connection helpers for multitenancy.
 *
 * Connection strings intentionally mirror Moodle core
 * {@see \pgsql_native_moodle_database::raw_connect()} so tenant clones use the
 * same host/socket/port/password rules as the parent site.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class postgres_helper {

    /**
     * @param \stdClass|null $tenant
     * @return array
     */
    public static function dboptions_for_tenant(?\stdClass $tenant): array {
        global $CFG;
        $opts = [];
        if ($tenant && !empty($tenant->dboptions)) {
            if (is_array($tenant->dboptions)) {
                $opts = $tenant->dboptions;
            } else {
                $decoded = json_decode((string) $tenant->dboptions, true);
                if (is_array($decoded)) {
                    $opts = $decoded;
                }
            }
        }
        if (!$opts && !empty($CFG->dboptions) && is_array($CFG->dboptions)) {
            $opts = $CFG->dboptions;
        }
        return $opts;
    }

    /**
     * @return array
     */
    public static function dboptions_for_parent(): array {
        global $CFG;
        return (!empty($CFG->dboptions) && is_array($CFG->dboptions)) ? $CFG->dboptions : [];
    }

    /**
     * Build a libpq connection string the same way Moodle core does.
     *
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param string $dbname
     * @param array $dboptions
     * @return string
     */
    public static function build_conninfo(string $host, string $user, string $pass, string $dbname, array $dboptions = []): string {
        $passescaped = addcslashes($pass, "'\\");
        $userescaped = addcslashes($user, "'\\");
        $dbnameescaped = addcslashes($dbname, "'\\");
        $dbsocket = $dboptions['dbsocket'] ?? '';

        // Match Moodle: unix socket when dbsocket is set and host is localhost/127.0.0.1.
        if (!empty($dbsocket) && ($host === 'localhost' || $host === '127.0.0.1')) {
            $connection = "user='{$userescaped}' password='{$passescaped}' dbname='{$dbnameescaped}'";
            if (is_string($dbsocket) && strpos($dbsocket, '/') !== false) {
                $connection .= " host='" . addcslashes($dbsocket, "'\\") . "'";
            }
            if (!empty($dboptions['dbport'])) {
                $connection .= " port='" . (int) $dboptions['dbport'] . "'";
            }
        } else {
            $port = '';
            if ($dbname !== '') {
                if (empty($dboptions['dbport'])) {
                    $port = "port='5432'";
                } else {
                    $port = "port='" . (int) $dboptions['dbport'] . "'";
                }
            }
            $hostescaped = addcslashes($host, "'\\");
            $connection = "host='{$hostescaped}' {$port} user='{$userescaped}' password='{$passescaped}' dbname='{$dbnameescaped}'";
        }

        if (!empty($dboptions['connecttimeout'])) {
            $connection .= ' connect_timeout=' . (int) $dboptions['connecttimeout'];
        }

        if (empty($dboptions['dbhandlesoptions'])) {
            $options = ['--client_encoding=utf8', '--standard_conforming_strings=on'];
            if (!empty($dboptions['dbschema'])) {
                $options[] = '-c search_path=' . addcslashes((string) $dboptions['dbschema'], "'\\");
            }
            $connection .= " options='" . implode(' ', $options) . "'";
        }

        if (!empty($dboptions['ssl'])) {
            $connection .= ' sslmode=' . preg_replace('/[^a-z]/', '', (string) $dboptions['ssl']);
        }

        return trim(preg_replace('/\s+/', ' ', $connection));
    }

    /**
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param string $dbname
     * @param array $dboptions
     * @return resource|false
     */
    public static function connect(string $host, string $user, string $pass, string $dbname, array $dboptions = []) {
        $result = self::connect_with_error($host, $user, $pass, $dbname, $dboptions);
        return $result['conn'];
    }

    /**
     * Connect and return a human-readable error when it fails.
     *
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param string $dbname
     * @param array $dboptions
     * @return array{conn: resource|false, error: string, conninfo_safe: string}
     */
    public static function connect_with_error(
        string $host,
        string $user,
        string $pass,
        string $dbname,
        array $dboptions = []
    ): array {
        if (!function_exists('pg_connect')) {
            return [
                'conn' => false,
                'error' => 'pgsql PHP extension not loaded',
                'conninfo_safe' => '',
            ];
        }

        $conninfo = self::build_conninfo($host, $user, $pass, $dbname, $dboptions);
        $safe = self::redact_conninfo($conninfo);

        ob_start();
        $conn = false;
        $dberr = '';
        try {
            $conn = @pg_connect($conninfo, PGSQL_CONNECT_FORCE_NEW);
            $dberr = trim((string) ob_get_contents());
        } catch (\Throwable $e) {
            $dberr = $e->getMessage();
        }
        ob_end_clean();

        $status = $conn ? pg_connection_status($conn) : false;
        if ($status === false || $status === PGSQL_CONNECTION_BAD) {
            if (is_resource($conn) || (is_object($conn) && $conn instanceof \PgSql\Connection)) {
                @pg_close($conn);
            }
            $err = $dberr !== '' ? $dberr : 'pg_connect returned false (check host/socket/port/user/password/dbname)';
            return [
                'conn' => false,
                'error' => $err . ' | conn=' . $safe,
                'conninfo_safe' => $safe,
            ];
        }

        return [
            'conn' => $conn,
            'error' => '',
            'conninfo_safe' => $safe,
        ];
    }

    /**
     * Connect for CREATE/DROP DATABASE. Prefers maintenance DBs the moodle role can access.
     *
     * Many locked-down installs grant CREATEDB but deny CONNECT on database "postgres".
     *
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param array $dboptions
     * @param string|null $preferdbname Optional first candidate (e.g. parent db for non-TEMPLATE ops).
     * @return array{conn: resource|false, error: string, dbname: string}
     */
    public static function connect_maintenance(
        string $host,
        string $user,
        string $pass,
        array $dboptions = [],
        ?string $preferdbname = null
    ): array {
        global $CFG;

        $candidates = [];
        if ($preferdbname !== null && $preferdbname !== '') {
            $candidates[] = $preferdbname;
        }
        $candidates[] = 'postgres';
        $candidates[] = 'template1';
        if (!empty($CFG->dbname) && !in_array((string) $CFG->dbname, $candidates, true)) {
            $candidates[] = (string) $CFG->dbname;
        }
        $candidates = array_values(array_unique($candidates));

        $errors = [];
        foreach ($candidates as $dbname) {
            $result = self::connect_with_error($host, $user, $pass, $dbname, $dboptions);
            if ($result['conn']) {
                return [
                    'conn' => $result['conn'],
                    'error' => '',
                    'dbname' => $dbname,
                ];
            }
            $errors[] = $dbname . ': ' . $result['error'];
        }

        return [
            'conn' => false,
            'error' => 'Could not open a PostgreSQL maintenance connection. Tried: ' .
                implode(' || ', $errors) .
                '. Grant CONNECT on database "postgres" (or template1) to the Moodle DB user, or ensure pg_dump/psql works.',
            'dbname' => '',
        ];
    }

    /**
     * @param \stdClass $tenant
     * @param string $dbname
     * @return resource|false
     */
    public static function connect_tenant(\stdClass $tenant, string $dbname) {
        $result = self::connect_tenant_with_error($tenant, $dbname);
        return $result['conn'];
    }

    /**
     * @param \stdClass $tenant
     * @param string $dbname
     * @return array{conn: resource|false, error: string, conninfo_safe: string}
     */
    public static function connect_tenant_with_error(\stdClass $tenant, string $dbname): array {
        return self::connect_with_error(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $dbname,
            self::dboptions_for_tenant($tenant)
        );
    }

    /**
     * @return resource|false
     */
    public static function connect_parent() {
        global $CFG;
        return self::connect(
            (string) $CFG->dbhost,
            (string) $CFG->dbuser,
            (string) $CFG->dbpass,
            (string) $CFG->dbname,
            self::dboptions_for_parent()
        );
    }

    /**
     * @param string $conninfo
     * @return string
     */
    public static function redact_conninfo(string $conninfo): string {
        return preg_replace("/password='[^']*'/", "password='***'", $conninfo) ?? $conninfo;
    }

    /**
     * @param string $name
     * @return string
     */
    public static function quote_ident(string $name): string {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * @param resource $conn
     * @param string $prefix Table prefix filter (e.g. mdl_).
     * @return string[]
     */
    public static function list_prefixed_tables($conn, string $prefix): array {
        $res = @pg_query($conn, "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
        if (!$res) {
            return [];
        }
        $tables = [];
        while ($row = pg_fetch_assoc($res)) {
            $name = (string) ($row['tablename'] ?? '');
            if ($name !== '' && strpos($name, $prefix) === 0) {
                $tables[] = $name;
            }
        }
        pg_free_result($res);
        return $tables;
    }

    /**
     * CLI args for pg_dump/psql with port/socket support aligned with Moodle.
     *
     * @param string $host
     * @param string $user
     * @param string $dbname
     * @param array $dboptions
     * @return string[]
     */
    public static function build_cli_args(string $host, string $user, string $dbname, array $dboptions = []): array {
        $args = [];
        $dbsocket = $dboptions['dbsocket'] ?? '';
        if (!empty($dbsocket) && ($host === 'localhost' || $host === '127.0.0.1')) {
            if (is_string($dbsocket) && strpos($dbsocket, '/') !== false) {
                $args[] = escapeshellarg('--host=' . $dbsocket);
            }
            // else: default unix socket — omit --host
        } else if ($host !== '') {
            $args[] = escapeshellarg('--host=' . $host);
        }
        if (!empty($dboptions['dbport'])) {
            $args[] = escapeshellarg('--port=' . (int) $dboptions['dbport']);
        } else if (empty($dbsocket) || ($host !== 'localhost' && $host !== '127.0.0.1')) {
            // TCP default matches Moodle raw_connect.
            if ($host !== '' && (empty($dbsocket) || strpos((string) $dbsocket, '/') === false)) {
                $args[] = escapeshellarg('--port=5432');
            }
        }
        if ($user !== '') {
            $args[] = escapeshellarg('--username=' . $user);
        }
        $args[] = escapeshellarg('--dbname=' . $dbname);
        $args[] = escapeshellarg('--no-password');
        return $args;
    }

    /**
     * Major version of the PostgreSQL server (e.g. 15 for 15.7), or null on failure.
     *
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param string $dbname
     * @param array $dboptions
     * @return int|null
     */
    public static function server_major_version(
        string $host,
        string $user,
        string $pass,
        string $dbname,
        array $dboptions = []
    ): ?int {
        $result = self::connect_with_error($host, $user, $pass, $dbname, $dboptions);
        if (!$result['conn']) {
            return null;
        }
        $conn = $result['conn'];
        $major = null;
        $res = @pg_query($conn, 'SHOW server_version_num');
        if ($res && ($row = pg_fetch_row($res)) && isset($row[0])) {
            // 150007 => 15
            $major = (int) floor(((int) $row[0]) / 10000);
        }
        if ($res) {
            pg_free_result($res);
        }
        pg_close($conn);
        return ($major !== null && $major > 0) ? $major : null;
    }

    /**
     * Resolve pg_dump/psql binaries compatible with the server major version.
     * Older clients (e.g. 13.x) cannot dump a newer server (e.g. 15.x).
     *
     * @param int|null $servermajor Required minimum client major; null = any found.
     * @return array{pg_dump:?string, psql:?string, pg_dump_version:?string, psql_version:?string, detail:string}
     */
    public static function resolve_compatible_cli_tools(?int $servermajor = null): array {
        $pgdump = self::resolve_pg_binary('pg_dump', $servermajor);
        $psql = self::resolve_pg_binary('psql', $servermajor);

        $detailparts = [];
        if ($servermajor !== null) {
            $detailparts[] = 'server_major=' . $servermajor;
        }
        $detailparts[] = 'pg_dump=' . ($pgdump['path'] ?? 'none') .
            ($pgdump['version'] ? ' (' . $pgdump['version'] . ')' : '');
        $detailparts[] = 'psql=' . ($psql['path'] ?? 'none') .
            ($psql['version'] ? ' (' . $psql['version'] . ')' : '');

        return [
            'pg_dump' => $pgdump['path'],
            'psql' => $psql['path'],
            'pg_dump_version' => $pgdump['version'],
            'psql_version' => $psql['version'],
            'detail' => implode('; ', $detailparts),
        ];
    }

    /**
     * @param string $cmd pg_dump|psql
     * @param int|null $servermajor
     * @return array{path:?string, version:?string, major:?int}
     */
    public static function resolve_pg_binary(string $cmd, ?int $servermajor = null): array {
        $candidates = self::list_pg_binary_candidates($cmd);
        $scored = [];
        foreach ($candidates as $path) {
            $ver = self::parse_pg_client_version($path);
            if ($ver === null) {
                continue;
            }
            // Client major must be >= server major (pg_dump 13 cannot dump PG 15).
            if ($servermajor !== null && $ver['major'] < $servermajor) {
                continue;
            }
            // Prefer exact major match, then closest higher major, then PATH order.
            $score = 0;
            if ($servermajor !== null) {
                $score = 1000 - abs($ver['major'] - $servermajor);
                if ($ver['major'] === $servermajor) {
                    $score += 100;
                }
            }
            $scored[] = [
                'path' => $path,
                'version' => $ver['label'],
                'major' => $ver['major'],
                'score' => $score,
            ];
        }

        if (!$scored) {
            return ['path' => null, 'version' => null, 'major' => null];
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });
        $best = $scored[0];
        return [
            'path' => $best['path'],
            'version' => $best['version'],
            'major' => $best['major'],
        ];
    }

    /**
     * @param string $cmd
     * @return string[] Absolute executable paths (unique, existing).
     */
    public static function list_pg_binary_candidates(string $cmd): array {
        $candidates = [];

        // Versioned installs first (more likely to match the server).
        foreach ([16, 15, 14, 13, 12] as $maj) {
            $candidates[] = '/usr/lib/postgresql/' . $maj . '/bin/' . $cmd;
            $candidates[] = '/usr/pgsql-' . $maj . '/bin/' . $cmd;
            $candidates[] = '/usr/pgsql-' . $maj . '.0/bin/' . $cmd;
            $candidates[] = '/opt/postgresql/' . $maj . '/bin/' . $cmd;
        }
        foreach (glob('/usr/lib/postgresql/*/bin/' . $cmd) ?: [] as $path) {
            $candidates[] = $path;
        }
        foreach (glob('/usr/pgsql-*/bin/' . $cmd) ?: [] as $path) {
            $candidates[] = $path;
        }
        foreach (glob('/opt/homebrew/opt/postgresql@*/bin/' . $cmd) ?: [] as $path) {
            $candidates[] = $path;
        }
        foreach (glob('/usr/local/opt/postgresql@*/bin/' . $cmd) ?: [] as $path) {
            $candidates[] = $path;
        }

        $which = trim((string) shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null'));
        if ($which !== '') {
            $candidates[] = $which;
        }
        $candidates = array_merge($candidates, [
            '/usr/bin/' . $cmd,
            '/usr/local/bin/' . $cmd,
            '/bin/' . $cmd,
            '/opt/homebrew/bin/' . $cmd,
            '/usr/lib/postgresql/bin/' . $cmd,
        ]);

        $out = [];
        $seen = [];
        foreach ($candidates as $path) {
            if (!is_string($path) || $path === '' || isset($seen[$path])) {
                continue;
            }
            if (!is_executable($path)) {
                continue;
            }
            $seen[$path] = true;
            $out[] = $path;
        }
        return $out;
    }

    /**
     * @param string $path
     * @return array{major:int, label:string}|null
     */
    public static function parse_pg_client_version(string $path): ?array {
        $out = [];
        $code = 0;
        exec(escapeshellarg($path) . ' --version 2>&1', $out, $code);
        $text = trim(implode(' ', $out));
        if ($text === '') {
            return null;
        }
        // "pg_dump (PostgreSQL) 15.7" / "psql (PostgreSQL) 13.11"
        if (!preg_match('/(\d+)\.(\d+)/', $text, $m)) {
            return null;
        }
        return [
            'major' => (int) $m[1],
            'label' => $m[1] . '.' . $m[2],
        ];
    }
}
