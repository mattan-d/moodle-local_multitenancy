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
 * Create a user in a tenant database from the hub admin UI.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenant_user_create {

    /**
     * Create user in the selected tenant.
     *
     * @param int $tenantid
     * @param \stdClass $formdata Form submission (tenantid, username, newpassword, …).
     * @return array{ok:bool, message:string}
     */
    public static function create(int $tenantid, \stdClass $formdata): array {
        global $DB;

        if ($tenantid < 1) {
            return ['ok' => false, 'message' => get_string('usercreate_tenant_required', 'local_multitenancy')];
        }

        $tenant = $DB->get_record('local_multitenancy_tenant', ['id' => $tenantid], '*', IGNORE_MISSING);
        if (!$tenant || empty($tenant->enabled)) {
            return ['ok' => false, 'message' => get_string('usercreate_tenant_not_found', 'local_multitenancy')];
        }

        if (!database_provisioner::tenant_database_ready($tenant)) {
            return ['ok' => false, 'message' => get_string('usercreate_tenant_not_ready', 'local_multitenancy')];
        }

        $dbtype = (string) ($tenant->dbtype ?? '');
        if (!in_array($dbtype, ['mysqli', 'mariadb', 'auroramysql', 'pgsql'], true)) {
            return ['ok' => false, 'message' => get_string('usertransfer_unsupported_db', 'local_multitenancy')];
        }

        if (empty($formdata->newpassword)) {
            return ['ok' => false, 'message' => get_string('usercreate_password_required', 'local_multitenancy')];
        }

        $user = self::build_user_from_form($formdata);
        $result = tenant_user_transfer::insert_new_user($tenant, $user);
        if (!$result['ok']) {
            return $result;
        }

        return [
            'ok' => true,
            'message' => get_string('usercreate_success', 'local_multitenancy', (object) [
                'user' => fullname($user) . ' (' . s($user->username) . ')',
                'tenant' => format_string($tenant->name),
            ]),
        ];
    }

    /**
     * @param \stdClass $formdata
     * @return \stdClass
     */
    public static function build_user_from_form(\stdClass $formdata): \stdClass {
        global $CFG;

        $now = time();
        $user = new \stdClass();
        $user->username = \core_text::strtolower(trim((string) ($formdata->username ?? '')));
        $user->mnethostid = 1;
        $user->auth = 'manual';
        $user->confirmed = 1;
        $user->policyagreed = 0;
        $user->deleted = 0;
        $user->suspended = 0;
        $user->timecreated = $now;
        $user->timemodified = $now;
        $user->lang = $CFG->lang;
        $user->timezone = $CFG->timezone;
        $user->calendartype = 'gregorian';
        $user->mailformat = 1;
        $user->maildigest = 0;
        $user->maildisplay = 2;
        $user->autosubscribe = 1;
        $user->trackforums = 0;
        $user->descriptionformat = 1;
        $user->picture = 0;
        $user->emailstop = 0;

        return tenant_user_transfer::apply_form_data($user, $formdata);
    }
}
