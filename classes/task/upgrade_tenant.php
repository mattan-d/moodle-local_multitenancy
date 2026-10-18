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

namespace local_multitenancy\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Adhoc task: run Moodle CLI upgrade for one tenant database.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upgrade_tenant extends \core\task\adhoc_task {

    /**
     * @return string
     */
    public function get_name() {
        return get_string('taskupgradetenant', 'local_multitenancy');
    }

    /**
     * @return void
     */
    public function execute() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/local/multitenancy/lib.php');
        if (function_exists('local_multitenancy_is_parent_site') && !local_multitenancy_is_parent_site()) {
            mtrace('local_multitenancy: skipping tenant upgrade task (not the parent site).');
            return;
        }

        $data = $this->get_custom_data();
        $tenantid = isset($data->tenantid) ? (int) $data->tenantid : 0;
        if ($tenantid < 1) {
            mtrace('local_multitenancy: upgrade_tenant missing tenantid');
            return;
        }

        $tenant = $DB->get_record('local_multitenancy_tenant', ['id' => $tenantid]);
        if (!$tenant) {
            mtrace('local_multitenancy: upgrade_tenant tenant not found: ' . $tenantid);
            return;
        }

        mtrace('local_multitenancy: upgrading tenant ' . $tenant->shortcode);
        $result = \local_multitenancy\tenant_upgrader::run_cli_upgrade($tenant);
        if ($result['ok']) {
            mtrace('local_multitenancy: upgrade OK for ' . $tenant->shortcode);
        } else {
            mtrace('local_multitenancy: upgrade FAILED for ' . $tenant->shortcode . ': ' . $result['detail']);
        }
    }
}
