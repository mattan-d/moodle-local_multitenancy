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
 * Aggregate tenant user listings for central admin UI.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_user_directory {

    /** @var int Max users loaded per tenant in one request. */
    private const PER_TENANT_LIMIT = 2000;

    /** @var string[] */
    private const MYSQL_FAMILY = ['mysqli', 'mariadb', 'auroramysql'];

    /** @var string[] */
    private const POSTGRES_FAMILY = ['pgsql'];

    /**
     * Search users across tenants.
     *
     * @param array{tenantid?:int,search?:string,includesuspended?:bool} $filters
     * @return \stdClass[]
     */
    public static function search(array $filters): array {
        global $DB;

        $tenantid = (int) ($filters['tenantid'] ?? 0);
        $search = trim((string) ($filters['search'] ?? ''));
        $includesuspended = !empty($filters['includesuspended']);

        if ($tenantid > 0) {
            $tenant = $DB->get_record('local_multitenancy_tenant', ['id' => $tenantid]);
            $tenants = $tenant ? [$tenant] : [];
        } else {
            $tenants = $DB->get_records('local_multitenancy_tenant', null, 'sortorder ASC, id ASC');
        }

        $rows = [];
        foreach ($tenants as $tenant) {
            if (!database_provisioner::tenant_database_ready($tenant)) {
                continue;
            }
            $dbtype = (string) ($tenant->dbtype ?? '');
            if (in_array($dbtype, self::POSTGRES_FAMILY, true)) {
                $tenantrows = self::fetch_users_pg($tenant, $search, $includesuspended);
            } else if (in_array($dbtype, self::MYSQL_FAMILY, true)) {
                $tenantrows = self::fetch_users_mysql($tenant, $search, $includesuspended);
            } else {
                continue;
            }
            foreach ($tenantrows as $row) {
                $row->tenantid = (int) $tenant->id;
                $row->tenantshortcode = (string) $tenant->shortcode;
                $row->tenantname = (string) $tenant->name;
                $rows[] = $row;
            }
        }

        usort($rows, static function (\stdClass $a, \stdClass $b): int {
            $t = strcasecmp($a->tenantname, $b->tenantname);
            if ($t !== 0) {
                return $t;
            }
            return strcasecmp($a->username, $b->username);
        });

        return $rows;
    }

    /**
     * @param \stdClass $tenant
     * @param string $search
     * @param bool $includesuspended
     * @return \stdClass[]
     */
    private static function fetch_users_mysql(\stdClass $tenant, string $search, bool $includesuspended): array {
        $dbname = (string) ($tenant->dbname ?? '');
        $conn = @new \mysqli(
            (string) ($tenant->dbhost ?? ''),
            (string) ($tenant->dbuser ?? ''),
            (string) ($tenant->dbpass ?? ''),
            $dbname
        );
        if ($conn->connect_errno) {
            return [];
        }

        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = '`' . str_replace('`', '``', $prefix . 'user') . '`';
        $configtable = '`' . str_replace('`', '``', $prefix . 'config') . '`';
        $siteadmins = self::get_siteadmin_ids_mysql($conn, $configtable);

        $where = ['deleted = 0', 'id > 1'];
        if (!$includesuspended) {
            $where[] = 'suspended = 0';
        }

        $params = [];
        $types = '';
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(username LIKE ? OR email LIKE ? OR firstname LIKE ? OR lastname LIKE ? OR idnumber LIKE ?)';
            $params = array_fill(0, 5, $like);
            $types = str_repeat('s', 5);
        }

        $sql = 'SELECT id, username, firstname, lastname, email, auth, suspended, lastlogin, confirmed ' .
            'FROM ' . $usertable . ' WHERE ' . implode(' AND ', $where) .
            ' ORDER BY lastname ASC, firstname ASC, username ASC LIMIT ' . self::PER_TENANT_LIMIT;

        $rows = [];
        if ($types !== '') {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $conn->close();
                return [];
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query($sql);
        }

        if ($res) {
            while ($record = $res->fetch_assoc()) {
                $rows[] = self::normalize_user_row($record, $siteadmins);
            }
        }
        if (isset($stmt)) {
            $stmt->close();
        }
        $conn->close();
        return $rows;
    }

    /**
     * @param \stdClass $tenant
     * @param string $search
     * @param bool $includesuspended
     * @return \stdClass[]
     */
    private static function fetch_users_pg(\stdClass $tenant, string $search, bool $includesuspended): array {
        if (!function_exists('pg_connect')) {
            return [];
        }

        $dbname = (string) ($tenant->dbname ?? '');
        $conn = postgres_helper::connect_tenant($tenant, $dbname);
        if (!$conn) {
            return [];
        }

        $prefix = (string) ($tenant->dbprefix ?? 'mdl_');
        $usertable = '"' . str_replace('"', '""', $prefix . 'user') . '"';
        $configtable = '"' . str_replace('"', '""', $prefix . 'config') . '"';
        $siteadmins = self::get_siteadmin_ids_pg($conn, $configtable);

        $where = ['deleted = 0', 'id > 1'];
        if (!$includesuspended) {
            $where[] = 'suspended = 0';
        }

        $params = [];
        $i = 1;
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(username ILIKE \${$i} OR email ILIKE \${$i} OR firstname ILIKE \${$i} OR lastname ILIKE \${$i} OR idnumber ILIKE \${$i})";
            $params[] = $like;
            $i++;
        }

        $sql = 'SELECT id, username, firstname, lastname, email, auth, suspended, lastlogin, confirmed FROM ' .
            $usertable . ' WHERE ' . implode(' AND ', $where) .
            ' ORDER BY lastname ASC, firstname ASC, username ASC LIMIT ' . self::PER_TENANT_LIMIT;

        $res = $params ? @pg_query_params($conn, $sql, $params) : @pg_query($conn, $sql);
        $rows = [];
        if ($res) {
            while ($record = pg_fetch_assoc($res)) {
                $rows[] = self::normalize_user_row($record, $siteadmins);
            }
            pg_free_result($res);
        }
        pg_close($conn);
        return $rows;
    }

    /**
     * @param array<string, mixed> $record
     * @param int[] $siteadmins
     * @return \stdClass
     */
    private static function normalize_user_row(array $record, array $siteadmins): \stdClass {
        $row = new \stdClass();
        $row->userid = (int) ($record['id'] ?? 0);
        $row->username = (string) ($record['username'] ?? '');
        $row->firstname = (string) ($record['firstname'] ?? '');
        $row->lastname = (string) ($record['lastname'] ?? '');
        $row->email = (string) ($record['email'] ?? '');
        $row->auth = (string) ($record['auth'] ?? '');
        $row->suspended = !empty($record['suspended']);
        $row->confirmed = !empty($record['confirmed']);
        $row->lastlogin = (int) ($record['lastlogin'] ?? 0);
        $row->siteadmin = in_array($row->userid, $siteadmins, true);
        return $row;
    }

    /**
     * @param \mysqli $conn
     * @param string $configtable
     * @return int[]
     */
    private static function get_siteadmin_ids_mysql(\mysqli $conn, string $configtable): array {
        $res = $conn->query("SELECT value FROM {$configtable} WHERE name = 'siteadmins'");
        if (!$res || !($row = $res->fetch_assoc())) {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', (string) $row['value']))));
    }

    /**
     * @param resource $conn
     * @param string $configtable
     * @return int[]
     */
    private static function get_siteadmin_ids_pg($conn, string $configtable): array {
        $res = @pg_query($conn, "SELECT value FROM {$configtable} WHERE name = 'siteadmins'");
        if (!$res || !($row = pg_fetch_assoc($res))) {
            return [];
        }
        pg_free_result($res);
        return array_values(array_filter(array_map('intval', explode(',', (string) $row['value']))));
    }

}
