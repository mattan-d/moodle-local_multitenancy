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
 * Provision tenant database from the parent Moodle database when it is empty.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class database_provisioner {

    /** @var string[] DB types that can be cloned via mysql/mysqldump. */
    private const MYSQL_FAMILY = ['mysqli', 'mariadb', 'auroramysql'];

    /**
     * @param \stdClass $tenant Tenant row from local_multitenancy_tenant.
     * @return array{state:string, detail:string}
     */
    public static function provision_if_empty(\stdClass $tenant): array {
        global $CFG;

        $dbtype = (string) ($tenant->dbtype ?? '');
        if (!in_array($dbtype, self::MYSQL_FAMILY, true)) {
            return ['state' => 'skipped_unsupported', 'detail' => $dbtype];
        }

        $tenantdbname = (string) ($tenant->dbname ?? '');
        if ($tenantdbname === '') {
            return ['state' => 'error', 'detail' => 'Empty tenant dbname'];
        }
        if ($tenantdbname === (string) $CFG->dbname) {
            return ['state' => 'error', 'detail' => 'Tenant DB equals parent DB'];
        }

        $tenantconn = @new \mysqli(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $tenantdbname
        );
        if ($tenantconn->connect_errno) {
            return ['state' => 'error', 'detail' => 'Tenant DB connect failed: ' . $tenantconn->connect_error];
        }

        $tablecount = self::count_tables($tenantconn, $tenantdbname);
        if ($tablecount === null) {
            $tenantconn->close();
            return ['state' => 'error', 'detail' => 'Could not inspect tenant DB tables'];
        }
        if ($tablecount > 0) {
            $tenantconn->close();
            return ['state' => 'skipped_notempty', 'detail' => (string) $tablecount];
        }
        $tenantconn->close();

        if (!self::has_command('mysqldump') || !self::has_command('mysql')) {
            return ['state' => 'error', 'detail' => 'mysqldump/mysql CLI tools are required on the server PATH'];
        }

        $sourceargs = self::build_mysql_args((string) $CFG->dbhost, (string) $CFG->dbuser, (string) $CFG->dbpass, (string) $CFG->dbname);
        $targetargs = self::build_mysql_args((string) ($tenant->dbhost ?? ''), (string) ($tenant->dbuser ?? ''), (string) ($tenant->dbpass ?? ''), $tenantdbname);
        $cmd = 'mysqldump --single-transaction --quick --skip-lock-tables ' .
            implode(' ', $sourceargs) . ' | mysql ' . implode(' ', $targetargs);

        $output = [];
        $exitcode = 0;
        exec($cmd . ' 2>&1', $output, $exitcode);
        if ($exitcode !== 0) {
            $detail = trim(implode("\n", array_slice($output, 0, 5)));
            return ['state' => 'error', 'detail' => $detail !== '' ? $detail : 'mysqldump/mysql command failed'];
        }

        $tenantconn = @new \mysqli(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $tenantdbname
        );
        if ($tenantconn->connect_errno) {
            return ['state' => 'error', 'detail' => 'Tenant DB reconnect failed after provision'];
        }
        $newcount = self::count_tables($tenantconn, $tenantdbname);
        $tenantconn->close();
        if ($newcount === null || $newcount === 0) {
            return ['state' => 'error', 'detail' => 'Provision finished but tenant DB is still empty'];
        }

        return ['state' => 'provisioned', 'detail' => (string) $newcount];
    }

    /**
     * @param \mysqli $conn
     * @param string $dbname
     * @return int|null
     */
    private static function count_tables(\mysqli $conn, string $dbname): ?int {
        $db = $conn->real_escape_string($dbname);
        $sql = "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = '{$db}'";
        $res = $conn->query($sql);
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        return isset($row['c']) ? (int) $row['c'] : null;
    }

    /**
     * @param string $cmd
     * @return bool
     */
    private static function has_command(string $cmd): bool {
        $result = trim((string) shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null'));
        return $result !== '';
    }

    /**
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param string $dbname
     * @return string[]
     */
    private static function build_mysql_args(string $host, string $user, string $pass, string $dbname): array {
        $args = [];
        if ($host !== '') {
            $args[] = escapeshellarg('--host=' . $host);
        }
        if ($user !== '') {
            $args[] = escapeshellarg('--user=' . $user);
        }
        $args[] = escapeshellarg('--password=' . $pass);
        $args[] = escapeshellarg($dbname);
        return $args;
    }
}

