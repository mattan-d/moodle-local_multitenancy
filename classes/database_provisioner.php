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
     * @param bool $copycourses
     * @param bool $allowwipe If true and DB has tables but Moodle is not installed, wipe and clone.
     * @return array{state:string, detail:string}
     */
    public static function provision_if_empty(\stdClass $tenant, bool $copycourses = true, bool $allowwipe = false): array {
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

        // Auto-heal broken previous attempts (tables without config.version).
        if ($tablecount > 0) {
            if (self::moodle_is_installed($tenant)) {
                return ['state' => 'skipped_notempty', 'detail' => (string) $tablecount];
            }
            if (!$allowwipe) {
                return [
                    'state' => 'error',
                    'detail' => 'Tenant DB has tables but Moodle is not installed (missing config.version)',
                ];
            }
            $wiped = self::empty_tenant_database($tenant);
            if (!$wiped['ok']) {
                return ['state' => 'error', 'detail' => 'Auto-wipe of broken tenant DB failed: ' . $wiped['detail']];
            }
            $tablecount = 0;
        }

        $errors = [];

        $hascli = self::can_clone_via_cli($dbtype);

        // TEMPLATE needs ownership of the parent DB. If it fails after DROP, the empty
        // tenant DB disappears — always restore an empty DB before falling back.
        if (in_array($dbtype, self::POSTGRES_FAMILY, true) && self::is_same_pg_server($tenant)) {
            if (self::pg_can_use_template($tenant)) {
                provision_logger::log_tenant($tenant, 'clone', 'Trying CREATE DATABASE WITH TEMPLATE');
                $templateresult = self::clone_via_pg_template($tenant, $tenantdbname);
                if ($templateresult['ok']) {
                    provision_logger::log_tenant($tenant, 'clone', 'TEMPLATE clone OK');
                    return self::finalize_clone($tenant, $tenantdbname, $copycourses);
                }
                provision_logger::log_tenant($tenant, 'clone', 'TEMPLATE failed: ' . $templateresult['detail'], 'warn');
                $errors[] = 'TEMPLATE: ' . $templateresult['detail'];
            } else {
                provision_logger::log_tenant(
                    $tenant,
                    'clone',
                    'Skipping TEMPLATE (DB role is not owner/superuser of parent DB) — using PHP/CLI clone',
                    'warn'
                );
            }
            // TEMPLATE (or a failed attempt) may have removed the empty tenant DB.
            $ensure = self::ensure_empty_tenant_database($tenant, $tenantdbname);
            if ($ensure['ok'] !== true) {
                return ['state' => 'error', 'detail' => 'Could not recreate empty tenant DB after TEMPLATE: ' . $ensure['detail']];
            }
        }

        // CLI dump tools (optional — preferred when a matching client exists).
        if ($hascli) {
            provision_logger::log_tenant($tenant, 'clone', 'Trying CLI dump/restore');
            $cliresult = self::clone_via_cli($tenant, $tenantdbname);
            if ($cliresult['ok']) {
                provision_logger::log_tenant($tenant, 'clone', 'CLI clone OK');
                return self::finalize_clone($tenant, $tenantdbname, $copycourses);
            }
            provision_logger::log_tenant($tenant, 'clone', 'CLI failed: ' . $cliresult['detail'], 'warn');
            $errors[] = 'CLI: ' . $cliresult['detail'];
            // Ensure empty DB still exists for PHP fallback.
            if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
                self::ensure_empty_tenant_database($tenant, $tenantdbname);
            }
        } else if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            provision_logger::log_tenant(
                $tenant,
                'clone',
                'No compatible pg_dump/psql in this environment — using PHP clone (no client install required)',
                'info'
            );
        }

        // PHP clone: works with only the pgsql PHP extension (typical in Moodle containers).
        if (in_array($dbtype, self::MYSQL_FAMILY, true) || in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
                $ensure = self::ensure_empty_tenant_database($tenant, $tenantdbname);
                if ($ensure['ok'] !== true) {
                    return ['state' => 'error', 'detail' => 'Tenant DB missing before PHP clone: ' . $ensure['detail']];
                }
            }
            provision_logger::log_tenant($tenant, 'clone', 'Trying PHP clone');
            $phpresult = self::clone_via_php($tenant, $tenantdbname, $copycourses);
            if ($phpresult['ok']) {
                provision_logger::log_tenant($tenant, 'clone', 'PHP clone OK');
                if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
                    // Course filtering is applied during PHP PG row copy; still finalize admin reset.
                    return self::finalize_clone($tenant, $tenantdbname, true);
                }
                $adminreset = self::reset_to_initial_admin($tenant, $tenantdbname);
                if ($adminreset['state'] !== 'ok') {
                    return ['state' => 'error', 'detail' => $adminreset['detail']];
                }
                return self::verify_after_clone($tenant, $tenantdbname);
            }
            provision_logger::log_tenant($tenant, 'clone', 'PHP failed: ' . $phpresult['detail'], 'error');
            $errors[] = 'PHP: ' . $phpresult['detail'];
        }

        return [
            'state' => 'error',
            'detail' => 'Automatic DB clone failed. ' . implode(' | ', $errors),
        ];
    }

    /**
     * Post-clone steps shared by TEMPLATE / CLI paths.
     *
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @param bool $copycourses
     * @return array{state:string, detail:string}
     */
    private static function finalize_clone(\stdClass $tenant, string $tenantdbname, bool $copycourses): array {
        if (!$copycourses) {
            $strip = self::strip_non_frontpage_courses($tenant, $tenantdbname);
            if ($strip['state'] !== 'ok') {
                return ['state' => 'error', 'detail' => $strip['detail']];
            }
        }
        $adminreset = self::reset_to_initial_admin($tenant, $tenantdbname);
        if ($adminreset['state'] !== 'ok') {
            return ['state' => 'error', 'detail' => $adminreset['detail']];
        }
        return self::verify_after_clone($tenant, $tenantdbname);
    }

    /**
     * Whether tenant PostgreSQL host/port match the parent (required for TEMPLATE clone).
     *
     * @param \stdClass $tenant
     * @return bool
     */
    private static function is_same_pg_server(\stdClass $tenant): bool {
        global $CFG;
        $thost = strtolower(trim((string) ($tenant->dbhost ?? '')));
        $phost = strtolower(trim((string) $CFG->dbhost));
        if ($thost === '' || $phost === '') {
            return false;
        }
        // Treat localhost aliases as equivalent.
        $aliases = ['localhost' => true, '127.0.0.1' => true, '::1' => true];
        if (isset($aliases[$thost]) && isset($aliases[$phost])) {
            // Same local machine family — still compare ports.
        } else if ($thost !== $phost) {
            return false;
        }
        $tport = (int) (postgres_helper::dboptions_for_tenant($tenant)['dbport'] ?? 5432);
        $pport = (int) (postgres_helper::dboptions_for_parent()['dbport'] ?? 5432);
        if ($tport <= 0) {
            $tport = 5432;
        }
        if ($pport <= 0) {
            $pport = 5432;
        }
        return $tport === $pport;
    }

    /**
     * Clone via CREATE DATABASE … WITH TEMPLATE (fast, complete, no CLI tools).
     * Briefly terminates other sessions on parent/tenant DBs (required by PostgreSQL).
     *
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @return array{ok:bool, detail:string}
     */
    private static function clone_via_pg_template(\stdClass $tenant, string $tenantdbname): array {
        global $CFG;

        if (!function_exists('pg_connect')) {
            return ['ok' => false, 'detail' => 'pgsql extension not loaded'];
        }

        $opts = postgres_helper::dboptions_for_tenant($tenant);
        // TEMPLATE cannot run while connected to the template (parent) DB — need postgres/template1.
        $maint = postgres_helper::connect_maintenance(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $opts,
            null
        );
        if (!$maint['conn']) {
            return ['ok' => false, 'detail' => $maint['error']];
        }
        $conn = $maint['conn'];
        provision_logger::log_tenant($tenant, 'pg_template', 'Connected to maintenance DB: ' . $maint['dbname']);


        $parentdb = (string) $CFG->dbname;
        $parentq = postgres_helper::quote_ident($parentdb);
        $tenantq = postgres_helper::quote_ident($tenantdbname);

        // TEMPLATE source cannot have other sessions; end them (parent Moodle reconnects).
        @pg_query_params(
            $conn,
            "SELECT pg_terminate_backend(pid)
               FROM pg_stat_activity
              WHERE datname = $1 AND pid <> pg_backend_pid()",
            [$parentdb]
        );
        @pg_query_params(
            $conn,
            "SELECT pg_terminate_backend(pid)
               FROM pg_stat_activity
              WHERE datname = $1 AND pid <> pg_backend_pid()",
            [$tenantdbname]
        );

        // Target must not exist for CREATE … WITH TEMPLATE.
        $exists = @pg_query_params($conn, 'SELECT 1 FROM pg_database WHERE datname = $1', [$tenantdbname]);
        if ($exists && pg_num_rows($exists) > 0) {
            pg_free_result($exists);
            if (!@pg_query($conn, 'DROP DATABASE ' . $tenantq)) {
                $err = pg_last_error($conn);
                pg_close($conn);
                self::reconnect_parent_moodle_db();
                return ['ok' => false, 'detail' => 'DROP DATABASE before TEMPLATE failed: ' . $err];
            }
        } else if ($exists) {
            pg_free_result($exists);
        }

        $sql = 'CREATE DATABASE ' . $tenantq . ' WITH TEMPLATE ' . $parentq;
        if (!@pg_query($conn, $sql)) {
            $err = pg_last_error($conn);
            // Recreate empty tenant DB so PHP/CLI fallback can still run.
            @pg_query($conn, 'CREATE DATABASE ' . $tenantq);
            pg_close($conn);
            self::reconnect_parent_moodle_db();
            return ['ok' => false, 'detail' => 'CREATE DATABASE WITH TEMPLATE failed: ' . $err];
        }
        pg_close($conn);
        self::reconnect_parent_moodle_db();
        return ['ok' => true, 'detail' => ''];
    }

    /**
     * Whether the Moodle DB role can CREATE DATABASE … WITH TEMPLATE of the parent DB.
     * Requires ownership of the parent database (or superuser) — CREATEDB alone is not enough.
     *
     * @param \stdClass $tenant
     * @return bool
     */
    private static function pg_can_use_template(\stdClass $tenant): bool {
        global $CFG;
        if (!function_exists('pg_connect')) {
            return false;
        }
        $opts = postgres_helper::dboptions_for_tenant($tenant);
        $result = postgres_helper::connect_with_error(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            (string) $CFG->dbname,
            $opts
        );
        if (!$result['conn']) {
            return false;
        }
        $conn = $result['conn'];
        $ok = false;
        $res = @pg_query(
            $conn,
            "SELECT rolsuper OR (pg_catalog.pg_get_userbyid(d.datdba) = current_user) AS can_copy
               FROM pg_roles r
               CROSS JOIN pg_database d
              WHERE r.rolname = current_user
                AND d.datname = current_database()"
        );
        if ($res && ($row = pg_fetch_assoc($res))) {
            $ok = ($row['can_copy'] === 't' || $row['can_copy'] === true || $row['can_copy'] === '1');
        }
        if ($res) {
            pg_free_result($res);
        }
        pg_close($conn);
        return $ok;
    }

    /**
     * Ensure the tenant database exists (empty is fine). Used after a failed TEMPLATE DROP.
     *
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @return array{ok:bool, detail:string}
     */
    private static function ensure_empty_tenant_database(\stdClass $tenant, string $tenantdbname): array {
        global $CFG;

        if (!function_exists('pg_connect')) {
            return ['ok' => false, 'detail' => 'pgsql extension not loaded'];
        }

        // Already usable?
        $probe = postgres_helper::connect_tenant_with_error($tenant, $tenantdbname);
        if ($probe['conn']) {
            pg_close($probe['conn']);
            return ['ok' => true, 'detail' => 'exists'];
        }

        $opts = postgres_helper::dboptions_for_tenant($tenant);
        $maint = postgres_helper::connect_maintenance(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $opts,
            (string) $CFG->dbname
        );
        if (!$maint['conn']) {
            return ['ok' => false, 'detail' => $maint['error']];
        }
        $conn = $maint['conn'];
        $exists = @pg_query_params($conn, 'SELECT 1 FROM pg_database WHERE datname = $1', [$tenantdbname]);
        if ($exists && pg_num_rows($exists) > 0) {
            pg_free_result($exists);
            pg_close($conn);
            // Exists but connect failed — surface the earlier connect error.
            return ['ok' => false, 'detail' => 'DB exists but connect failed: ' . $probe['error']];
        }
        if ($exists) {
            pg_free_result($exists);
        }
        $createdb = postgres_helper::quote_ident($tenantdbname);
        if (!@pg_query($conn, 'CREATE DATABASE ' . $createdb)) {
            $err = pg_last_error($conn);
            pg_close($conn);
            return ['ok' => false, 'detail' => 'CREATE DATABASE failed: ' . $err];
        }
        pg_close($conn);
        provision_logger::log_tenant($tenant, 'ensure_db', 'Recreated empty tenant database: ' . $tenantdbname);

        $probe2 = postgres_helper::connect_tenant_with_error($tenant, $tenantdbname);
        if (!$probe2['conn']) {
            return ['ok' => false, 'detail' => 'Created DB but still cannot connect: ' . $probe2['error']];
        }
        pg_close($probe2['conn']);
        return ['ok' => true, 'detail' => 'created'];
    }

    /**
     * Reconnect Moodle's global $DB after pg_terminate_backend on the parent database.
     *
     * @return void
     */
    private static function reconnect_parent_moodle_db(): void {
        global $CFG, $DB;
        if (!isset($DB) || !is_object($DB)) {
            return;
        }
        try {
            if (method_exists($DB, 'dispose')) {
                $DB->dispose();
            }
        } catch (\Throwable $e) {
            // Ignore dispose errors on a dead connection.
        }
        try {
            $DB->connect(
                $CFG->dbhost,
                $CFG->dbuser,
                $CFG->dbpass,
                $CFG->dbname,
                $CFG->prefix,
                !empty($CFG->dboptions) && is_array($CFG->dboptions) ? $CFG->dboptions : []
            );
        } catch (\Throwable $e) {
            // Provisioner will fail on the next parent DB write if reconnect fails.
        }
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
            $servermajor = postgres_helper::server_major_version(
                (string) $CFG->dbhost,
                (string) $CFG->dbuser,
                (string) $CFG->dbpass,
                (string) $CFG->dbname,
                postgres_helper::dboptions_for_parent()
            );
            $tools = postgres_helper::resolve_compatible_cli_tools($servermajor);
            $pgdump = $tools['pg_dump'];
            $psql = $tools['psql'];
            if ($pgdump === null || $psql === null) {
                $hint = $servermajor !== null
                    ? 'Need pg_dump/psql major >= ' . $servermajor . ' (server is PostgreSQL ' . $servermajor . '.x). '
                    : '';
                return [
                    'ok' => false,
                    'detail' => $hint . 'Compatible tools not found. ' . $tools['detail'] .
                        '. Install postgresql-client matching the server (e.g. postgresql-client-15).',
                ];
            }
            provision_logger::log_tenant(
                $tenant,
                'clone_cli',
                'Using ' . $tools['detail']
            );

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
            $cmd = trim($sourceenv . ' ' . escapeshellarg($pgdump) . ' --clean --if-exists --no-owner --no-privileges ' .
                implode(' ', $sourceargs)) .
                ' | ' .
                trim($targetenv . ' ' . escapeshellarg($psql) . ' ' . implode(' ', $targetargs));
            $output = [];
            $exitcode = 0;
            exec($cmd . ' 2>&1', $output, $exitcode);
            if ($exitcode !== 0) {
                $detail = trim(implode("\n", array_slice($output, 0, 8)));
                return [
                    'ok' => false,
                    'detail' => ($detail !== '' ? $detail : 'pg_dump/psql command failed') .
                        ' | tools: ' . $tools['detail'],
                ];
            }
            return ['ok' => true, 'detail' => ''];
        }

        $mysqldump = self::resolve_command('mysqldump');
        $mysql = self::resolve_command('mysql');
        if ($mysqldump === null || $mysql === null) {
            return ['ok' => false, 'detail' => 'mysqldump/mysql not found'];
        }
        $sourceargs = self::build_mysql_args((string) $CFG->dbhost, (string) $CFG->dbuser, (string) $CFG->dbpass, (string) $CFG->dbname);
        $targetargs = self::build_mysql_args((string) ($tenant->dbhost ?? ''), (string) ($tenant->dbuser ?? ''), (string) ($tenant->dbpass ?? ''), $tenantdbname);
        $cmd = escapeshellarg($mysqldump) . ' --single-transaction --quick --skip-lock-tables ' .
            implode(' ', $sourceargs) . ' | ' . escapeshellarg($mysql) . ' ' . implode(' ', $targetargs);

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
     * PHP clone for PostgreSQL using only the pgsql extension (no pg_dump/psql required).
     * Copies sequences, tables, primary/unique keys, data, then resets sequence values.
     *
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @param bool $copycourses
     * @return array{ok:bool, detail:string}
     */
    private static function clone_via_php_pgsql(\stdClass $tenant, string $tenantdbname, bool $copycourses): array {
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
            $err = postgres_helper::connect_tenant_with_error($tenant, $tenantdbname);
            return ['ok' => false, 'detail' => 'Tenant PostgreSQL connect failed for PHP clone: ' . $err['error']];
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        @pg_query($targetconn, 'SET session_replication_role = replica');

        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');

        // 1) Sequences first so column DEFAULT nextval(...) works.
        $seqcopy = self::pgsql_copy_sequences($sourceconn, $targetconn, $prefix);
        if ($seqcopy['ok'] === false) {
            pg_close($sourceconn);
            pg_close($targetconn);
            return $seqcopy;
        }

        $tables = postgres_helper::list_prefixed_tables($sourceconn, $prefix);
        if (!$tables) {
            pg_close($sourceconn);
            pg_close($targetconn);
            return ['ok' => false, 'detail' => 'Parent PostgreSQL DB has no tables to clone'];
        }

        foreach ($tables as $table) {
            $qtable = postgres_helper::quote_ident($table);
            if (!@pg_query($targetconn, 'DROP TABLE IF EXISTS ' . $qtable . ' CASCADE')) {
                $err = pg_last_error($targetconn);
                pg_close($sourceconn);
                pg_close($targetconn);
                return ['ok' => false, 'detail' => 'DROP TABLE failed for ' . $table . ': ' . $err];
            }

            $ddl = self::pgsql_build_create_table($sourceconn, $table);
            if ($ddl === null || !@pg_query($targetconn, $ddl)) {
                $err = pg_last_error($targetconn);
                pg_close($sourceconn);
                pg_close($targetconn);
                return ['ok' => false, 'detail' => 'CREATE TABLE failed for ' . $table . ': ' . ($err ?: 'null DDL')];
            }

            $pk = self::pgsql_build_primary_key($sourceconn, $table);
            if ($pk !== null && !@pg_query($targetconn, $pk)) {
                // Non-fatal if PK already embedded; still try continue.
                provision_logger::log_tenant($tenant, 'clone_php', 'PK warn ' . $table . ': ' . pg_last_error($targetconn), 'warn');
            }

            $datares = @pg_query($sourceconn, 'SELECT * FROM ' . $qtable);
            if (!$datares) {
                $err = pg_last_error($sourceconn);
                pg_close($sourceconn);
                pg_close($targetconn);
                return ['ok' => false, 'detail' => 'SELECT failed for ' . $table . ': ' . $err];
            }

            $columns = [];
            $numfields = pg_num_fields($datares);
            $courseindexes = [];
            $categoryindexes = [];
            $idindex = null;
            for ($i = 0; $i < $numfields; $i++) {
                $fname = (string) pg_field_name($datares, $i);
                $columns[] = postgres_helper::quote_ident($fname);
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
            $batchsize = 80;
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
                        $err = pg_last_error($targetconn);
                        pg_free_result($datares);
                        pg_close($sourceconn);
                        pg_close($targetconn);
                        return ['ok' => false, 'detail' => 'INSERT failed for ' . $table . ': ' . $err];
                    }
                    $batch = [];
                }
            }
            pg_free_result($datares);

            if (!empty($batch)) {
                $sql = 'INSERT INTO ' . $qtable . ' (' . $columnlist . ') VALUES ' . implode(',', $batch);
                if (!@pg_query($targetconn, $sql)) {
                    $err = pg_last_error($targetconn);
                    pg_close($sourceconn);
                    pg_close($targetconn);
                    return ['ok' => false, 'detail' => 'INSERT failed for ' . $table . ': ' . $err];
                }
            }
        }

        // 3) Reset sequences to MAX(id) so new inserts do not collide.
        self::pgsql_reset_sequences($targetconn, $prefix);

        // 4) Unique constraints / indexes (best-effort).
        self::pgsql_copy_unique_and_indexes($sourceconn, $targetconn, $tables);

        @pg_query($targetconn, 'SET session_replication_role = DEFAULT');
        pg_close($sourceconn);
        pg_close($targetconn);
        return ['ok' => true, 'detail' => ''];
    }

    /**
     * @param resource $source
     * @param resource $target
     * @param string $prefix
     * @return array{ok:bool, detail:string}
     */
    private static function pgsql_copy_sequences($source, $target, string $prefix): array {
        $res = @pg_query(
            $source,
            "SELECT c.relname AS sequencename
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE c.relkind = 'S' AND n.nspname = 'public'
              ORDER BY c.relname"
        );
        if (!$res) {
            return ['ok' => false, 'detail' => 'Failed listing sequences: ' . pg_last_error($source)];
        }
        while ($row = pg_fetch_assoc($res)) {
            $name = (string) ($row['sequencename'] ?? '');
            if ($name === '') {
                continue;
            }
            // Only Moodle-related sequences (prefix) plus any nextval targets we may need.
            if ($prefix !== '' && strpos($name, $prefix) !== 0) {
                continue;
            }
            $qname = postgres_helper::quote_ident($name);
            @pg_query($target, 'DROP SEQUENCE IF EXISTS ' . $qname . ' CASCADE');

            $meta = @pg_query_params(
                $source,
                "SELECT s.seqstart, s.seqincrement, s.seqmin, s.seqmax, s.seqcache, s.seqcycle,
                        pg_catalog.format_type(s.seqtypid, NULL) AS typ
                   FROM pg_sequence s
                   JOIN pg_class c ON c.oid = s.seqrelid
                  WHERE c.relname = $1",
                [$name]
            );
            $start = 1;
            $inc = 1;
            $min = 1;
            $max = '9223372036854775807';
            $cache = 1;
            $cycle = false;
            $typ = 'bigint';
            if ($meta && ($m = pg_fetch_assoc($meta))) {
                $start = (int) $m['seqstart'];
                $inc = (int) $m['seqincrement'];
                $min = (int) $m['seqmin'];
                $max = (string) $m['seqmax'];
                $cache = (int) $m['seqcache'];
                $cycle = ($m['seqcycle'] === 't' || $m['seqcycle'] === true || $m['seqcycle'] === '1');
                if (!empty($m['typ'])) {
                    $typ = (string) $m['typ'];
                }
            }
            if ($meta) {
                pg_free_result($meta);
            }

            $sql = 'CREATE SEQUENCE ' . $qname .
                ' AS ' . $typ .
                ' INCREMENT BY ' . $inc .
                ' MINVALUE ' . $min .
                ' MAXVALUE ' . $max .
                ' START WITH ' . $start .
                ' CACHE ' . max(1, $cache) .
                ($cycle ? ' CYCLE' : ' NO CYCLE');
            if (!@pg_query($target, $sql)) {
                pg_free_result($res);
                return ['ok' => false, 'detail' => 'CREATE SEQUENCE failed for ' . $name . ': ' . pg_last_error($target)];
            }
        }
        pg_free_result($res);
        return ['ok' => true, 'detail' => ''];
    }

    /**
     * @param resource $conn
     * @param string $prefix
     * @return void
     */
    private static function pgsql_reset_sequences($conn, string $prefix): void {
        $res = @pg_query(
            $conn,
            "SELECT c.relname AS seq, a.attname AS col, t.relname AS tbl
               FROM pg_class c
               JOIN pg_depend d ON d.objid = c.oid AND d.deptype = 'a'
               JOIN pg_class t ON t.oid = d.refobjid
               JOIN pg_attribute a ON a.attrelid = d.refobjid AND a.attnum = d.refobjsubid
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE c.relkind = 'S' AND n.nspname = 'public'"
        );
        if (!$res) {
            return;
        }
        while ($row = pg_fetch_assoc($res)) {
            $seq = (string) ($row['seq'] ?? '');
            $col = (string) ($row['col'] ?? '');
            $tbl = (string) ($row['tbl'] ?? '');
            if ($seq === '' || $col === '' || $tbl === '') {
                continue;
            }
            if ($prefix !== '' && strpos($tbl, $prefix) !== 0) {
                continue;
            }
            $qtbl = postgres_helper::quote_ident($tbl);
            $qcol = postgres_helper::quote_ident($col);
            $qseq = postgres_helper::quote_ident($seq);
            @pg_query($conn, 'ALTER SEQUENCE ' . $qseq . ' OWNED BY ' . $qtbl . '.' . $qcol);
            @pg_query_params(
                $conn,
                'SELECT setval($1::regclass, COALESCE((SELECT MAX(' . $qcol . ') FROM ' . $qtbl . '), 1), true)',
                [$seq]
            );
        }
        pg_free_result($res);
    }

    /**
     * @param resource $source
     * @param string $table
     * @return string|null
     */
    private static function pgsql_build_primary_key($source, string $table): ?string {
        $res = @pg_query_params(
            $source,
            "SELECT kcu.column_name
               FROM information_schema.table_constraints tc
               JOIN information_schema.key_column_usage kcu
                 ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
              WHERE tc.table_schema = 'public'
                AND tc.table_name = $1
                AND tc.constraint_type = 'PRIMARY KEY'
              ORDER BY kcu.ordinal_position",
            [$table]
        );
        if (!$res) {
            return null;
        }
        $cols = [];
        while ($row = pg_fetch_assoc($res)) {
            $cols[] = postgres_helper::quote_ident((string) $row['column_name']);
        }
        pg_free_result($res);
        if (!$cols) {
            return null;
        }
        return 'ALTER TABLE ' . postgres_helper::quote_ident($table) .
            ' ADD PRIMARY KEY (' . implode(', ', $cols) . ')';
    }

    /**
     * @param resource $source
     * @param resource $target
     * @param string[] $tables
     * @return void
     */
    private static function pgsql_copy_unique_and_indexes($source, $target, array $tables): void {
        foreach ($tables as $table) {
            $res = @pg_query_params(
                $source,
                "SELECT indexname, indexdef
                   FROM pg_indexes
                  WHERE schemaname = 'public' AND tablename = $1",
                [$table]
            );
            if (!$res) {
                continue;
            }
            while ($row = pg_fetch_assoc($res)) {
                $def = (string) ($row['indexdef'] ?? '');
                $name = (string) ($row['indexname'] ?? '');
                if ($def === '' || $name === '') {
                    continue;
                }
                // Skip primary-key indexes (already added).
                if (stripos($def, ' UNIQUE INDEX ') === false && stripos($def, 'CREATE UNIQUE') === false
                        && preg_match('/_pkey$/', $name)) {
                    continue;
                }
                if (preg_match('/_pkey$/', $name)) {
                    continue;
                }
                @pg_query($target, $def);
            }
            pg_free_result($res);
        }
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
            // Keep nextval defaults — sequences were created first.
            if ($default !== null && $default !== '') {
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
        if (!self::moodle_is_installed($tenant)) {
            return [
                'state' => 'error',
                'detail' => 'Tenant DB has tables but Moodle is not installed (missing config.version). ' .
                    'Gateway would open /install.php and can cause redirect loops.',
            ];
        }

        return ['state' => 'provisioned', 'detail' => (string) $newcount];
    }

    /**
     * Whether the tenant database looks like a finished Moodle install (config.version present).
     *
     * @param \stdClass $tenant
     * @return bool
     */
    public static function moodle_is_installed(\stdClass $tenant): bool {
        $dbname = (string) ($tenant->dbname ?? '');
        if ($dbname === '') {
            return false;
        }
        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $dbtype = (string) ($tenant->dbtype ?? '');

        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            if (!function_exists('pg_connect')) {
                return false;
            }
            $conn = postgres_helper::connect_tenant($tenant, $dbname);
            if (!$conn) {
                return false;
            }
            $table = postgres_helper::quote_ident($prefix . 'config');
            $res = @pg_query($conn, "SELECT value FROM {$table} WHERE name = 'version' LIMIT 1");
            $ok = false;
            if ($res && ($row = pg_fetch_assoc($res)) && isset($row['value']) && (string) $row['value'] !== '') {
                $ok = true;
            }
            if ($res) {
                pg_free_result($res);
            }
            pg_close($conn);
            return $ok;
        }

        if (!in_array($dbtype, self::MYSQL_FAMILY, true)) {
            return false;
        }
        $conn = @new \mysqli(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $dbname
        );
        if ($conn->connect_errno) {
            return false;
        }
        $table = '`' . str_replace('`', '``', $prefix . 'config') . '`';
        $res = $conn->query("SELECT value FROM {$table} WHERE name = 'version' LIMIT 1");
        $ok = false;
        if ($res && ($row = $res->fetch_assoc()) && isset($row['value']) && (string) $row['value'] !== '') {
            $ok = true;
        }
        $conn->close();
        return $ok;
    }

    /**
     * After a full CLI clone, remove non-frontpage courses when copy-courses was disabled.
     *
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @return array{state:string, detail:string}
     */
    private static function strip_non_frontpage_courses(\stdClass $tenant, string $tenantdbname): array {
        $dbtype = (string) ($tenant->dbtype ?? '');
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            return self::strip_non_frontpage_courses_pg($tenant, $tenantdbname);
        }
        return self::strip_non_frontpage_courses_mysql($tenant, $tenantdbname);
    }

    /**
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @return array{state:string, detail:string}
     */
    private static function strip_non_frontpage_courses_mysql(\stdClass $tenant, string $tenantdbname): array {
        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $conn = @new \mysqli(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $tenantdbname
        );
        if ($conn->connect_errno) {
            return ['state' => 'error', 'detail' => 'Strip courses: connect failed: ' . $conn->connect_error];
        }
        $conn->query('SET FOREIGN_KEY_CHECKS=0');
        $coursetable = '`' . str_replace('`', '``', $prefix . 'course') . '`';
        $cattable = '`' . str_replace('`', '``', $prefix . 'course_categories') . '`';
        if (!$conn->query("DELETE FROM {$coursetable} WHERE id > 1")) {
            $err = $conn->error;
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            $conn->close();
            return ['state' => 'error', 'detail' => 'Strip courses failed: ' . $err];
        }
        $conn->query("DELETE FROM {$cattable} WHERE id > 1");
        // Best-effort: remove orphaned rows keyed by course/courseid > 1.
        $tablesres = $conn->query('SHOW TABLES');
        if ($tablesres) {
            while ($row = $tablesres->fetch_row()) {
                $table = (string) ($row[0] ?? '');
                if ($table === '' || strpos($table, $prefix) !== 0) {
                    continue;
                }
                if ($table === $prefix . 'course' || $table === $prefix . 'course_categories') {
                    continue;
                }
                $qtable = '`' . str_replace('`', '``', $table) . '`';
                $cols = $conn->query('SHOW COLUMNS FROM ' . $qtable);
                if (!$cols) {
                    continue;
                }
                $hascourse = false;
                $hascourseid = false;
                while ($col = $cols->fetch_assoc()) {
                    $cname = (string) ($col['Field'] ?? '');
                    if ($cname === 'course') {
                        $hascourse = true;
                    }
                    if ($cname === 'courseid') {
                        $hascourseid = true;
                    }
                }
                if ($hascourse) {
                    $conn->query("DELETE FROM {$qtable} WHERE course > 1");
                }
                if ($hascourseid) {
                    $conn->query("DELETE FROM {$qtable} WHERE courseid > 1");
                }
            }
            $tablesres->close();
        }
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        $conn->close();
        return ['state' => 'ok', 'detail' => ''];
    }

    /**
     * @param \stdClass $tenant
     * @param string $tenantdbname
     * @return array{state:string, detail:string}
     */
    private static function strip_non_frontpage_courses_pg(\stdClass $tenant, string $tenantdbname): array {
        if (!function_exists('pg_connect')) {
            return ['state' => 'error', 'detail' => 'Strip courses: pgsql extension not loaded'];
        }
        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $conn = postgres_helper::connect_tenant($tenant, $tenantdbname);
        if (!$conn) {
            return ['state' => 'error', 'detail' => 'Strip courses: connect failed'];
        }
        @pg_query($conn, 'SET session_replication_role = replica');
        $coursetable = postgres_helper::quote_ident($prefix . 'course');
        $cattable = postgres_helper::quote_ident($prefix . 'course_categories');
        if (!@pg_query($conn, "DELETE FROM {$coursetable} WHERE id > 1")) {
            $err = pg_last_error($conn);
            pg_close($conn);
            return ['state' => 'error', 'detail' => 'Strip courses failed: ' . $err];
        }
        @pg_query($conn, "DELETE FROM {$cattable} WHERE id > 1");
        $tables = postgres_helper::list_prefixed_tables($conn, $prefix);
        foreach ($tables as $table) {
            if ($table === $prefix . 'course' || $table === $prefix . 'course_categories') {
                continue;
            }
            $qtable = postgres_helper::quote_ident($table);
            $colres = @pg_query_params(
                $conn,
                "SELECT column_name FROM information_schema.columns
                  WHERE table_schema = 'public' AND table_name = $1
                    AND column_name IN ('course', 'courseid')",
                [$table]
            );
            if (!$colres) {
                continue;
            }
            while ($col = pg_fetch_assoc($colres)) {
                $cname = (string) ($col['column_name'] ?? '');
                if ($cname === 'course' || $cname === 'courseid') {
                    @pg_query($conn, 'DELETE FROM ' . $qtable . ' WHERE ' .
                        postgres_helper::quote_ident($cname) . ' > 1');
                }
            }
            pg_free_result($colres);
        }
        @pg_query($conn, 'SET session_replication_role = DEFAULT');
        pg_close($conn);
        return ['state' => 'ok', 'detail' => ''];
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
     * Drop all tables in the tenant database so provisioning can clone again.
     * Used when a previous clone left a broken (non-empty, not installed) DB.
     *
     * @param \stdClass $tenant
     * @return array{ok:bool, detail:string}
     */
    public static function empty_tenant_database(\stdClass $tenant): array {
        global $CFG;

        $dbname = (string) ($tenant->dbname ?? '');
        if ($dbname === '' || $dbname === (string) $CFG->dbname) {
            return ['ok' => false, 'detail' => 'Refusing to empty invalid/parent database name'];
        }
        $dbtype = (string) ($tenant->dbtype ?? '');
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            return self::empty_tenant_database_pg($tenant, $dbname);
        }
        if (!in_array($dbtype, self::MYSQL_FAMILY, true)) {
            return ['ok' => false, 'detail' => 'Unsupported dbtype for empty: ' . $dbtype];
        }
        $conn = @new \mysqli(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $dbname
        );
        if ($conn->connect_errno) {
            return ['ok' => false, 'detail' => 'Connect failed: ' . $conn->connect_error];
        }
        $conn->query('SET FOREIGN_KEY_CHECKS=0');
        $res = $conn->query('SHOW TABLES');
        if (!$res) {
            $conn->close();
            return ['ok' => false, 'detail' => 'SHOW TABLES failed'];
        }
        while ($row = $res->fetch_row()) {
            $table = (string) ($row[0] ?? '');
            if ($table === '') {
                continue;
            }
            $q = '`' . str_replace('`', '``', $table) . '`';
            if (!$conn->query('DROP TABLE IF EXISTS ' . $q)) {
                $err = $conn->error;
                $conn->query('SET FOREIGN_KEY_CHECKS=1');
                $conn->close();
                return ['ok' => false, 'detail' => 'DROP TABLE failed: ' . $err];
            }
        }
        $res->close();
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        $conn->close();
        return ['ok' => true, 'detail' => ''];
    }

    /**
     * @param \stdClass $tenant
     * @param string $dbname
     * @return array{ok:bool, detail:string}
     */
    private static function empty_tenant_database_pg(\stdClass $tenant, string $dbname): array {
        if (!function_exists('pg_connect')) {
            return ['ok' => false, 'detail' => 'pgsql extension not loaded'];
        }
        $conn = postgres_helper::connect_tenant($tenant, $dbname);
        if (!$conn) {
            return ['ok' => false, 'detail' => 'Connect failed'];
        }
        // Drop and recreate public schema (clears tables, sequences, etc.).
        if (!@pg_query($conn, 'DROP SCHEMA public CASCADE')) {
            $err = pg_last_error($conn);
            pg_close($conn);
            return ['ok' => false, 'detail' => 'DROP SCHEMA failed: ' . $err];
        }
        if (!@pg_query($conn, 'CREATE SCHEMA public')) {
            $err = pg_last_error($conn);
            pg_close($conn);
            return ['ok' => false, 'detail' => 'CREATE SCHEMA failed: ' . $err];
        }
        pg_close($conn);
        return ['ok' => true, 'detail' => ''];
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
        return self::resolve_command($cmd) !== null;
    }

    /**
     * Resolve a generic executable (MySQL tools). Prefer PATH then common locations.
     *
     * @param string $cmd
     * @return string|null Absolute path or null.
     */
    private static function resolve_command(string $cmd): ?string {
        static $cache = [];
        if (array_key_exists($cmd, $cache)) {
            return $cache[$cmd];
        }

        $candidates = [];
        $which = trim((string) shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null'));
        if ($which !== '') {
            $candidates[] = $which;
        }
        $candidates = array_merge($candidates, [
            '/usr/bin/' . $cmd,
            '/usr/local/bin/' . $cmd,
            '/bin/' . $cmd,
            '/opt/homebrew/bin/' . $cmd,
        ]);

        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && is_executable($path)) {
                $cache[$cmd] = $path;
                return $path;
            }
        }
        $cache[$cmd] = null;
        return null;
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
        global $CFG;
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            $servermajor = postgres_helper::server_major_version(
                (string) $CFG->dbhost,
                (string) $CFG->dbuser,
                (string) $CFG->dbpass,
                (string) $CFG->dbname,
                postgres_helper::dboptions_for_parent()
            );
            $tools = postgres_helper::resolve_compatible_cli_tools($servermajor);
            return $tools['pg_dump'] !== null && $tools['psql'] !== null;
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

