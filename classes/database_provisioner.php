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

        $clidetail = '';
        if (self::has_command('mysqldump') && self::has_command('mysql')) {
            $cliresult = self::clone_via_cli($tenant, $tenantdbname);
            if ($cliresult['ok']) {
                return self::verify_after_clone($tenant, $tenantdbname);
            }
            $clidetail = $cliresult['detail'];
        }

        // Fallback for environments without mysql/mysqldump in PATH.
        $phpresult = self::clone_via_php($tenant, $tenantdbname);
        if (!$phpresult['ok']) {
            $detail = $phpresult['detail'];
            if ($clidetail !== '') {
                $detail = 'CLI clone failed (' . $clidetail . '); PHP fallback failed (' . $detail . ')';
            }
            return ['state' => 'error', 'detail' => $detail];
        }

        return self::verify_after_clone($tenant, $tenantdbname);
    }

    /**
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @return array{ok:bool, detail:string}
     */
    private static function clone_via_cli(\stdClass $tenant, string $tenantdbname): array {
        global $CFG;

        $sourceargs = self::build_mysql_args((string) $CFG->dbhost, (string) $CFG->dbuser, (string) $CFG->dbpass, (string) $CFG->dbname);
        $targetargs = self::build_mysql_args((string) ($tenant->dbhost ?? ''), (string) ($tenant->dbuser ?? ''), (string) ($tenant->dbpass ?? ''), $tenantdbname);
        $cmd = 'mysqldump --single-transaction --quick --skip-lock-tables ' .
            implode(' ', $sourceargs) . ' | mysql ' . implode(' ', $targetargs);

        $output = [];
        $exitcode = 0;
        exec($cmd . ' 2>&1', $output, $exitcode);
        if ($exitcode !== 0) {
            $detail = trim(implode("\n", array_slice($output, 0, 5)));
            return ['ok' => false, 'detail' => ($detail !== '' ? $detail : 'mysqldump/mysql command failed')];
        }
        return ['ok' => true, 'detail' => ''];
    }

    /**
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @return array{ok:bool, detail:string}
     */
    private static function clone_via_php(\stdClass $tenant, string $tenantdbname): array {
        global $CFG;

        $sourceconn = @new \mysqli((string) $CFG->dbhost, (string) $CFG->dbuser, (string) $CFG->dbpass, (string) $CFG->dbname);
        if ($sourceconn->connect_errno) {
            return ['ok' => false, 'detail' => 'Parent DB connect failed: ' . $sourceconn->connect_error];
        }

        $targetconn = @new \mysqli(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $tenantdbname
        );
        if ($targetconn->connect_errno) {
            $sourceconn->close();
            return ['ok' => false, 'detail' => 'Tenant DB connect failed: ' . $targetconn->connect_error];
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $tables = [];
        $res = $sourceconn->query('SHOW TABLES');
        if (!$res) {
            $sourceconn->close();
            $targetconn->close();
            return ['ok' => false, 'detail' => 'Failed to read table list from parent DB'];
        }
        while ($row = $res->fetch_row()) {
            if (!empty($row[0])) {
                $tables[] = (string) $row[0];
            }
        }
        $res->close();
        if (empty($tables)) {
            $sourceconn->close();
            $targetconn->close();
            return ['ok' => false, 'detail' => 'Parent DB has no tables to clone'];
        }

        $targetconn->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $qtable = '`' . str_replace('`', '``', $table) . '`';

            $createres = $sourceconn->query('SHOW CREATE TABLE ' . $qtable);
            if (!$createres) {
                $targetconn->query('SET FOREIGN_KEY_CHECKS=1');
                $sourceconn->close();
                $targetconn->close();
                return ['ok' => false, 'detail' => 'SHOW CREATE TABLE failed for ' . $table];
            }
            $create = $createres->fetch_assoc();
            $createres->close();
            if (empty($create['Create Table'])) {
                $targetconn->query('SET FOREIGN_KEY_CHECKS=1');
                $sourceconn->close();
                $targetconn->close();
                return ['ok' => false, 'detail' => 'Missing CREATE TABLE SQL for ' . $table];
            }

            if (!$targetconn->query('DROP TABLE IF EXISTS ' . $qtable)) {
                $targetconn->query('SET FOREIGN_KEY_CHECKS=1');
                $sourceconn->close();
                $targetconn->close();
                return ['ok' => false, 'detail' => 'DROP TABLE failed for ' . $table . ': ' . $targetconn->error];
            }
            if (!$targetconn->query((string) $create['Create Table'])) {
                $targetconn->query('SET FOREIGN_KEY_CHECKS=1');
                $sourceconn->close();
                $targetconn->close();
                return ['ok' => false, 'detail' => 'CREATE TABLE failed for ' . $table . ': ' . $targetconn->error];
            }

            $datares = $sourceconn->query('SELECT * FROM ' . $qtable);
            if (!$datares) {
                $targetconn->query('SET FOREIGN_KEY_CHECKS=1');
                $sourceconn->close();
                $targetconn->close();
                return ['ok' => false, 'detail' => 'SELECT failed for ' . $table . ': ' . $sourceconn->error];
            }
            $fields = $datares->fetch_fields();
            $columns = [];
            foreach ($fields as $field) {
                $columns[] = '`' . str_replace('`', '``', (string) $field->name) . '`';
            }
            $columnlist = implode(',', $columns);

            $batch = [];
            $batchsize = 100;
            while ($row = $datares->fetch_row()) {
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . $targetconn->real_escape_string((string) $value) . "'";
                    }
                }
                $batch[] = '(' . implode(',', $values) . ')';
                if (count($batch) >= $batchsize) {
                    $sql = 'INSERT INTO ' . $qtable . ' (' . $columnlist . ') VALUES ' . implode(',', $batch);
                    if (!$targetconn->query($sql)) {
                        $datares->close();
                        $targetconn->query('SET FOREIGN_KEY_CHECKS=1');
                        $sourceconn->close();
                        $targetconn->close();
                        return ['ok' => false, 'detail' => 'INSERT failed for ' . $table . ': ' . $targetconn->error];
                    }
                    $batch = [];
                }
            }
            $datares->close();

            if (!empty($batch)) {
                $sql = 'INSERT INTO ' . $qtable . ' (' . $columnlist . ') VALUES ' . implode(',', $batch);
                if (!$targetconn->query($sql)) {
                    $targetconn->query('SET FOREIGN_KEY_CHECKS=1');
                    $sourceconn->close();
                    $targetconn->close();
                    return ['ok' => false, 'detail' => 'INSERT failed for ' . $table . ': ' . $targetconn->error];
                }
            }
        }
        $targetconn->query('SET FOREIGN_KEY_CHECKS=1');
        $sourceconn->close();
        $targetconn->close();
        return ['ok' => true, 'detail' => ''];
    }

    /**
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @return array{state:string, detail:string}
     */
    private static function verify_after_clone(\stdClass $tenant, string $tenantdbname): array {

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

