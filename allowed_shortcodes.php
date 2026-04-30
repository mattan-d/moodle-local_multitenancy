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
 * Manage allowed tenant shortcodes list.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/classes/form/allowed_shortcodes_form.php');

admin_externalpage_setup('local_multitenancy_allowed_shortcodes');

require_capability('local/multitenancy:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/multitenancy/allowed_shortcodes.php'));
$PAGE->set_title(get_string('manageallowedshortcodes', 'local_multitenancy'));
$PAGE->set_heading(get_string('manageallowedshortcodes', 'local_multitenancy'));

$form = new \local_multitenancy\form\allowed_shortcodes_form();

$existing = (string) get_config('local_multitenancy', 'allowedshortcodes');
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/multitenancy/manage.php'));
}
if ($data = $form->get_data()) {
    $lines = preg_split('/\R/u', (string) $data->allowedshortcodes) ?: [];
    $unique = [];
    foreach ($lines as $line) {
        $code = trim($line);
        if ($code === '') {
            continue;
        }
        $unique[$code] = true;
    }
    set_config('allowedshortcodes', implode("\n", array_keys($unique)), 'local_multitenancy');
    \core\notification::success(get_string('allowedshortcodessaved', 'local_multitenancy'));
    redirect(new moodle_url('/local/multitenancy/manage.php'));
}

$form->set_data(['allowedshortcodes' => $existing]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manageallowedshortcodes', 'local_multitenancy'));
echo $OUTPUT->notification(get_string('allowedshortcodesdesc', 'local_multitenancy'), 'info');
$form->display();
echo $OUTPUT->footer();

