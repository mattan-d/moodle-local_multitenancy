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
 * Scheduled task: sync parent site administrators to tenant databases.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_parent_admins extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name() {
        return get_string('tasksyncparentadmins', 'local_multitenancy');
    }

    /**
     * @return void
     */
    public function execute() {
        $summary = \local_multitenancy\admin_sync::sync_all_enabled_tenants();
        mtrace('local_multitenancy: parent admin sync finished.');
        mtrace('  tenants: ' . $summary['tenants']);
        mtrace('  synced: ' . $summary['synced']);
        mtrace('  skipped: ' . $summary['skipped']);
        mtrace('  errors: ' . $summary['errors']);
        foreach ($summary['messages'] as $line) {
            mtrace('  - ' . $line);
        }
    }
}
