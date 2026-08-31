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
 * Background tenant provisioning (dataroot, DB schema, clone, registry).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_provisioner {

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';

    /**
     * Queue adhoc provisioning for a tenant.
     *
     * @param int $tenantid
     * @param bool $initdbfromparent
     * @param bool $copycourses
     * @return void
     */
    public static function queue(int $tenantid, bool $initdbfromparent, bool $copycourses): void {
        global $DB;

        $record = $DB->get_record('local_multitenancy_tenant', ['id' => $tenantid], '*', MUST_EXIST);
        $update = new \stdClass();
        $update->id = $record->id;
        $update->provisionstatus = self::STATUS_PENDING;
        $update->provisionerror = null;
        $update->timemodified = time();
        $DB->update_record('local_multitenancy_tenant', $update);

        $task = new \local_multitenancy\task\provision_tenant();
        $task->set_custom_data((object) [
            'tenantid' => $tenantid,
            'initdb' => $initdbfromparent,
            'copycourses' => $copycourses,
        ]);
        $task->set_component('local_multitenancy');
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Run full provisioning for one tenant (called from adhoc task).
     *
     * @param int $tenantid
     * @param bool $initdbfromparent
     * @param bool $copycourses
     * @return array{ok:bool, detail:string}
     */
    public static function run(int $tenantid, bool $initdbfromparent, bool $copycourses): array {
        global $DB;

        $tenant = $DB->get_record('local_multitenancy_tenant', ['id' => $tenantid], '*', MUST_EXIST);
        self::set_status($tenantid, self::STATUS_PROCESSING, null);

        $messages = [];

        if (!is_dir($tenant->dataroot) && !make_writable_directory($tenant->dataroot, false)) {
            return self::fail($tenantid, get_string('datarootautocreatefailed', 'local_multitenancy', $tenant->dataroot));
        }

        $schemaerr = self::ensure_tenant_database_exists($tenant);
        if ($schemaerr !== null) {
            return self::fail($tenantid, get_string('dbschemaautocreatefailed', 'local_multitenancy', $schemaerr));
        }
        $messages[] = get_string('dbschemaautocreated', 'local_multitenancy', $tenant->dbname);

        if ($initdbfromparent) {
            $result = database_provisioner::provision_if_empty($tenant, $copycourses);
            if ($result['state'] === 'provisioned') {
                $key = $copycourses ? 'dbprovisioned' : 'dbprovisionednocourses';
                $messages[] = get_string($key, 'local_multitenancy', $tenant->dbname);
            } else if ($result['state'] === 'skipped_notempty') {
                $messages[] = get_string('dbprovisionskippednotempty', 'local_multitenancy', $tenant->dbname);
            } else if ($result['state'] === 'skipped_unsupported') {
                $messages[] = get_string('dbprovisionskippedunsupported', 'local_multitenancy', $tenant->dbtype);
            } else {
                return self::fail($tenantid, get_string('dbprovisionfailed', 'local_multitenancy', $result['detail']));
            }
        }

        // Never mark Complete unless Moodle is actually installed in the tenant DB.
        // Empty/broken DBs previously opened /install.php and caused ERR_TOO_MANY_REDIRECTS.
        if (!database_provisioner::moodle_is_installed($tenant)) {
            return self::fail(
                $tenantid,
                get_string('dbnotinstalled', 'local_multitenancy', $tenant->dbname)
            );
        }

        // Propagate parent site-administration settings and language packs to the tenant,
        // independent of course data.
        $syncresult = admin_sync::sync_tenant($tenant);
        if ($syncresult['state'] === 'synced') {
            $messages[] = get_string('settingssynced', 'local_multitenancy', $syncresult['detail']);
        } else if ($syncresult['state'] === 'error') {
            $messages[] = get_string('settingssyncfailed', 'local_multitenancy', $syncresult['detail']);
        }

        // Mark complete in DB before writing registry so gateway sees the ready status.
        self::set_status($tenantid, self::STATUS_COMPLETE, null);

        gateway_manager::sync();
        if (!registry_writer::sync()) {
            $messages[] = get_string('registrynotwritten', 'local_multitenancy');
        } else {
            $messages[] = get_string('registryupdated', 'local_multitenancy');
        }

        return ['ok' => true, 'detail' => implode("\n", $messages)];
    }

    /**
     * @param int $tenantid
     * @param string $status
     * @param string|null $error
     * @return void
     */
    public static function set_status(int $tenantid, string $status, ?string $error): void {
        global $DB;
        $update = new \stdClass();
        $update->id = $tenantid;
        $update->provisionstatus = $status;
        $update->provisionerror = $error;
        $update->timemodified = time();
        $DB->update_record('local_multitenancy_tenant', $update);
    }

    /**
     * @param int $tenantid
     * @param string $errormessage
     * @return array{ok:bool, detail:string}
     */
    private static function fail(int $tenantid, string $errormessage): array {
        self::set_status($tenantid, self::STATUS_FAILED, $errormessage);
        // Keep registry in sync so bootstrap refuses this tenant and manage UI can show the error.
        registry_writer::sync();
        return ['ok' => false, 'detail' => $errormessage];
    }

    /**
     * Create tenant database if it does not exist (MySQL/MariaDB or PostgreSQL).
     *
     * @param \stdClass $tenant
     * @return string|null Error message or null on success.
     */
    public static function ensure_tenant_database_exists(\stdClass $tenant): ?string {
        global $CFG;

        $dbtype = (string) ($tenant->dbtype ?? '');
        $dbvalues = [
            'dbhost' => (string) ($tenant->dbhost ?? ''),
            'dbname' => (string) ($tenant->dbname ?? ''),
            'dbuser' => (string) ($tenant->dbuser ?? ''),
            'dbpass' => (string) ($tenant->dbpass ?? ''),
            'dbtype' => $dbtype,
        ];

        if (in_array($dbtype, ['mysqli', 'mariadb', 'auroramysql'], true)) {
            $conn = @new \mysqli($dbvalues['dbhost'], $dbvalues['dbuser'], $dbvalues['dbpass']);
            if ($conn->connect_errno) {
                return 'DB connect failed: ' . $conn->connect_error;
            }
            $parentdb = $conn->real_escape_string((string) $CFG->dbname);
            $cs = 'utf8mb4';
            $coll = 'utf8mb4_unicode_ci';
            $res = $conn->query("SELECT DEFAULT_CHARACTER_SET_NAME AS cs, DEFAULT_COLLATION_NAME AS coll " .
                "FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$parentdb}'");
            if ($res && ($row = $res->fetch_assoc())) {
                if (!empty($row['cs'])) {
                    $cs = (string) $row['cs'];
                }
                if (!empty($row['coll'])) {
                    $coll = (string) $row['coll'];
                }
            }
            if ($res) {
                $res->close();
            }
            $dbname = '`' . str_replace('`', '``', $dbvalues['dbname']) . '`';
            $sql = "CREATE DATABASE IF NOT EXISTS {$dbname} DEFAULT CHARACTER SET {$cs} COLLATE {$coll}";
            if (!$conn->query($sql)) {
                $err = $conn->error;
                $conn->close();
                return 'CREATE DATABASE failed: ' . $err;
            }
            $conn->close();
            return null;
        }

        if ($dbtype === 'pgsql') {
            if (!function_exists('pg_connect')) {
                return 'pg_connect function is unavailable (pgsql extension not loaded)';
            }
            $opts = postgres_helper::dboptions_for_tenant($tenant);
            $conn = postgres_helper::connect(
                $dbvalues['dbhost'],
                $dbvalues['dbuser'],
                $dbvalues['dbpass'],
                'postgres',
                $opts
            );
            if (!$conn) {
                return 'DB connect failed: could not connect to postgres maintenance database';
            }
            $dbname = $dbvalues['dbname'];
            $existsres = @pg_query_params($conn, 'SELECT 1 FROM pg_database WHERE datname = $1', [$dbname]);
            if (!$existsres) {
                pg_close($conn);
                return 'Failed checking pg_database for target DB';
            }
            if (pg_num_rows($existsres) > 0) {
                pg_free_result($existsres);
                pg_close($conn);
                return null;
            }
            pg_free_result($existsres);
            $createdb = postgres_helper::quote_ident($dbname);
            if (!@pg_query($conn, 'CREATE DATABASE ' . $createdb)) {
                $err = pg_last_error($conn);
                pg_close($conn);
                return 'CREATE DATABASE failed: ' . $err;
            }
            pg_close($conn);
            return null;
        }

        return null;
    }

    /**
     * Human-readable status label for manage UI.
     *
     * @param \stdClass $tenant
     * @return string
     */
    public static function status_label(\stdClass $tenant): string {
        $status = (string) ($tenant->provisionstatus ?? '');
        switch ($status) {
            case self::STATUS_PENDING:
                return get_string('provisionstatus_pending', 'local_multitenancy');
            case self::STATUS_PROCESSING:
                return get_string('provisionstatus_processing', 'local_multitenancy');
            case self::STATUS_COMPLETE:
                return get_string('provisionstatus_complete', 'local_multitenancy');
            case self::STATUS_FAILED:
                return get_string('provisionstatus_failed', 'local_multitenancy');
            default:
                return get_string('provisionstatus_unknown', 'local_multitenancy');
        }
    }
}
