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
            $decoded = json_decode((string) $tenant->dboptions, true);
            if (is_array($decoded)) {
                $opts = $decoded;
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
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param string $dbname
     * @param array $dboptions
     * @return string
     */
    public static function build_conninfo(string $host, string $user, string $pass, string $dbname, array $dboptions = []): string {
        $parts = [];
        if ($host !== '') {
            $parts[] = "host='" . str_replace("'", "\\'", $host) . "'";
        }
        if (!empty($dboptions['dbport'])) {
            $parts[] = 'port=' . (int) $dboptions['dbport'];
        }
        if (!empty($dboptions['dbsocket'])) {
            $parts[] = "host='" . str_replace("'", "\\'", (string) $dboptions['dbsocket']) . "'";
        }
        if ($user !== '') {
            $parts[] = "user='" . str_replace("'", "\\'", $user) . "'";
        }
        if ($pass !== '') {
            $parts[] = "password='" . str_replace("'", "\\'", $pass) . "'";
        }
        $parts[] = "dbname='" . str_replace("'", "\\'", $dbname) . "'";
        return implode(' ', $parts);
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
        if (!function_exists('pg_connect')) {
            return false;
        }
        return @pg_connect(self::build_conninfo($host, $user, $pass, $dbname, $dboptions));
    }

    /**
     * @param \stdClass $tenant
     * @param string $dbname
     * @return resource|false
     */
    public static function connect_tenant(\stdClass $tenant, string $dbname) {
        return self::connect(
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
     * CLI args for pg_dump/psql with port support.
     *
     * @param string $host
     * @param string $user
     * @param string $dbname
     * @param array $dboptions
     * @return string[]
     */
    public static function build_cli_args(string $host, string $user, string $dbname, array $dboptions = []): array {
        $args = [];
        if ($host !== '') {
            $args[] = escapeshellarg('--host=' . $host);
        }
        if (!empty($dboptions['dbport'])) {
            $args[] = escapeshellarg('--port=' . (int) $dboptions['dbport']);
        }
        if ($user !== '') {
            $args[] = escapeshellarg('--username=' . $user);
        }
        $args[] = escapeshellarg('--dbname=' . $dbname);
        $args[] = escapeshellarg('--no-password');
        return $args;
    }
}
