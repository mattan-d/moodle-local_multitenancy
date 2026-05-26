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
 * Move a user from one tenant database to another.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/classes/form/user_transfer_form.php');

use local_multitenancy\database_provisioner;
use local_multitenancy\tenant_user_transfer;

global $DB, $PAGE, $OUTPUT;

admin_externalpage_setup('local_multitenancy_users');

require_capability('local/multitenancy:manage', context_system::instance());

$sourcetenantid = required_param('sourcetenantid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

$returnparams = [
    'tenantid' => optional_param('tenantid', 0, PARAM_INT),
    'search' => optional_param('search', '', PARAM_RAW),
    'includesuspended' => optional_param('includesuspended', 0, PARAM_INT),
    'perpage' => optional_param('perpage', 50, PARAM_INT),
    'page' => optional_param('page', 0, PARAM_INT),
];

$returnurl = new moodle_url('/local/multitenancy/users_manage.php', $returnparams);

$sourcetenant = $DB->get_record('local_multitenancy_tenant', ['id' => $sourcetenantid], '*', MUST_EXIST);
$user = tenant_user_transfer::fetch_user($sourcetenant, $userid);
if ($user === null) {
    throw new moodle_exception('usertransfer_user_not_found', 'local_multitenancy');
}

$targetoptions = [];
$tenants = $DB->get_records('local_multitenancy_tenant', null, 'sortorder ASC, id ASC');
foreach ($tenants as $tenant) {
    if ((int) $tenant->id === $sourcetenantid) {
        continue;
    }
    if (!database_provisioner::tenant_database_ready($tenant)) {
        continue;
    }
    $targetoptions[$tenant->id] = format_string($tenant->name) . ' (' . s($tenant->shortcode) . ')';
}

if ($targetoptions === []) {
    redirect(
        $returnurl,
        get_string('usertransfer_no_targets', 'local_multitenancy'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$PAGE->set_url(new moodle_url('/local/multitenancy/user_transfer.php', [
    'sourcetenantid' => $sourcetenantid,
    'userid' => $userid,
] + $returnparams));
$PAGE->set_title(get_string('usertransfer_title', 'local_multitenancy'));
$PAGE->set_heading(get_string('usertransfer_title', 'local_multitenancy'));

$form = new \local_multitenancy\form\user_transfer_form(null, [
    'user' => $user,
    'sourcetenant' => $sourcetenant,
    'targetoptions' => $targetoptions,
]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $form->get_data()) {
    $result = tenant_user_transfer::transfer(
        (int) $data->sourcetenantid,
        (int) $data->userid,
        (int) $data->targettenantid,
        $data
    );
    if ($result['ok']) {
        redirect($returnurl, $result['message'], null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($returnurl, $result['message'], null, \core\output\notification::NOTIFY_ERROR);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('usertransfer_title', 'local_multitenancy'));
echo html_writer::tag('p', get_string('usertransfer_intro', 'local_multitenancy'), ['class' => 'mb-3']);
$form->display();
echo $OUTPUT->single_button($returnurl, get_string('back'), 'get');
echo $OUTPUT->footer();
