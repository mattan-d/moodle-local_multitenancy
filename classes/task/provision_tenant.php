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
 * Adhoc task: provision a tenant (dataroot, DB, registry).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provision_tenant extends \core\task\adhoc_task {

    /**
     * @return string
     */
    public function get_name() {
        return get_string('taskprovisiontenant', 'local_multitenancy');
    }

    /**
     * @return void
     */
    public function execute() {
        $data = $this->get_custom_data();
        $tenantid = (int) ($data->tenantid ?? 0);
        $initdb = !empty($data->initdb);
        $copycourses = !empty($data->copycourses);

        if ($tenantid <= 0) {
            mtrace('local_multitenancy: provision_tenant missing tenantid');
            return;
        }

        mtrace('local_multitenancy: provisioning tenant id ' . $tenantid);
        $result = \local_multitenancy\tenant_provisioner::run($tenantid, $initdb, $copycourses);
        if ($result['ok']) {
            mtrace('local_multitenancy: provision complete');
            mtrace($result['detail']);
        } else {
            mtrace('local_multitenancy: provision failed: ' . $result['detail']);
        }
    }
}
