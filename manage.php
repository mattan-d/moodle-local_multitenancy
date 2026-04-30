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

admin_externalpage_setup('local_multitenancy_manage');

require_capability('local/multitenancy:manage', context_system::instance());

if (optional_param('rebuild', 0, PARAM_INT) && confirm_sesskey()) {
    \local_multitenancy\gateway_manager::sync();
    if (\local_multitenancy\registry_writer::sync()) {
        \core\notification::success(get_string('registryupdated', 'local_multitenancy'));
    } else {
        \core\notification::warning(get_string('registrynotwritten', 'local_multitenancy'));
    }
    redirect(new moodle_url('/local/multitenancy/manage.php'));
}

$PAGE->set_url(new moodle_url('/local/multitenancy/manage.php'));
$PAGE->set_title(get_string('manage_tenants', 'local_multitenancy'));
$PAGE->set_heading(get_string('manage_tenants', 'local_multitenancy'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage_tenants', 'local_multitenancy'));

$registrydir = get_config('local_multitenancy', 'registrydir');
if (empty($registrydir)) {
    echo $OUTPUT->notification(get_string('registrydirmissing', 'local_multitenancy'), 'warning');
} else {
    echo $OUTPUT->notification(get_string('registrydirset', 'local_multitenancy', $registrydir), 'info');
}

$addurl = new moodle_url('/local/multitenancy/edit.php');
echo $OUTPUT->single_button($addurl, get_string('addtenant', 'local_multitenancy'), 'get');
$allowedlisturl = new moodle_url('/local/multitenancy/allowed_shortcodes.php');
echo $OUTPUT->single_button($allowedlisturl, get_string('manageallowedshortcodes', 'local_multitenancy'), 'get');

if (!empty($registrydir)) {
    $rebuildurl = new moodle_url('/local/multitenancy/manage.php', ['rebuild' => 1]);
    echo $OUTPUT->single_button($rebuildurl, get_string('rebuildregistry', 'local_multitenancy'), 'post');
}

$tenants = $DB->get_records('local_multitenancy_tenant', null, 'sortorder ASC, id ASC');

if (!$tenants) {
    echo $OUTPUT->notification(get_string('notenants', 'local_multitenancy'), 'notifymessage');
    echo $OUTPUT->footer();
    exit;
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
    $table->data[] = [
        s($t->shortcode),
        format_string($t->name),
        html_writer::link($gateway, s($gateway->out(false)), ['target' => '_blank']),
        s($t->host),
        html_writer::link($t->wwwroot, s($t->wwwroot), ['target' => '_blank']),
        s($t->dbname),
        $t->enabled ? get_string('yes') : get_string('no'),
        $actions,
    ];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
