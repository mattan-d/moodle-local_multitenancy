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
 * Create a user in a tenant database.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/classes/tenant_user_create.php');
require_once(__DIR__ . '/classes/form/user_create_form.php');

use local_multitenancy\database_provisioner;
use local_multitenancy\tenant_user_create;

global $DB, $PAGE, $OUTPUT;

admin_externalpage_setup('local_multitenancy_users');

require_capability('local/multitenancy:manage', context_system::instance());

$returnparams = [
    'tenantid' => optional_param('tenantid', 0, PARAM_INT),
    'search' => optional_param('search', '', PARAM_RAW),
    'includesuspended' => optional_param('includesuspended', 0, PARAM_INT),
    'perpage' => optional_param('perpage', 50, PARAM_INT),
    'page' => optional_param('page', 0, PARAM_INT),
];

$returnurl = new moodle_url('/local/multitenancy/users_manage.php', $returnparams);

$tenantoptions = [];
$tenants = $DB->get_records('local_multitenancy_tenant', ['enabled' => 1], 'sortorder ASC, id ASC');
foreach ($tenants as $tenant) {
    if (!database_provisioner::tenant_database_ready($tenant)) {
        continue;
    }
    $tenantoptions[$tenant->id] = format_string($tenant->name) . ' (' . s($tenant->shortcode) . ')';
}

if ($tenantoptions === []) {
    redirect(
        $returnurl,
        get_string('usercreate_no_tenants', 'local_multitenancy'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$defaulttenantid = (int) $returnparams['tenantid'];
if ($defaulttenantid > 0 && !isset($tenantoptions[$defaulttenantid])) {
    $defaulttenantid = 0;
}

$PAGE->set_url(new moodle_url('/local/multitenancy/user_create.php', $returnparams));
$PAGE->set_title(get_string('usercreate_title', 'local_multitenancy'));
$PAGE->set_heading(get_string('usercreate_title', 'local_multitenancy'));

$form = new \local_multitenancy\form\user_create_form(null, [
    'tenantoptions' => $tenantoptions,
    'defaulttenantid' => $defaulttenantid,
]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $form->get_data()) {
    $result = tenant_user_create::create((int) $data->tenantid, $data);
    if ($result['ok']) {
        redirect($returnurl, $result['message'], null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($returnurl, $result['message'], null, \core\output\notification::NOTIFY_ERROR);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('usercreate_title', 'local_multitenancy'));
echo html_writer::tag('p', get_string('usercreate_intro', 'local_multitenancy'), ['class' => 'mb-3']);
$form->display();
echo $OUTPUT->single_button($returnurl, get_string('back'), 'get');
echo $OUTPUT->footer();
