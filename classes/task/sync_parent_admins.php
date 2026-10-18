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
 * Scheduled task (parent cron.php): sync settings + run tenant DB upgrades.
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
        global $CFG;

        // Only the parent (hub) site owns the tenant list; tenant DBs contain a cloned
        // copy of it, so running this from a tenant cron would sync in the wrong direction.
        require_once($CFG->dirroot . '/local/multitenancy/lib.php');
        if (function_exists('local_multitenancy_is_parent_site') && !local_multitenancy_is_parent_site()) {
            mtrace('local_multitenancy: skipping parent admin/settings sync (not the parent site).');
            return;
        }

        $retried = \local_multitenancy\tenant_provisioner::retry_incomplete_failures();
        if ($retried > 0) {
            mtrace('local_multitenancy: auto-retried incomplete provisioning for ' . $retried . ' tenant(s).');
        }

        // Tenant DB upgrades via parent cron (admin/cli/cron.php) — run CLI upgrade now,
        // do not only queue adhoc tasks (those can sit if adhoc processing is delayed).
        mtrace('local_multitenancy: checking tenant database upgrades...');
        $up = \local_multitenancy\tenant_upgrader::run_pending_upgrades(false);
        mtrace('local_multitenancy: tenant upgrades finished.');
        mtrace('  ran: ' . $up['ran'] . ', ok: ' . $up['ok'] . ', fail: ' . $up['fail'] . ', skipped: ' . $up['skipped']);
        foreach ($up['messages'] as $line) {
            mtrace('  - ' . $line);
        }

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
