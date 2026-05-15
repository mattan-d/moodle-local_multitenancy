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
 * Central user directory across all tenants.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/classes/form/users_manage_filter_form.php');

global $DB, $PAGE, $OUTPUT;

admin_externalpage_setup('local_multitenancy_manage');

require_capability('local/multitenancy:manage', context_system::instance());

/**
 * @param mixed $value
 * @return string
 */
$param_string = static function($value): string {
    if (is_array($value)) {
        $value = reset($value);
    }
    return trim((string) ($value ?? ''));
};

$tenantid = optional_param('tenantid', 0, PARAM_INT);
$search = $param_string(optional_param('search', '', PARAM_RAW));
$includesuspended = optional_param('includesuspended', 0, PARAM_INT) ? 1 : 0;
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 50, PARAM_INT);
$perpage = min(200, max(10, $perpage));

$PAGE->set_title(get_string('manageusers', 'local_multitenancy'));
$PAGE->set_heading(get_string('manageusers', 'local_multitenancy'));

$tenants = $DB->get_records('local_multitenancy_tenant', null, 'sortorder ASC, id ASC');
$tenantoptions = [0 => get_string('alltenants', 'local_multitenancy')];
foreach ($tenants as $tenant) {
    $tenantoptions[$tenant->id] = format_string($tenant->name) . ' (' . s($tenant->shortcode) . ')';
}

$perpageoptions = [
    10 => '10',
    25 => '25',
    50 => '50',
    100 => '100',
    200 => '200',
];

$allusers = \local_multitenancy\tenant_user_directory::search([
    'tenantid' => $tenantid,
    'search' => $search,
    'includesuspended' => $includesuspended,
]);

$total = count($allusers);
$maxpage = $total > 0 ? (int) ceil($total / $perpage) - 1 : 0;
$requestedpage = $page;
$page = min(max(0, $page), $maxpage);
if ($requestedpage !== $page && $total > 0) {
    redirect(new moodle_url('/local/multitenancy/users_manage.php', [
        'tenantid' => $tenantid,
        'search' => $search,
        'includesuspended' => $includesuspended,
        'perpage' => $perpage,
        'page' => $page,
    ]));
}

$pagedusers = array_slice($allusers, $page * $perpage, $perpage);

$pageparams = [
    'tenantid' => $tenantid,
    'search' => $search,
    'includesuspended' => $includesuspended,
    'perpage' => $perpage,
];
if ($page > 0) {
    $pageparams['page'] = $page;
}
$PAGE->set_url(new moodle_url('/local/multitenancy/users_manage.php', $pageparams));
$baseurl = new moodle_url('/local/multitenancy/users_manage.php', [
    'tenantid' => $tenantid,
    'search' => $search,
    'includesuspended' => $includesuspended,
    'perpage' => $perpage,
]);

$filterform = new \local_multitenancy\form\users_manage_filter_form(
    new moodle_url('/local/multitenancy/users_manage.php'),
    [
        'tenantoptions' => $tenantoptions,
        'perpageoptions' => $perpageoptions,
        'clearurl' => new moodle_url('/local/multitenancy/users_manage.php'),
    ],
    'get',
    '',
    ['id' => 'users-manage-filterform']
);
$filterform->set_data((object) [
    'tenantid' => $tenantid,
    'search' => $search,
    'perpage' => $perpage,
    'includesuspended' => $includesuspended,
    'page' => 0,
]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manageusers', 'local_multitenancy'));
echo html_writer::tag('p', get_string('manageusers_desc', 'local_multitenancy'), ['class' => 'mb-3']);
$backurl = new moodle_url('/local/multitenancy/manage.php');
echo $OUTPUT->single_button($backurl, get_string('backtotenants', 'local_multitenancy'), 'get');
$filterform->display();

if ($total === 0) {
    echo $OUTPUT->notification(get_string('nousersfound', 'local_multitenancy'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$fromnum = ($page * $perpage) + 1;
$tonum = min($total, ($page + 1) * $perpage);
echo html_writer::tag('p', get_string('usersshowing', 'local_multitenancy', (object) [
    'from' => $fromnum,
    'to' => $tonum,
    'total' => $total,
]), ['class' => 'mt-3 mb-2']);

if ($total > $perpage) {
    echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);
}

$table = new html_table();
$table->head = [
    get_string('columntenant', 'local_multitenancy'),
    get_string('columnusername', 'local_multitenancy'),
    get_string('fullname', 'moodle'),
    get_string('email', 'moodle'),
    get_string('columnauth', 'local_multitenancy'),
    get_string('columnlastlogin', 'local_multitenancy'),
    get_string('columnsiteadmin', 'local_multitenancy'),
    get_string('suspended', 'moodle'),
];
$table->attributes['class'] = 'generaltable';
$table->data = [];

$cell = static function($value): string {
    if (is_array($value) || is_object($value)) {
        return '';
    }
    return s((string) $value);
};

foreach ($pagedusers as $user) {
    $tenantlabel = format_string((string) $user->tenantname) . ' (' . s((string) $user->tenantshortcode) . ')';
    $gateway = new moodle_url('/local/multitenancy/users/' . rawurlencode((string) $user->tenantshortcode) . '/');
    $fullname = fullname((object) [
        'firstname' => (string) $user->firstname,
        'lastname' => (string) $user->lastname,
    ]);
    $table->data[] = [
        html_writer::link($gateway, $tenantlabel, ['target' => '_blank']),
        $cell($user->username),
        $cell($fullname),
        $cell($user->email),
        $cell($user->auth),
        $user->lastlogin ? userdate($user->lastlogin) : get_string('never', 'moodle'),
        $user->siteadmin ? get_string('yes', 'moodle') : get_string('no', 'moodle'),
        $user->suspended ? get_string('yes', 'moodle') : get_string('no', 'moodle'),
    ];
}

echo html_writer::table($table);

if ($total > $perpage) {
    echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);
}

echo $OUTPUT->footer();
