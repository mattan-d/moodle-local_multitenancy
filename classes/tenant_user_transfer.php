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
 * Move a user record from one tenant database to another.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_user_transfer {

    /** @var string[] */
    private const USER_COLUMNS = [
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

    /** @var string[] Fields editable on the transfer form before upsert. */
    public const EDITABLE_FIELDS = [
        'username', 'firstname', 'lastname', 'email', 'idnumber', 'auth', 'suspended',
        'phone1', 'phone2', 'institution', 'department', 'address', 'city',
        'country', 'lang', 'timezone', 'alternatename', 'middlename',
        'firstnamephonetic', 'lastnamephonetic', 'description',
    ];

    /**
     * Transfer user to another tenant (copy/upsert target, soft-delete source).
     *
     * @param int $sourcetenantid
     * @param int $userid Tenant-local user id.
     * @param int $targettenantid
     * @param \stdClass|null $formdata Optional field overrides from the transfer form.
     * @return array{ok:bool, message:string}
     */
    public static function transfer(
        int $sourcetenantid,
        int $userid,
        int $targettenantid,
        ?\stdClass $formdata = null
    ): array {
        global $DB;

        if ($sourcetenantid === $targettenantid) {
            return ['ok' => false, 'message' => get_string('usertransfer_same_tenant', 'local_multitenancy')];
        }
        if ($userid < 2) {
            return ['ok' => false, 'message' => get_string('usertransfer_invalid_user', 'local_multitenancy')];
        }

        $source = $DB->get_record('local_multitenancy_tenant', ['id' => $sourcetenantid], '*', MUST_EXIST);
        $target = $DB->get_record('local_multitenancy_tenant', ['id' => $targettenantid], '*', MUST_EXIST);

        if (!database_provisioner::tenant_database_ready($source)) {
            return ['ok' => false, 'message' => get_string('usertransfer_source_not_ready', 'local_multitenancy')];
        }
        if (!database_provisioner::tenant_database_ready($target)) {
            return ['ok' => false, 'message' => get_string('usertransfer_target_not_ready', 'local_multitenancy')];
        }

        $sourcetype = (string) ($source->dbtype ?? '');
        $targettype = (string) ($target->dbtype ?? '');
        if (!self::dbtype_supported($sourcetype) || !self::dbtype_supported($targettype)) {
            return ['ok' => false, 'message' => get_string('usertransfer_unsupported_db', 'local_multitenancy')];
        }

        $user = self::fetch_user($source, $userid);
        if ($user === null) {
            return ['ok' => false, 'message' => get_string('usertransfer_user_not_found', 'local_multitenancy')];
        }
        if (!empty($user->siteadmin)) {
            return ['ok' => false, 'message' => get_string('usertransfer_siteadmin_blocked', 'local_multitenancy')];
        }

        if ($formdata !== null) {
            $user = self::apply_form_data($user, $formdata);
        }

        $emailconflict = self::email_conflict($target, (string) $user->email, (string) $user->username);
        if ($emailconflict !== null) {
            return ['ok' => false, 'message' => get_string('usertransfer_email_conflict', 'local_multitenancy', $emailconflict)];
        }

        $upsert = self::upsert_user($target, $user);
        if (!$upsert['ok']) {
            return $upsert;
        }

        $remove = self::soft_delete_user($source, $userid);
        if (!$remove['ok']) {
            return [
                'ok' => false,
                'message' => get_string('usertransfer_partial', 'local_multitenancy', (object) [
                    'target' => format_string($target->name),
                    'error' => $remove['message'],
                ]),
            ];
        }

        return [
            'ok' => true,
            'message' => get_string('usertransfer_success', 'local_multitenancy', (object) [
                'user' => fullname($user) . ' (' . s($user->username) . ')',
                'source' => format_string($source->name),
                'target' => format_string($target->name),
            ]),
        ];
    }

    /**
     * Merge transfer-form values onto the tenant user record.
     *
     * @param \stdClass $user
     * @param \stdClass $formdata
     * @return \stdClass
     */
    public static function apply_form_data(\stdClass $user, \stdClass $formdata): \stdClass {
        foreach (self::EDITABLE_FIELDS as $field) {
            if (property_exists($formdata, $field)) {
                $user->{$field} = $formdata->{$field};
            }
        }
        $user->suspended = !empty($formdata->suspended) ? 1 : 0;

        if (!empty($formdata->newpassword)) {
            $user->password = hash_internal_user_password($formdata->newpassword);
        }

        return $user;
    }

    /**
     * @param string $dbtype
     * @return bool
     */
    private static function dbtype_supported(string $dbtype): bool {
        return in_array($dbtype, self::MYSQL_FAMILY, true) || in_array($dbtype, self::POSTGRES_FAMILY, true);
    }

    /**
     * @param \stdClass $tenant
     * @param int $userid
     * @return \stdClass|null
     */
    public static function fetch_user(\stdClass $tenant, int $userid): ?\stdClass {
        $dbtype = (string) ($tenant->dbtype ?? '');
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            return self::fetch_user_pg($tenant, $userid);
        }
        if (in_array($dbtype, self::MYSQL_FAMILY, true)) {
            return self::fetch_user_mysql($tenant, $userid);
        }
        return null;
    }

    /**
     * @param \stdClass $tenant
     * @param int $userid
     * @return \stdClass|null
     */
    private static function fetch_user_mysql(\stdClass $tenant, int $userid): ?\stdClass {
        $conn = self::connect_mysql($tenant);
        if (!$conn) {
            return null;
        }

        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = '`' . str_replace('`', '``', $prefix . 'user') . '`';
        $configtable = '`' . str_replace('`', '``', $prefix . 'config') . '`';
        $cols = array_merge(['id'], self::USER_COLUMNS);
        $collist = implode(', ', array_map(static function (string $c): string {
            return '`' . str_replace('`', '``', $c) . '`';
        }, $cols));

        $stmt = $conn->prepare("SELECT {$collist} FROM {$usertable} WHERE id = ? AND deleted = 0");
        if (!$stmt) {
            $conn->close();
            return null;
        }
        $stmt->bind_param('i', $userid);
        $stmt->execute();
        $res = $stmt->get_result();
        $record = ($res && ($row = $res->fetch_assoc())) ? $row : null;
        $stmt->close();

        $siteadmins = [];
        if ($record) {
            $admres = $conn->query("SELECT value FROM {$configtable} WHERE name = 'siteadmins'");
            if ($admres && ($admrow = $admres->fetch_assoc())) {
                $siteadmins = array_values(array_filter(array_map('intval', explode(',', (string) $admrow['value']))));
            }
        }
        $conn->close();

        if (!$record) {
            return null;
        }

        $user = (object) $record;
        $user->siteadmin = in_array((int) $user->id, $siteadmins, true);
        return $user;
    }

    /**
     * @param \stdClass $tenant
     * @param int $userid
     * @return \stdClass|null
     */
    private static function fetch_user_pg(\stdClass $tenant, int $userid): ?\stdClass {
        $dbname = (string) ($tenant->dbname ?? '');
        $conn = postgres_helper::connect_tenant($tenant, $dbname);
        if (!$conn) {
            return null;
        }

        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = postgres_helper::quote_ident($prefix . 'user');
        $configtable = postgres_helper::quote_ident($prefix . 'config');
        $cols = array_merge(['id'], self::USER_COLUMNS);
        $collist = implode(', ', array_map(static function (string $c): string {
            return '"' . str_replace('"', '""', $c) . '"';
        }, $cols));

        $res = @pg_query_params($conn, "SELECT {$collist} FROM {$usertable} WHERE id = $1 AND deleted = 0", [$userid]);
        $record = ($res && ($row = pg_fetch_assoc($res))) ? $row : null;
        if ($res) {
            pg_free_result($res);
        }

        $siteadmins = [];
        if ($record) {
            $admres = @pg_query($conn, "SELECT value FROM {$configtable} WHERE name = 'siteadmins'");
            if ($admres && ($admrow = pg_fetch_assoc($admres))) {
                $siteadmins = array_values(array_filter(array_map('intval', explode(',', (string) $admrow['value']))));
            }
            if ($admres) {
                pg_free_result($admres);
            }
        }
        pg_close($conn);

        if (!$record) {
            return null;
        }

        $user = (object) $record;
        $user->siteadmin = in_array((int) $user->id, $siteadmins, true);
        return $user;
    }

    /**
     * Insert a new user into a tenant database (fails if username or email already exists).
     *
     * @param \stdClass $tenant
     * @param \stdClass $user User row fields (password hash required for manual auth).
     * @return array{ok:bool, message:string}
     */
    public static function insert_new_user(\stdClass $tenant, \stdClass $user): array {
        $username = trim((string) ($user->username ?? ''));
        $mnethostid = (int) ($user->mnethostid ?? 1);
        if ($username === '') {
            return ['ok' => false, 'message' => get_string('usercreate_invalid_username', 'local_multitenancy')];
        }
        if (self::username_exists($tenant, $username, $mnethostid)) {
            return ['ok' => false, 'message' => get_string('usercreate_username_exists', 'local_multitenancy', $username)];
        }

        $emailconflict = self::email_conflict($tenant, (string) ($user->email ?? ''), $username);
        if ($emailconflict !== null) {
            return ['ok' => false, 'message' => get_string('usercreate_email_conflict', 'local_multitenancy', $emailconflict)];
        }

        return self::upsert_user($tenant, $user);
    }

    /**
     * @param \stdClass $tenant
     * @param string $username
     * @param int $mnethostid
     * @return bool
     */
    public static function username_exists(\stdClass $tenant, string $username, int $mnethostid = 1): bool {
        $dbtype = (string) ($tenant->dbtype ?? '');
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            return self::username_exists_pg($tenant, $username, $mnethostid);
        }
        return self::username_exists_mysql($tenant, $username, $mnethostid);
    }

    /**
     * @param \stdClass $tenant
     * @param string $username
     * @param int $mnethostid
     * @return bool
     */
    private static function username_exists_mysql(\stdClass $tenant, string $username, int $mnethostid): bool {
        $conn = self::connect_mysql($tenant);
        if (!$conn) {
            return false;
        }
        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = '`' . str_replace('`', '``', $prefix . 'user') . '`';
        $stmt = $conn->prepare("SELECT id FROM {$usertable} WHERE username = ? AND mnethostid = ? AND deleted = 0 LIMIT 1");
        if (!$stmt) {
            $conn->close();
            return false;
        }
        $stmt->bind_param('si', $username, $mnethostid);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = $res && $res->fetch_assoc();
        $stmt->close();
        $conn->close();
        return (bool) $exists;
    }

    /**
     * @param \stdClass $tenant
     * @param string $username
     * @param int $mnethostid
     * @return bool
     */
    private static function username_exists_pg(\stdClass $tenant, string $username, int $mnethostid): bool {
        $dbname = (string) ($tenant->dbname ?? '');
        $conn = postgres_helper::connect_tenant($tenant, $dbname);
        if (!$conn) {
            return false;
        }
        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = postgres_helper::quote_ident($prefix . 'user');
        $res = @pg_query_params(
            $conn,
            "SELECT id FROM {$usertable} WHERE username = $1 AND mnethostid = $2 AND deleted = 0 LIMIT 1",
            [$username, $mnethostid]
        );
        $exists = $res && pg_fetch_assoc($res);
        if ($res) {
            pg_free_result($res);
        }
        pg_close($conn);
        return (bool) $exists;
    }

    /**
     * @param \stdClass $tenant
     * @param string $email
     * @param string $username
     * @return \stdClass|null Conflicting user summary.
     */
    private static function email_conflict(\stdClass $tenant, string $email, string $username): ?\stdClass {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        $dbtype = (string) ($tenant->dbtype ?? '');
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            return self::email_conflict_pg($tenant, $email, $username);
        }
        return self::email_conflict_mysql($tenant, $email, $username);
    }

    /**
     * @param \stdClass $tenant
     * @param string $email
     * @param string $username
     * @return \stdClass|null
     */
    private static function email_conflict_mysql(\stdClass $tenant, string $email, string $username): ?\stdClass {
        $conn = self::connect_mysql($tenant);
        if (!$conn) {
            return null;
        }
        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = '`' . str_replace('`', '``', $prefix . 'user') . '`';
        $stmt = $conn->prepare("SELECT id, username FROM {$usertable} WHERE email = ? AND deleted = 0 AND username <> ? LIMIT 1");
        if (!$stmt) {
            $conn->close();
            return null;
        }
        $stmt->bind_param('ss', $email, $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && ($r = $res->fetch_assoc())) ? (object) $r : null;
        $stmt->close();
        $conn->close();
        return $row;
    }

    /**
     * @param \stdClass $tenant
     * @param string $email
     * @param string $username
     * @return \stdClass|null
     */
    private static function email_conflict_pg(\stdClass $tenant, string $email, string $username): ?\stdClass {
        $dbname = (string) ($tenant->dbname ?? '');
        $conn = postgres_helper::connect_tenant($tenant, $dbname);
        if (!$conn) {
            return null;
        }
        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = postgres_helper::quote_ident($prefix . 'user');
        $res = @pg_query_params(
            $conn,
            "SELECT id, username FROM {$usertable} WHERE email = $1 AND deleted = 0 AND username <> $2 LIMIT 1",
            [$email, $username]
        );
        $row = ($res && ($r = pg_fetch_assoc($res))) ? (object) $r : null;
        if ($res) {
            pg_free_result($res);
        }
        pg_close($conn);
        return $row;
    }

    /**
     * @param \stdClass $tenant
     * @param \stdClass $user
     * @return array{ok:bool, message:string}
     */
    private static function upsert_user(\stdClass $tenant, \stdClass $user): array {
        $dbtype = (string) ($tenant->dbtype ?? '');
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            return self::upsert_user_pg($tenant, $user);
        }
        return self::upsert_user_mysql($tenant, $user);
    }

    /**
     * @param \stdClass $tenant
     * @param \stdClass $user
     * @return array{ok:bool, message:string}
     */
    private static function upsert_user_mysql(\stdClass $tenant, \stdClass $user): array {
        $conn = self::connect_mysql($tenant);
        if (!$conn) {
            return ['ok' => false, 'message' => get_string('usertransfer_db_connect', 'local_multitenancy')];
        }

        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = '`' . str_replace('`', '``', $prefix . 'user') . '`';
        $username = (string) $user->username;
        $mnethostid = (int) ($user->mnethostid ?? 1);

        $stmt = $conn->prepare("SELECT id FROM {$usertable} WHERE username = ? AND mnethostid = ?");
        if (!$stmt) {
            $conn->close();
            return ['ok' => false, 'message' => $conn->error];
        }
        $stmt->bind_param('si', $username, $mnethostid);
        $stmt->execute();
        $res = $stmt->get_result();
        $existingid = ($res && ($row = $res->fetch_assoc())) ? (int) $row['id'] : null;
        $stmt->close();

        $data = self::user_payload($user);
        if ($existingid !== null) {
            $sets = [];
            foreach ($data as $column => $value) {
                $sets[] = '`' . str_replace('`', '``', $column) . '` = ' . self::mysqli_value($conn, $value);
            }
            $sql = 'UPDATE ' . $usertable . ' SET ' . implode(', ', $sets) . ' WHERE id = ' . $existingid;
            if (!$conn->query($sql)) {
                $conn->close();
                return ['ok' => false, 'message' => $conn->error];
            }
            $conn->close();
            return ['ok' => true, 'message' => ''];
        }

        $newid = self::next_user_id_mysql($conn, $usertable);
        if ($newid === null) {
            $conn->close();
            return ['ok' => false, 'message' => get_string('usertransfer_allocate_id', 'local_multitenancy')];
        }

        $columns = ['id'];
        $values = [(string) $newid];
        foreach ($data as $column => $value) {
            $columns[] = '`' . str_replace('`', '``', $column) . '`';
            $values[] = self::mysqli_value($conn, $value);
        }
        $sql = 'INSERT INTO ' . $usertable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
        if (!$conn->query($sql)) {
            $err = $conn->error;
            $conn->close();
            return ['ok' => false, 'message' => $err];
        }
        $conn->close();
        return ['ok' => true, 'message' => ''];
    }

    /**
     * @param \stdClass $tenant
     * @param \stdClass $user
     * @return array{ok:bool, message:string}
     */
    private static function upsert_user_pg(\stdClass $tenant, \stdClass $user): array {
        $dbname = (string) ($tenant->dbname ?? '');
        $conn = postgres_helper::connect_tenant($tenant, $dbname);
        if (!$conn) {
            return ['ok' => false, 'message' => get_string('usertransfer_db_connect', 'local_multitenancy')];
        }

        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = postgres_helper::quote_ident($prefix . 'user');
        $username = (string) $user->username;
        $mnethostid = (int) ($user->mnethostid ?? 1);

        $res = @pg_query_params(
            $conn,
            "SELECT id FROM {$usertable} WHERE username = $1 AND mnethostid = $2",
            [$username, $mnethostid]
        );
        $existingid = ($res && ($row = pg_fetch_assoc($res))) ? (int) $row['id'] : null;
        if ($res) {
            pg_free_result($res);
        }

        $data = self::user_payload($user);
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
                $err = pg_last_error($conn);
                pg_close($conn);
                return ['ok' => false, 'message' => $err];
            }
            pg_close($conn);
            return ['ok' => true, 'message' => ''];
        }

        $newid = self::next_user_id_pg($conn, $usertable);
        if ($newid === null) {
            pg_close($conn);
            return ['ok' => false, 'message' => get_string('usertransfer_allocate_id', 'local_multitenancy')];
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
            $err = pg_last_error($conn);
            pg_close($conn);
            return ['ok' => false, 'message' => $err];
        }
        pg_close($conn);
        return ['ok' => true, 'message' => ''];
    }

    /**
     * @param \stdClass $tenant
     * @param int $userid
     * @return array{ok:bool, message:string}
     */
    private static function soft_delete_user(\stdClass $tenant, int $userid): array {
        $dbtype = (string) ($tenant->dbtype ?? '');
        if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
            return self::soft_delete_user_pg($tenant, $userid);
        }
        return self::soft_delete_user_mysql($tenant, $userid);
    }

    /**
     * @param \stdClass $tenant
     * @param int $userid
     * @return array{ok:bool, message:string}
     */
    private static function soft_delete_user_mysql(\stdClass $tenant, int $userid): array {
        $conn = self::connect_mysql($tenant);
        if (!$conn) {
            return ['ok' => false, 'message' => get_string('usertransfer_db_connect', 'local_multitenancy')];
        }
        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = '`' . str_replace('`', '``', $prefix . 'user') . '`';
        $now = time();
        $stmt = $conn->prepare("UPDATE {$usertable} SET deleted = 1, suspended = 1, timemodified = ? WHERE id = ? AND deleted = 0");
        if (!$stmt) {
            $err = $conn->error;
            $conn->close();
            return ['ok' => false, 'message' => $err];
        }
        $stmt->bind_param('ii', $now, $userid);
        $ok = $stmt->execute() && $stmt->affected_rows > 0;
        $stmt->close();
        $conn->close();
        if (!$ok) {
            return ['ok' => false, 'message' => get_string('usertransfer_remove_failed', 'local_multitenancy')];
        }
        return ['ok' => true, 'message' => ''];
    }

    /**
     * @param \stdClass $tenant
     * @param int $userid
     * @return array{ok:bool, message:string}
     */
    private static function soft_delete_user_pg(\stdClass $tenant, int $userid): array {
        $dbname = (string) ($tenant->dbname ?? '');
        $conn = postgres_helper::connect_tenant($tenant, $dbname);
        if (!$conn) {
            return ['ok' => false, 'message' => get_string('usertransfer_db_connect', 'local_multitenancy')];
        }
        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = postgres_helper::quote_ident($prefix . 'user');
        $now = time();
        $res = @pg_query_params(
            $conn,
            "UPDATE {$usertable} SET deleted = 1, suspended = 1, timemodified = $1 WHERE id = $2 AND deleted = 0",
            [$now, $userid]
        );
        $ok = $res && pg_affected_rows($res) > 0;
        if ($res) {
            pg_free_result($res);
        }
        pg_close($conn);
        if (!$ok) {
            return ['ok' => false, 'message' => get_string('usertransfer_remove_failed', 'local_multitenancy')];
        }
        return ['ok' => true, 'message' => ''];
    }

    /**
     * @param \stdClass $user
     * @return array<string, mixed>
     */
    private static function user_payload(\stdClass $user): array {
        $data = [];
        foreach (self::USER_COLUMNS as $column) {
            if (property_exists($user, $column)) {
                $data[$column] = $user->{$column};
            }
        }
        $data['deleted'] = 0;
        $data['suspended'] = (int) ($user->suspended ?? 0);
        $data['confirmed'] = (int) ($user->confirmed ?? 1);
        $data['timemodified'] = time();
        return $data;
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
     * @param \stdClass $tenant
     * @return \mysqli|null
     */
    private static function connect_mysql(\stdClass $tenant): ?\mysqli {
        $conn = @new \mysqli(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            (string) ($tenant->dbname ?? '')
        );
        return $conn->connect_errno ? null : $conn;
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
