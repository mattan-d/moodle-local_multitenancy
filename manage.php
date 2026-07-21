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
 * List tenants (parent / hub site).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB, $PAGE, $OUTPUT, $CFG;

admin_externalpage_setup('local_multitenancy_manage');

require_capability('local/multitenancy:manage', context_system::instance());

// Keep gateway folders + registry in sync automatically (no manual rebuild).
\local_multitenancy\gateway_manager::sync();
$registrydir = \local_multitenancy\registry_writer::ensure_registry_dir();
$registryok = $registrydir !== '' && \local_multitenancy\registry_writer::sync();

$PAGE->set_url(new moodle_url('/local/multitenancy/manage.php'));
$PAGE->set_title(get_string('manage_tenants', 'local_multitenancy'));
$PAGE->set_heading(get_string('manage_tenants', 'local_multitenancy'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage_tenants', 'local_multitenancy'));

if (!$registryok) {
    echo $OUTPUT->notification(get_string('registrydirmissing', 'local_multitenancy'), 'error');
}

$leaveurl = new moodle_url('/local/multitenancy/leave.php');
echo $OUTPUT->notification(
    get_string('manage_recovery_hint', 'local_multitenancy', $leaveurl->out(false)),
    'info'
);

$addurl = new moodle_url('/local/multitenancy/edit.php');
echo $OUTPUT->single_button($addurl, get_string('addtenant', 'local_multitenancy'), 'get');
$allowedlisturl = new moodle_url('/local/multitenancy/allowed_shortcodes.php');
echo $OUTPUT->single_button($allowedlisturl, get_string('manageallowedshortcodes', 'local_multitenancy'), 'get');
$usersurl = new moodle_url('/local/multitenancy/users_manage.php');
echo $OUTPUT->single_button($usersurl, get_string('manageusers', 'local_multitenancy'), 'get');
echo $OUTPUT->single_button($leaveurl, get_string('footerleavetenant', 'local_multitenancy'), 'get');

$tenants = $DB->get_records('local_multitenancy_tenant', null, 'sortorder ASC, id ASC');

if (!$tenants) {
    echo $OUTPUT->notification(get_string('notenants', 'local_multitenancy'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
}

$failed = [];
$pending = [];
foreach ($tenants as $t) {
    $status = (string) ($t->provisionstatus ?? '');
    if ($status === \local_multitenancy\tenant_provisioner::STATUS_FAILED) {
        $failed[] = $t;
    } else if (in_array($status, [
        \local_multitenancy\tenant_provisioner::STATUS_PENDING,
        \local_multitenancy\tenant_provisioner::STATUS_PROCESSING,
    ], true)) {
        $pending[] = $t;
    }
}

foreach ($failed as $t) {
    $msg = get_string('manage_provision_failed', 'local_multitenancy', (object) [
        'name' => format_string($t->name),
        'code' => $t->shortcode,
        'error' => (string) ($t->provisionerror ?? ''),
    ]);
    echo $OUTPUT->notification($msg, 'error');
}
if ($pending) {
    echo $OUTPUT->notification(get_string('manage_provision_pending', 'local_multitenancy', count($pending)), 'warning');
}

$table = new html_table();
$table->head = [
    get_string('shortcode', 'local_multitenancy'),
    get_string('name'),
    get_string('gatewayurl', 'local_multitenancy'),
    get_string('host', 'local_multitenancy'),
    get_string('wwwroot', 'local_multitenancy'),
    get_string('dbname', 'local_multitenancy'),
    get_string('enabled', 'local_multitenancy'),
    get_string('columnprovision', 'local_multitenancy'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable';
$table->data = [];

foreach ($tenants as $t) {
    $edit = new moodle_url('/local/multitenancy/edit.php', ['id' => $t->id]);
    $delete = new moodle_url('/local/multitenancy/delete.php', ['id' => $t->id]);
    $actions = $OUTPUT->action_icon($edit, new pix_icon('t/edit', get_string('edit'))) .
        $OUTPUT->action_icon($delete, new pix_icon('t/delete', get_string('delete')));
    $gateway = new moodle_url('/local/multitenancy/users/' . rawurlencode($t->shortcode) . '/');
    $ready = ((string) ($t->provisionstatus ?? '') === \local_multitenancy\tenant_provisioner::STATUS_COMPLETE)
        && !empty($t->enabled);
    $provisionlabel = \local_multitenancy\tenant_provisioner::status_label($t);
    if (!empty($t->provisionerror)) {
        $provisionlabel .= ' ' . html_writer::tag(
            'span',
            '(' . s($t->provisionerror) . ')',
            ['class' => 'text-danger', 'title' => s($t->provisionerror)]
        );
    }
    if ($ready) {
        $gatewaycell = html_writer::link($gateway, s($gateway->out(false)), ['target' => '_blank']);
        $wwwrootcell = html_writer::link($t->wwwroot, s($t->wwwroot), ['target' => '_blank']);
    } else {
        $gatewaycell = html_writer::span(
            get_string('gateway_not_ready', 'local_multitenancy'),
            'text-muted',
            ['title' => s($gateway->out(false))]
        );
        $wwwrootcell = html_writer::span(s($t->wwwroot), 'text-muted');
    }
    $table->data[] = [
        s($t->shortcode),
        format_string($t->name),
        $gatewaycell,
        s($t->host),
        $wwwrootcell,
        s($t->dbname),
        $t->enabled ? get_string('yes') : get_string('no'),
        $provisionlabel,
        $actions,
    ];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
