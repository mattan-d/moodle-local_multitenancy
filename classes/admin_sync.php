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
 * Sync parent site administrators into tenant databases.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_sync {

    /** @var string[] User columns copied from parent to tenant (excluding id). */
    private const USER_SYNC_COLUMNS = [
        'auth', 'confirmed', 'policyagreed', 'deleted', 'suspended', 'mnethostid',
        'username', 'password', 'idnumber', 'firstname', 'lastname', 'email',
        'emailstop', 'phone1', 'phone2', 'institution', 'department', 'address',
        'city', 'country', 'lang', 'calendartype', 'theme', 'timezone',
        'firstaccess', 'lastaccess', 'lastlogin', 'currentlogin', 'lastip',
        'secret', 'picture', 'description', 'descriptionformat', 'mailformat',
        'maildigest', 'maildisplay', 'autosubscribe', 'trackforums', 'timecreated',
        'timemodified', 'trustbitmask', 'imagealt', 'lastnamephonetic', 'firstnamephonetic',
        'middlename', 'alternatename',
    ];

    /** @var string[] */
    private const MYSQL_FAMILY = ['mysqli', 'mariadb', 'auroramysql'];

    /** @var string[] */
    private const POSTGRES_FAMILY = ['pgsql'];

    /**
     * Sync parent site admins to all enabled tenants with a ready database.
     *
     * @return array{tenants:int, synced:int, skipped:int, errors:int, messages:string[]}
     */
    public static function sync_all_enabled_tenants(): array {
        global $DB;

        $summary = [
            'tenants' => 0,
            'synced' => 0,
            'skipped' => 0,
            'errors' => 0,
            'messages' => [],
        ];

        $parentadmins = self::get_parent_site_admin_users();
        if (!$parentadmins) {
            $summary['messages'][] = 'No parent site administrators found.';
            return $summary;
        }

        $tenants = $DB->get_records('local_multitenancy_tenant', ['enabled' => 1], 'sortorder ASC, id ASC');
        foreach ($tenants as $tenant) {
            $summary['tenants']++;
            $result = self::sync_tenant($tenant, $parentadmins);
            if ($result['state'] === 'synced') {
                $summary['synced']++;
            } else if ($result['state'] === 'skipped') {
                $summary['skipped']++;
            } else {
                $summary['errors']++;
            }
            $summary['messages'][] = $tenant->shortcode . ': ' . $result['detail'];
        }

        return $summary;
    }

    /**
     * @return \stdClass[] Parent user records keyed by id.
     */
    public static function get_parent_site_admin_users(): array {
        global $CFG, $DB;

        $raw = (string) ($CFG->siteadmins ?? get_config('core', 'siteadmins') ?? '');
        $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
        if (!$ids) {
            return [];
        }

        $users = $DB->get_records_list('user', 'id', $ids);
        $active = [];
        foreach ($users as $user) {
            if (!empty($user->deleted)) {
                continue;
            }
            $active[$user->id] = $user;
        }
        return $active;
    }

    /**
     * @param \stdClass $tenant
     * @param \stdClass[]|null $parentadmins
     * @return array{state:string, detail:string}
     */
    public static function sync_tenant(\stdClass $tenant, ?array $parentadmins = null): array {
        if ($parentadmins === null) {
            $parentadmins = self::get_parent_site_admin_users();
        }
        if (!$parentadmins) {
            return ['state' => 'skipped', 'detail' => 'No parent site administrators'];
        }
        if (!database_provisioner::tenant_database_ready($tenant)) {
            return ['state' => 'skipped', 'detail' => 'Tenant database not ready'];
        }

        $dbtype = (string) ($tenant->dbtype ?? '');
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            return self::sync_tenant_pg($tenant, $parentadmins);
        }
        if (in_array($dbtype, self::MYSQL_FAMILY, true)) {
            return self::sync_tenant_mysql($tenant, $parentadmins);
        }
        return ['state' => 'skipped', 'detail' => 'Unsupported dbtype: ' . $dbtype];
    }

    /**
     * @param \stdClass $tenant
     * @param \stdClass[] $parentadmins
     * @return array{state:string, detail:string}
     */
    private static function sync_tenant_mysql(\stdClass $tenant, array $parentadmins): array {
        $dbname = (string) ($tenant->dbname ?? '');
        $conn = @new \mysqli(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $dbname
        );
        if ($conn->connect_errno) {
            return ['state' => 'error', 'detail' => 'DB connect failed: ' . $conn->connect_error];
        }

        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = '`' . str_replace('`', '``', $prefix . 'user') . '`';
        $configtable = '`' . str_replace('`', '``', $prefix . 'config') . '`';

        $tenantadminids = [];
        foreach ($parentadmins as $parentuser) {
            $syncresult = self::upsert_user_mysql($conn, $usertable, $parentuser);
            if ($syncresult['state'] !== 'ok') {
                $conn->close();
                return ['state' => 'error', 'detail' => $syncresult['detail']];
            }
            $tenantadminids[] = (int) $syncresult['userid'];
        }

        $merge = self::merge_siteadmins_mysql($conn, $configtable, $tenantadminids);
        $conn->close();
        if ($merge['state'] !== 'ok') {
            return ['state' => 'error', 'detail' => $merge['detail']];
        }

        return [
            'state' => 'synced',
            'detail' => count($tenantadminids) . ' administrator(s) synced',
        ];
    }

    /**
     * @param \mysqli $conn
     * @param string $usertable
     * @param \stdClass $parentuser
     * @return array{state:string, detail:string, userid?:int}
     */
    private static function upsert_user_mysql(\mysqli $conn, string $usertable, \stdClass $parentuser): array {
        $username = (string) $parentuser->username;
        $mnethostid = (int) ($parentuser->mnethostid ?? 1);

        $stmt = $conn->prepare("SELECT id FROM {$usertable} WHERE username = ? AND mnethostid = ?");
        if (!$stmt) {
            return ['state' => 'error', 'detail' => 'Prepare failed: ' . $conn->error];
        }
        $stmt->bind_param('si', $username, $mnethostid);
        $stmt->execute();
        $res = $stmt->get_result();
        $existingid = null;
        if ($res && ($row = $res->fetch_assoc())) {
            $existingid = (int) $row['id'];
        }
        $stmt->close();

        $data = self::user_sync_data($parentuser);
        if ($existingid !== null) {
            $sets = [];
            foreach ($data as $column => $value) {
                $escaped = self::mysqli_value($conn, $value);
                $sets[] = '`' . str_replace('`', '``', $column) . '` = ' . $escaped;
            }
            $sql = 'UPDATE ' . $usertable . ' SET ' . implode(', ', $sets) .
                ' WHERE id = ' . $existingid;
            if (!$conn->query($sql)) {
                return ['state' => 'error', 'detail' => 'Update user failed: ' . $conn->error];
            }
            return ['state' => 'ok', 'detail' => '', 'userid' => $existingid];
        }

        $newid = self::next_user_id_mysql($conn, $usertable);
        if ($newid === null) {
            return ['state' => 'error', 'detail' => 'Could not allocate user id'];
        }

        $columns = ['id'];
        $values = [(string) $newid];
        foreach ($data as $column => $value) {
            $columns[] = '`' . str_replace('`', '``', $column) . '`';
            $values[] = self::mysqli_value($conn, $value);
        }
        $sql = 'INSERT INTO ' . $usertable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
        if (!$conn->query($sql)) {
            return ['state' => 'error', 'detail' => 'Insert user failed: ' . $conn->error];
        }
        return ['state' => 'ok', 'detail' => '', 'userid' => $newid];
    }

    /**
     * @param \mysqli $conn
     * @param string $usertable
     * @return int|null
     */
    private static function next_user_id_mysql(\mysqli $conn, string $usertable): ?int {
        $res = $conn->query('SELECT COALESCE(MAX(id), 0) + 1 AS nextid FROM ' . $usertable);
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        return isset($row['nextid']) ? (int) $row['nextid'] : null;
    }

    /**
     * @param \mysqli $conn
     * @param string $configtable
     * @param int[] $adminids
     * @return array{state:string, detail:string}
     */
    private static function merge_siteadmins_mysql(\mysqli $conn, string $configtable, array $adminids): array {
        $current = [];
        $res = $conn->query("SELECT value FROM {$configtable} WHERE name = 'siteadmins'");
        if ($res && ($row = $res->fetch_assoc()) && isset($row['value'])) {
            $current = array_values(array_filter(array_map('intval', explode(',', (string) $row['value']))));
        }
        $merged = self::merge_admin_ids($current, $adminids);
        $value = $conn->real_escape_string(implode(',', $merged));
        if (!$conn->query("UPDATE {$configtable} SET value = '{$value}' WHERE name = 'siteadmins'")) {
            return ['state' => 'error', 'detail' => 'Failed to update siteadmins: ' . $conn->error];
        }
        return ['state' => 'ok', 'detail' => ''];
    }

    /**
     * @param \stdClass $tenant
     * @param \stdClass[] $parentadmins
     * @return array{state:string, detail:string}
     */
    private static function sync_tenant_pg(\stdClass $tenant, array $parentadmins): array {
        if (!function_exists('pg_connect')) {
            return ['state' => 'error', 'detail' => 'pgsql extension not loaded'];
        }

        $dbname = (string) ($tenant->dbname ?? '');
        $conn = postgres_helper::connect_tenant($tenant, $dbname);
        if (!$conn) {
            return ['state' => 'error', 'detail' => 'DB connect failed'];
        }

        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = postgres_helper::quote_ident($prefix . 'user');
        $configtable = postgres_helper::quote_ident($prefix . 'config');

        $tenantadminids = [];
        foreach ($parentadmins as $parentuser) {
            $syncresult = self::upsert_user_pg($conn, $usertable, $parentuser);
            if ($syncresult['state'] !== 'ok') {
                pg_close($conn);
                return ['state' => 'error', 'detail' => $syncresult['detail']];
            }
            $tenantadminids[] = (int) $syncresult['userid'];
        }

        $merge = self::merge_siteadmins_pg($conn, $configtable, $tenantadminids);
        pg_close($conn);
        if ($merge['state'] !== 'ok') {
            return ['state' => 'error', 'detail' => $merge['detail']];
        }

        return [
            'state' => 'synced',
            'detail' => count($tenantadminids) . ' administrator(s) synced',
        ];
    }

    /**
     * @param resource $conn
     * @param string $usertable
     * @param \stdClass $parentuser
     * @return array{state:string, detail:string, userid?:int}
     */
    private static function upsert_user_pg($conn, string $usertable, \stdClass $parentuser): array {
        $username = (string) $parentuser->username;
        $mnethostid = (int) ($parentuser->mnethostid ?? 1);

        $res = @pg_query_params(
            $conn,
            "SELECT id FROM {$usertable} WHERE username = $1 AND mnethostid = $2",
            [$username, $mnethostid]
        );
        $existingid = null;
        if ($res && ($row = pg_fetch_assoc($res))) {
            $existingid = (int) $row['id'];
        }
        if ($res) {
            pg_free_result($res);
        }

        $data = self::user_sync_data($parentuser);
        if ($existingid !== null) {
            $sets = [];
            $params = [];
            $i = 1;
            foreach ($data as $column => $value) {
                $col = '"' . str_replace('"', '""', $column) . '"';
                $sets[] = "{$col} = \${$i}";
                $params[] = $value;
                $i++;
            }
            $params[] = $existingid;
            $sql = 'UPDATE ' . $usertable . ' SET ' . implode(', ', $sets) . " WHERE id = \${$i}";
            if (!@pg_query_params($conn, $sql, $params)) {
                return ['state' => 'error', 'detail' => 'Update user failed: ' . pg_last_error($conn)];
            }
            return ['state' => 'ok', 'detail' => '', 'userid' => $existingid];
        }

        $newid = self::next_user_id_pg($conn, $usertable);
        if ($newid === null) {
            return ['state' => 'error', 'detail' => 'Could not allocate user id'];
        }

        $columns = ['id'];
        $placeholders = ['$1'];
        $params = [$newid];
        $i = 2;
        foreach ($data as $column => $value) {
            $columns[] = '"' . str_replace('"', '""', $column) . '"';
            $placeholders[] = '$' . $i;
            $params[] = $value;
            $i++;
        }
        $sql = 'INSERT INTO ' . $usertable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        if (!@pg_query_params($conn, $sql, $params)) {
            return ['state' => 'error', 'detail' => 'Insert user failed: ' . pg_last_error($conn)];
        }
        return ['state' => 'ok', 'detail' => '', 'userid' => $newid];
    }

    /**
     * @param resource $conn
     * @param string $usertable
     * @return int|null
     */
    private static function next_user_id_pg($conn, string $usertable): ?int {
        $res = @pg_query($conn, 'SELECT COALESCE(MAX(id), 0) + 1 AS nextid FROM ' . $usertable);
        if (!$res) {
            return null;
        }
        $row = pg_fetch_assoc($res);
        pg_free_result($res);
        return isset($row['nextid']) ? (int) $row['nextid'] : null;
    }

    /**
     * @param resource $conn
     * @param string $configtable
     * @param int[] $adminids
     * @return array{state:string, detail:string}
     */
    private static function merge_siteadmins_pg($conn, string $configtable, array $adminids): array {
        $current = [];
        $res = @pg_query($conn, "SELECT value FROM {$configtable} WHERE name = 'siteadmins'");
        if ($res && ($row = pg_fetch_assoc($res))) {
            $current = array_values(array_filter(array_map('intval', explode(',', (string) $row['value']))));
        }
        if ($res) {
            pg_free_result($res);
        }

        $merged = self::merge_admin_ids($current, $adminids);
        if (!@pg_query_params(
            $conn,
            "UPDATE {$configtable} SET value = $1 WHERE name = 'siteadmins'",
            [implode(',', $merged)]
        )) {
            return ['state' => 'error', 'detail' => 'Failed to update siteadmins: ' . pg_last_error($conn)];
        }
        return ['state' => 'ok', 'detail' => ''];
    }

    /**
     * @param \stdClass $parentuser
     * @return array<string, mixed>
     */
    private static function user_sync_data(\stdClass $parentuser): array {
        $data = [];
        foreach (self::USER_SYNC_COLUMNS as $column) {
            if (property_exists($parentuser, $column)) {
                $data[$column] = $parentuser->{$column};
            }
        }
        // Ensure active admin account in tenant.
        $data['deleted'] = 0;
        $data['suspended'] = 0;
        $data['confirmed'] = 1;
        return $data;
    }

    /**
     * @param int[] $current
     * @param int[] $newids
     * @return int[]
     */
    private static function merge_admin_ids(array $current, array $newids): array {
        $merged = array_values(array_unique(array_merge($current, $newids)));
        $merged = array_values(array_filter($merged, static function (int $id): bool {
            return $id > 0;
        }));
        sort($merged, SORT_NUMERIC);
        return $merged;
    }

    /**
     * @param \mysqli $conn
     * @param mixed $value
     * @return string
     */
    private static function mysqli_value(\mysqli $conn, $value): string {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return "'" . $conn->real_escape_string((string) $value) . "'";
    }

}
