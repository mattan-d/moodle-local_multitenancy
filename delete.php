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

/**
 * Delete a tenant.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);

admin_externalpage_setup('local_multitenancy_manage');

require_capability('local/multitenancy:manage', context_system::instance());

$tenant = $DB->get_record('local_multitenancy_tenant', ['id' => $id], '*', MUST_EXIST);
$returnurl = new moodle_url('/local/multitenancy/manage.php');

if (optional_param('confirm', 0, PARAM_INT) && confirm_sesskey()) {
    $DB->delete_records('local_multitenancy_tenant', ['id' => $id]);
    \local_multitenancy\gateway_manager::sync();
    if (\local_multitenancy\registry_writer::sync()) {
        \core\notification::success(get_string('tenantdeleted', 'local_multitenancy'));
    } else {
        \core\notification::warning(get_string('registrynotwritten', 'local_multitenancy'));
    }
    redirect($returnurl);
}

$PAGE->set_url(new moodle_url('/local/multitenancy/delete.php', ['id' => $id]));
$PAGE->set_title(get_string('deletetenant', 'local_multitenancy'));

echo $OUTPUT->header();

$confirmurl = new moodle_url('/local/multitenancy/delete.php', [
    'id' => $id,
    'confirm' => 1,
    'sesskey' => sesskey(),
]);
echo $OUTPUT->confirm(
    get_string('deleteconfirm', 'local_multitenancy', format_string($tenant->name)),
    $confirmurl,
    $returnurl
);

echo $OUTPUT->footer();
