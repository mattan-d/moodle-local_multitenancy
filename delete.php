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
 * Delete a tenant, including its database and moodledata (dataroot).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_multitenancy\database_provisioner;
use local_multitenancy\gateway_manager;
use local_multitenancy\provision_logger;
use local_multitenancy\registry_writer;

$id = required_param('id', PARAM_INT);

admin_externalpage_setup('local_multitenancy_manage');

require_capability('local/multitenancy:manage', context_system::instance());

$tenant = $DB->get_record('local_multitenancy_tenant', ['id' => $id], '*', MUST_EXIST);
$returnurl = new moodle_url('/local/multitenancy/manage.php');

if (optional_param('confirm', 0, PARAM_INT) && confirm_sesskey()) {
    // Full delete by default: DB + moodledata. Soft delete only when explicitly requested.
    $recordonly = (bool) optional_param('recordonly', 0, PARAM_BOOL);

    if (!$recordonly) {
        $dbresult = database_provisioner::drop_tenant_database($tenant);
        if (!$dbresult['ok']) {
            \core\notification::error(get_string('tenantdelete_dbfailed', 'local_multitenancy', $dbresult['detail']));
            redirect(new moodle_url('/local/multitenancy/delete.php', ['id' => $id]));
        }

        $fileresult = database_provisioner::remove_tenant_dataroot($tenant);
        if (!$fileresult['ok']) {
            \core\notification::error(get_string('tenantdelete_datarootfailed', 'local_multitenancy', $fileresult['detail']));
            redirect(new moodle_url('/local/multitenancy/delete.php', ['id' => $id]));
        }

        provision_logger::delete_log((string) $tenant->shortcode);
    }

    $DB->delete_records('local_multitenancy_tenant', ['id' => $id]);
    gateway_manager::sync();
    if (registry_writer::sync()) {
        if ($recordonly) {
            \core\notification::success(get_string('tenantdeleted', 'local_multitenancy'));
        } else {
            \core\notification::success(get_string('tenantdeleted_full', 'local_multitenancy'));
        }
    } else {
        \core\notification::warning(get_string('registrynotwritten', 'local_multitenancy'));
    }
    redirect($returnurl);
}

$PAGE->set_url(new moodle_url('/local/multitenancy/delete.php', ['id' => $id]));
$PAGE->set_title(get_string('deletetenant', 'local_multitenancy'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('deletetenant', 'local_multitenancy'));

echo html_writer::div(get_string('deleteconfirm', 'local_multitenancy', format_string($tenant->name)), 'mb-3');

$details = [];
$details[] = get_string('shortcode', 'local_multitenancy') . ': ' . s($tenant->shortcode);
$details[] = get_string('dbname', 'local_multitenancy') . ': ' . s((string) $tenant->dbname);
$details[] = get_string('dataroot', 'local_multitenancy') . ': ' . s((string) $tenant->dataroot);
echo html_writer::alist($details);

echo html_writer::div(get_string('deleteconfirm_warning', 'local_multitenancy'), 'alert alert-danger mt-3');

$actionurl = new moodle_url('/local/multitenancy/delete.php');
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $actionurl->out(false),
    'class' => 'mform mt-3',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => 1]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('form-check mb-3');
echo html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'class' => 'form-check-input',
    'name' => 'recordonly',
    'id' => 'id_recordonly',
    'value' => 1,
]);
echo html_writer::label(get_string('deleteoption_recordonly', 'local_multitenancy'), 'id_recordonly', false, ['class' => 'form-check-label']);
echo html_writer::end_div();

echo html_writer::start_div('mt-3');
echo html_writer::tag('button', get_string('deleteconfirm_submit', 'local_multitenancy'), [
    'type' => 'submit',
    'class' => 'btn btn-danger',
]);
echo ' ';
echo html_writer::link($returnurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

echo html_writer::end_tag('form');

echo $OUTPUT->footer();
