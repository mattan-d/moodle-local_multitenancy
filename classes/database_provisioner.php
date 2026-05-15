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

use local_multitenancy\db\postgres_helper;

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
    /** @var string[] DB types that can be cloned via pg_dump/psql. */
    private const POSTGRES_FAMILY = ['pgsql'];

    /**
     * @param \stdClass $tenant Tenant row from local_multitenancy_tenant.
     * @return array{state:string, detail:string}
     */
    public static function provision_if_empty(\stdClass $tenant, bool $copycourses = true): array {
        global $CFG;

        $dbtype = (string) ($tenant->dbtype ?? '');
        if (!self::is_supported_dbtype($dbtype)) {
            return ['state' => 'skipped_unsupported', 'detail' => $dbtype];
        }

        $tenantdbname = (string) ($tenant->dbname ?? '');
        if ($tenantdbname === '') {
            return ['state' => 'error', 'detail' => 'Empty tenant dbname'];
        }
        if ($tenantdbname === (string) $CFG->dbname) {
            return ['state' => 'error', 'detail' => 'Tenant DB equals parent DB'];
        }

        $tablecount = self::count_tables_for_tenant($tenant, $tenantdbname);
        if ($tablecount === null) {
            return ['state' => 'error', 'detail' => 'Could not inspect tenant DB tables'];
        }
        if ($tablecount > 0) {
            return ['state' => 'skipped_notempty', 'detail' => (string) $tablecount];
        }

        $clidetail = '';
        // Course filtering exists only in the PHP fallback clone path.
        $trycli = self::can_clone_via_cli($dbtype) && $copycourses;
        if ($trycli) {
            $cliresult = self::clone_via_cli($tenant, $tenantdbname);
            if ($cliresult['ok']) {
                $adminreset = self::reset_to_initial_admin($tenant, $tenantdbname);
                if ($adminreset['state'] !== 'ok') {
                    return ['state' => 'error', 'detail' => $adminreset['detail']];
                }
                return self::verify_after_clone($tenant, $tenantdbname);
            }
            $clidetail = $cliresult['detail'];
        }

        // Fallback for environments without DB dump tools in PATH.
        $phpresult = self::clone_via_php($tenant, $tenantdbname, $copycourses);
        if (!$phpresult['ok']) {
            $detail = $phpresult['detail'];
            if ($clidetail !== '') {
                $detail = 'CLI clone failed (' . $clidetail . '); PHP fallback failed (' . $detail . ')';
            }
            return ['state' => 'error', 'detail' => $detail];
        }

        $adminreset = self::reset_to_initial_admin($tenant, $tenantdbname);
        if ($adminreset['state'] !== 'ok') {
            return ['state' => 'error', 'detail' => $adminreset['detail']];
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

        $dbtype = (string) ($tenant->dbtype ?? '');
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            $sourceargs = postgres_helper::build_cli_args(
                (string) $CFG->dbhost,
                (string) $CFG->dbuser,
                (string) $CFG->dbname,
                postgres_helper::dboptions_for_parent()
            );
            $targetargs = postgres_helper::build_cli_args(
                (string) ($tenant->dbhost ?? ''),
                (string) ($tenant->dbuser ?? ''),
                $tenantdbname,
                postgres_helper::dboptions_for_tenant($tenant)
            );
            $sourceenv = self::build_pg_password_env((string) $CFG->dbpass);
            $targetenv = self::build_pg_password_env((string) ($tenant->dbpass ?? ''));
            $cmd = trim($sourceenv . ' pg_dump --clean --if-exists --no-owner --no-privileges ' .
                implode(' ', $sourceargs)) .
                ' | ' .
                trim($targetenv . ' psql ' . implode(' ', $targetargs));
            $output = [];
            $exitcode = 0;
            exec($cmd . ' 2>&1', $output, $exitcode);
            if ($exitcode !== 0) {
                $detail = trim(implode("\n", array_slice($output, 0, 5)));
                return ['ok' => false, 'detail' => ($detail !== '' ? $detail : 'pg_dump/psql command failed')];
            }
            return ['ok' => true, 'detail' => ''];
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
            return ['ok' => false, 'detail' => ($detail !== '' ? $detail : 'mysqldump/mysql command failed')];
        }
        return ['ok' => true, 'detail' => ''];
    }

    /**
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @param bool $copycourses
     * @return array{ok:bool, detail:string}
     */
    private static function clone_via_php(\stdClass $tenant, string $tenantdbname, bool $copycourses): array {
        global $CFG;
        $dbtype = (string) ($tenant->dbtype ?? '');
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            return self::clone_via_php_pgsql($tenant, $tenantdbname, $copycourses);
        }
        if (!in_array($dbtype, self::MYSQL_FAMILY, true)) {
            return ['ok' => false, 'detail' => 'PHP fallback clone unsupported dbtype: ' . $dbtype];
        }

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
            $courseindexes = [];
            $categoryindexes = [];
            $idindex = null;
            foreach ($fields as $field) {
                $columns[] = '`' . str_replace('`', '``', (string) $field->name) . '`';
                $fname = (string) $field->name;
                if ($fname === 'course' || $fname === 'courseid') {
                    $courseindexes[] = count($columns) - 1;
                }
                if ($fname === 'category' || $fname === 'categoryid') {
                    $categoryindexes[] = count($columns) - 1;
                }
                if ($fname === 'id') {
                    $idindex = count($columns) - 1;
                }
            }
            $columnlist = implode(',', $columns);

            $batch = [];
            $batchsize = 100;
            while ($row = $datares->fetch_row()) {
                if (!self::should_copy_row(
                    $table,
                    $tenant->dbprefix ?? 'mdl_',
                    $row,
                    $courseindexes,
                    $categoryindexes,
                    $idindex,
                    $copycourses
                )) {
                    continue;
                }
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
     * PHP fallback clone for PostgreSQL (pg_dump CLI when available, else schema+data copy).
     *
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @param bool $copycourses
     * @return array{ok:bool, detail:string}
     */
    private static function clone_via_php_pgsql(\stdClass $tenant, string $tenantdbname, bool $copycourses): array {
        if (self::can_clone_via_cli('pgsql')) {
            return self::clone_via_cli($tenant, $tenantdbname);
        }
        if (!function_exists('pg_connect')) {
            return ['ok' => false, 'detail' => 'pgsql PHP extension not loaded'];
        }

        $sourceconn = postgres_helper::connect_parent();
        $targetconn = postgres_helper::connect_tenant($tenant, $tenantdbname);
        if (!$sourceconn) {
            return ['ok' => false, 'detail' => 'Parent PostgreSQL connect failed for PHP clone'];
        }
        if (!$targetconn) {
            pg_close($sourceconn);
            return ['ok' => false, 'detail' => 'Tenant PostgreSQL connect failed for PHP clone'];
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        @pg_query($targetconn, 'SET session_replication_role = replica');

        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $tables = postgres_helper::list_prefixed_tables($sourceconn, $prefix);
        if (!$tables) {
            pg_close($sourceconn);
            pg_close($targetconn);
            return ['ok' => false, 'detail' => 'Parent PostgreSQL DB has no tables to clone'];
        }

        foreach ($tables as $table) {
            $qtable = postgres_helper::quote_ident($table);
            if (!@pg_query($targetconn, 'DROP TABLE IF EXISTS ' . $qtable . ' CASCADE')) {
                pg_close($sourceconn);
                pg_close($targetconn);
                return ['ok' => false, 'detail' => 'DROP TABLE failed for ' . $table . ': ' . pg_last_error($targetconn)];
            }

            $ddl = self::pgsql_build_create_table($sourceconn, $table);
            if ($ddl === null || !@pg_query($targetconn, $ddl)) {
                pg_close($sourceconn);
                pg_close($targetconn);
                return ['ok' => false, 'detail' => 'CREATE TABLE failed for ' . $table . ': ' . pg_last_error($targetconn)];
            }

            $datares = @pg_query($sourceconn, 'SELECT * FROM ' . $qtable);
            if (!$datares) {
                pg_close($sourceconn);
                pg_close($targetconn);
                return ['ok' => false, 'detail' => 'SELECT failed for ' . $table . ': ' . pg_last_error($sourceconn)];
            }

            $columns = [];
            $numfields = pg_num_fields($datares);
            for ($i = 0; $i < $numfields; $i++) {
                $columns[] = postgres_helper::quote_ident((string) pg_field_name($datares, $i));
            }
            $courseindexes = [];
            $categoryindexes = [];
            $idindex = null;
            for ($i = 0; $i < $numfields; $i++) {
                $fname = (string) pg_field_name($datares, $i);
                if ($fname === 'course' || $fname === 'courseid') {
                    $courseindexes[] = $i;
                }
                if ($fname === 'category' || $fname === 'categoryid') {
                    $categoryindexes[] = $i;
                }
                if ($fname === 'id') {
                    $idindex = $i;
                }
            }
            $columnlist = implode(',', $columns);

            $batch = [];
            $batchsize = 100;
            while ($row = pg_fetch_row($datares)) {
                if (!self::should_copy_row($table, $prefix, $row, $courseindexes, $categoryindexes, $idindex, $copycourses)) {
                    continue;
                }
                $values = [];
                foreach ($row as $idx => $value) {
                    $values[] = self::pgsql_sql_value($targetconn, $value, pg_field_type($datares, (int) $idx));
                }
                $batch[] = '(' . implode(',', $values) . ')';
                if (count($batch) >= $batchsize) {
                    $sql = 'INSERT INTO ' . $qtable . ' (' . $columnlist . ') VALUES ' . implode(',', $batch);
                    if (!@pg_query($targetconn, $sql)) {
                        pg_free_result($datares);
                        pg_close($sourceconn);
                        pg_close($targetconn);
                        return ['ok' => false, 'detail' => 'INSERT failed for ' . $table . ': ' . pg_last_error($targetconn)];
                    }
                    $batch = [];
                }
            }
            pg_free_result($datares);

            if (!empty($batch)) {
                $sql = 'INSERT INTO ' . $qtable . ' (' . $columnlist . ') VALUES ' . implode(',', $batch);
                if (!@pg_query($targetconn, $sql)) {
                    pg_close($sourceconn);
                    pg_close($targetconn);
                    return ['ok' => false, 'detail' => 'INSERT failed for ' . $table . ': ' . pg_last_error($targetconn)];
                }
            }
        }

        @pg_query($targetconn, 'SET session_replication_role = DEFAULT');
        pg_close($sourceconn);
        pg_close($targetconn);
        return ['ok' => true, 'detail' => ''];
    }

    /**
     * @param resource $conn
     * @param string $table
     * @return string|null
     */
    private static function pgsql_build_create_table($conn, string $table): ?string {
        $res = @pg_query_params(
            $conn,
            "SELECT column_name, udt_name, character_maximum_length, numeric_precision, numeric_scale, is_nullable, column_default
               FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = $1
              ORDER BY ordinal_position",
            [$table]
        );
        if (!$res) {
            return null;
        }

        $parts = [];
        while ($col = pg_fetch_assoc($res)) {
            $name = postgres_helper::quote_ident((string) $col['column_name']);
            $type = self::pgsql_format_column_type($col);
            $line = $name . ' ' . $type;
            if (($col['is_nullable'] ?? '') === 'NO') {
                $line .= ' NOT NULL';
            }
            $default = $col['column_default'] ?? null;
            if ($default !== null && $default !== '' && strpos((string) $default, 'nextval(') === false) {
                $line .= ' DEFAULT ' . $default;
            }
            $parts[] = $line;
        }
        pg_free_result($res);

        if (!$parts) {
            return null;
        }
        return 'CREATE TABLE ' . postgres_helper::quote_ident($table) . ' (' . implode(', ', $parts) . ')';
    }

    /**
     * @param array<string, mixed> $col
     * @return string
     */
    private static function pgsql_format_column_type(array $col): string {
        $udt = (string) ($col['udt_name'] ?? 'text');
        switch ($udt) {
            case 'int2':
                return 'smallint';
            case 'int4':
                return 'integer';
            case 'int8':
                return 'bigint';
            case 'bool':
                return 'boolean';
            case 'varchar':
                $len = (int) ($col['character_maximum_length'] ?? 0);
                return $len > 0 ? 'character varying(' . $len . ')' : 'character varying';
            case 'numeric':
                $p = (int) ($col['numeric_precision'] ?? 0);
                $s = (int) ($col['numeric_scale'] ?? 0);
                return $p > 0 ? "numeric({$p},{$s})" : 'numeric';
            case 'float4':
                return 'real';
            case 'float8':
                return 'double precision';
            case 'timestamp':
                return 'timestamp without time zone';
            case 'timestamptz':
                return 'timestamp with time zone';
            default:
                return $udt;
        }
    }

    /**
     * @param resource $conn
     * @param mixed $value
     * @param string $pgtype
     * @return string
     */
    private static function pgsql_sql_value($conn, $value, string $pgtype): string {
        if ($value === null) {
            return 'NULL';
        }
        if ($pgtype === 'bool') {
            return ($value === true || $value === 't' || $value === '1' || $value === 1) ? 'TRUE' : 'FALSE';
        }
        if ($pgtype === 'bytea') {
            return "'" . pg_escape_bytea($conn, (string) $value) . "'";
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return "'" . pg_escape_string($conn, (string) $value) . "'";
    }

    /**
     * @param string $table
     * @param string $prefix
     * @param array $row
     * @param int[] $courseindexes
     * @param int[] $categoryindexes
     * @param int|null $idindex
     * @param bool $copycourses
     * @return bool
     */
    private static function should_copy_row(
        string $table,
        string $prefix,
        array $row,
        array $courseindexes,
        array $categoryindexes,
        ?int $idindex,
        bool $copycourses
    ): bool {
        if ($copycourses) {
            return true;
        }
        $short = (strpos($table, $prefix) === 0) ? substr($table, strlen($prefix)) : $table;
        if ($short === 'course' && $idindex !== null) {
            return isset($row[$idindex]) && (int) $row[$idindex] <= 1;
        }
        if ($short === 'course_categories' && $idindex !== null) {
            return isset($row[$idindex]) && (int) $row[$idindex] <= 1;
        }
        foreach ($courseindexes as $ix) {
            if (isset($row[$ix]) && $row[$ix] !== null && (int) $row[$ix] > 1) {
                return false;
            }
        }
        foreach ($categoryindexes as $ix) {
            if (isset($row[$ix]) && $row[$ix] !== null && (int) $row[$ix] > 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @return array{state:string, detail:string}
     */
    private static function verify_after_clone(\stdClass $tenant, string $tenantdbname): array {
        $newcount = self::count_tables_for_tenant($tenant, $tenantdbname);
        if ($newcount === null || $newcount === 0) {
            return ['state' => 'error', 'detail' => 'Provision finished but tenant DB is still empty'];
        }

        return ['state' => 'provisioned', 'detail' => (string) $newcount];
    }

    /**
     * Keep only guest + one initial admin user in tenant DB.
     *
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @return array{state:string, detail:string}
     */
    private static function reset_to_initial_admin(\stdClass $tenant, string $tenantdbname): array {
        if (in_array((string) ($tenant->dbtype ?? ''), self::POSTGRES_FAMILY, true)) {
            return self::reset_to_initial_admin_pg($tenant, $tenantdbname);
        }

        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $conn = @new \mysqli(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $tenantdbname
        );
        if ($conn->connect_errno) {
            return ['state' => 'error', 'detail' => 'Tenant DB reconnect failed for admin reset'];
        }

        $usertable = '`' . str_replace('`', '``', $prefix . 'user') . '`';
        $configtable = '`' . str_replace('`', '``', $prefix . 'config') . '`';
        $sessiontable = '`' . str_replace('`', '``', $prefix . 'sessions') . '`';
        $hash = $conn->real_escape_string(password_hash('Admin123!', PASSWORD_DEFAULT));

        if (!$conn->query("DELETE FROM {$usertable} WHERE id > 2")) {
            $err = $conn->error;
            $conn->close();
            return ['state' => 'error', 'detail' => 'Failed to clean copied users: ' . $err];
        }
        if (!$conn->query("UPDATE {$usertable} SET auth='manual', username='admin', password='{$hash}', deleted=0, suspended=0, confirmed=1 WHERE id=2")) {
            $err = $conn->error;
            $conn->close();
            return ['state' => 'error', 'detail' => 'Failed to reset admin user: ' . $err];
        }
        // Keep tenant admin ownership explicit.
        if (!$conn->query("UPDATE {$configtable} SET value='2' WHERE name='siteadmins'")) {
            $err = $conn->error;
            $conn->close();
            return ['state' => 'error', 'detail' => 'Failed to set siteadmins: ' . $err];
        }
        // Force fresh login after provisioning.
        $conn->query("TRUNCATE TABLE {$sessiontable}");
        $conn->close();
        return ['state' => 'ok', 'detail' => ''];
    }

    /**
     * Keep only guest + one initial admin user in tenant DB (PostgreSQL).
     *
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @return array{state:string, detail:string}
     */
    private static function reset_to_initial_admin_pg(\stdClass $tenant, string $tenantdbname): array {
        if (!function_exists('pg_connect')) {
            return ['state' => 'error', 'detail' => 'pg_connect function is unavailable (pgsql extension not loaded)'];
        }
        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $conn = postgres_helper::connect_tenant($tenant, $tenantdbname);
        if (!$conn) {
            return ['state' => 'error', 'detail' => 'Tenant DB reconnect failed for admin reset'];
        }

        $usertable = postgres_helper::quote_ident($prefix . 'user');
        $configtable = postgres_helper::quote_ident($prefix . 'config');
        $sessiontable = postgres_helper::quote_ident($prefix . 'sessions');
        $hash = password_hash('Admin123!', PASSWORD_DEFAULT);

        if (!@pg_query($conn, "DELETE FROM {$usertable} WHERE id > 2")) {
            $err = pg_last_error($conn);
            pg_close($conn);
            return ['state' => 'error', 'detail' => 'Failed to clean copied users: ' . $err];
        }
        if (!@pg_query_params($conn, "UPDATE {$usertable} SET auth='manual', username='admin', password=$1, deleted=0, suspended=0, confirmed=1 WHERE id=2", [$hash])) {
            $err = pg_last_error($conn);
            pg_close($conn);
            return ['state' => 'error', 'detail' => 'Failed to reset admin user: ' . $err];
        }
        if (!@pg_query($conn, "UPDATE {$configtable} SET value='2' WHERE name='siteadmins'")) {
            $err = pg_last_error($conn);
            pg_close($conn);
            return ['state' => 'error', 'detail' => 'Failed to set siteadmins: ' . $err];
        }
        @pg_query($conn, "TRUNCATE TABLE {$sessiontable}");
        pg_close($conn);
        return ['state' => 'ok', 'detail' => ''];
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
     * Whether the tenant database exists and has at least one table.
     *
     * @param \stdClass $tenant
     * @return bool
     */
    public static function tenant_database_ready(\stdClass $tenant): bool {
        $dbname = (string) ($tenant->dbname ?? '');
        if ($dbname === '') {
            return false;
        }
        $count = self::count_tables_for_tenant($tenant, $dbname);
        return $count !== null && $count > 0;
    }

    /**
     * @param \stdClass $tenant
     * @param string $dbname
     * @return int|null
     */
    private static function count_tables_for_tenant(\stdClass $tenant, string $dbname): ?int {
        $dbtype = (string) ($tenant->dbtype ?? '');
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            return self::count_tables_pg($tenant, $dbname);
        }
        $conn = @new \mysqli(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $dbname
        );
        if ($conn->connect_errno) {
            return null;
        }
        $count = self::count_tables($conn, $dbname);
        $conn->close();
        return $count;
    }

    /**
     * @param \stdClass $tenant
     * @param string $dbname
     * @return int|null
     */
    private static function count_tables_pg(\stdClass $tenant, string $dbname): ?int {
        if (!function_exists('pg_connect')) {
            return null;
        }
        $conn = postgres_helper::connect_tenant($tenant, $dbname);
        if (!$conn) {
            return null;
        }
        $res = @pg_query($conn, "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = 'public'");
        if (!$res) {
            pg_close($conn);
            return null;
        }
        $row = pg_fetch_assoc($res);
        pg_free_result($res);
        pg_close($conn);
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
     * @param string $dbtype
     * @return bool
     */
    private static function is_supported_dbtype(string $dbtype): bool {
        return in_array($dbtype, self::MYSQL_FAMILY, true) || in_array($dbtype, self::POSTGRES_FAMILY, true);
    }

    /**
     * @param string $dbtype
     * @return bool
     */
    private static function can_clone_via_cli(string $dbtype): bool {
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            return self::has_command('pg_dump') && self::has_command('psql');
        }
        return self::has_command('mysqldump') && self::has_command('mysql');
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

    /**
     * @param string $password
     * @return string
     */
    private static function build_pg_password_env(string $password): string {
        if ($password === '') {
            return '';
        }
        return 'PGPASSWORD=' . escapeshellarg($password);
    }
}

