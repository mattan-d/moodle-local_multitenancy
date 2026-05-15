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

namespace local_multitenancy\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * GET filter form for the central user directory.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class users_manage_filter_form extends \moodleform {

    public function definition() {
        $mform = $this->_form;
        $tenantoptions = $this->_customdata['tenantoptions'] ?? [];
        $perpageoptions = $this->_customdata['perpageoptions'] ?? [];
        $clearurl = $this->_customdata['clearurl'] ?? new \moodle_url('/local/multitenancy/users_manage.php');

        $mform->addElement('header', 'filtersheading', get_string('filtersearch', 'local_multitenancy'));

        $mform->addElement('hidden', 'page', 0);
        $mform->setType('page', PARAM_INT);
        $mform->setConstant('page', 0);

        $mform->addElement('select', 'tenantid', get_string('filtertenant', 'local_multitenancy'), $tenantoptions);
        $mform->setType('tenantid', PARAM_INT);

        $mform->addElement(
            'text',
            'search',
            get_string('filtersearch', 'local_multitenancy'),
            [
                'size' => 50,
                'placeholder' => get_string('filtersearchplaceholder', 'local_multitenancy'),
            ]
        );
        $mform->setType('search', PARAM_RAW_TRIMMED);

        $mform->addElement('select', 'perpage', get_string('perpage', 'moodle'), $perpageoptions);
        $mform->setType('perpage', PARAM_INT);

        $mform->addElement(
            'advcheckbox',
            'includesuspended',
            '',
            get_string('filterincludesuspended', 'local_multitenancy')
        );
        $mform->setType('includesuspended', PARAM_INT);

        $buttonarray = [
            $mform->createElement('submit', 'submitbutton', get_string('search', 'moodle')),
            $mform->createElement(
                'static',
                'clearfilterslink',
                '',
                \html_writer::link($clearurl, get_string('clearfilters', 'local_multitenancy'))
            ),
        ];
        $mform->addGroup($buttonarray, 'buttonar', '', ' ', false);
        $mform->closeHeaderBefore('buttonar');

        $mform->disable_form_change_checker();
    }
}
