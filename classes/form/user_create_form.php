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

global $CFG;

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Form to create a user in a selected tenant.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_create_form extends \moodleform {

    /**
     * @return void
     */
    protected function definition() {
        global $CFG;

        $mform = $this->_form;
        $tenantoptions = $this->_customdata['tenantoptions'];
        $defaulttenantid = (int) ($this->_customdata['defaulttenantid'] ?? 0);

        $mform->addElement(
            'select',
            'tenantid',
            get_string('usercreate_tenant', 'local_multitenancy'),
            $tenantoptions
        );
        $mform->addRule('tenantid', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('tenantid', 'usercreate_tenant', 'local_multitenancy');

        $mform->addElement('header', 'userdetails', get_string('usertransfer_user_details', 'local_multitenancy'));

        $mform->addElement('text', 'username', get_string('username'));
        $mform->addRule('username', get_string('required'), 'required', null, 'client');
        $mform->setType('username', PARAM_USERNAME);

        $mform->addElement('text', 'firstname', get_string('firstname'));
        $mform->addRule('firstname', get_string('required'), 'required', null, 'client');
        $mform->setType('firstname', PARAM_NOTAGS);

        $mform->addElement('text', 'lastname', get_string('lastname'));
        $mform->addRule('lastname', get_string('required'), 'required', null, 'client');
        $mform->setType('lastname', PARAM_NOTAGS);

        $mform->addElement('text', 'email', get_string('email'));
        $mform->addRule('email', get_string('required'), 'required', null, 'client');
        $mform->setType('email', PARAM_EMAIL);

        $mform->addElement('text', 'idnumber', get_string('idnumber'));
        $mform->setType('idnumber', PARAM_RAW);

        $auths = get_enabled_auth_plugins();
        if (!in_array('manual', $auths, true)) {
            $auths[] = 'manual';
        }
        $mform->addElement('select', 'auth', get_string('authentication'), array_combine($auths, $auths));
        $mform->setType('auth', PARAM_AUTH);
        $mform->setDefault('auth', 'manual');

        $mform->addElement(
            'passwordunmask',
            'newpassword',
            get_string('newpassword'),
            ['size' => 20]
        );
        $mform->addRule('newpassword', get_string('required'), 'required', null, 'client');
        $mform->setType('newpassword', PARAM_RAW);

        $mform->addElement('advcheckbox', 'suspended', get_string('suspended'));
        $mform->setType('suspended', PARAM_INT);

        $mform->addElement('text', 'phone1', get_string('phone1'));
        $mform->setType('phone1', PARAM_NOTAGS);

        $mform->addElement('text', 'phone2', get_string('phone2'));
        $mform->setType('phone2', PARAM_NOTAGS);

        $mform->addElement('text', 'institution', get_string('institution'));
        $mform->setType('institution', PARAM_NOTAGS);

        $mform->addElement('text', 'department', get_string('department'));
        $mform->setType('department', PARAM_NOTAGS);

        $mform->addElement('text', 'address', get_string('address'));
        $mform->setType('address', PARAM_NOTAGS);

        $mform->addElement('text', 'city', get_string('city'));
        $mform->setType('city', PARAM_NOTAGS);

        $countries = get_string_manager()->get_list_of_countries();
        $mform->addElement('select', 'country', get_string('country'), $countries);
        $mform->setType('country', PARAM_ALPHAEXT);

        $languages = get_string_manager()->get_list_of_translations();
        $mform->addElement('select', 'lang', get_string('language'), $languages);
        $mform->setType('lang', PARAM_LANG);
        $mform->setDefault('lang', $CFG->lang);

        $timezones = \core_date::get_list_of_timezones($CFG->timezone, true);
        $mform->addElement('select', 'timezone', get_string('timezone'), $timezones);
        $mform->setType('timezone', PARAM_TIMEZONE);
        $mform->setDefault('timezone', $CFG->timezone);

        $mform->addElement('text', 'alternatename', get_string('alternatename'));
        $mform->setType('alternatename', PARAM_NOTAGS);

        $mform->addElement('text', 'middlename', get_string('middlename'));
        $mform->setType('middlename', PARAM_NOTAGS);

        $mform->addElement('text', 'firstnamephonetic', get_string('firstnamephonetic'));
        $mform->setType('firstnamephonetic', PARAM_NOTAGS);

        $mform->addElement('text', 'lastnamephonetic', get_string('lastnamephonetic'));
        $mform->setType('lastnamephonetic', PARAM_NOTAGS);

        $mform->addElement('textarea', 'description', get_string('description'), 'rows="4" cols="50"');
        $mform->setType('description', PARAM_RAW);

        $this->add_action_buttons(true, get_string('save', 'moodle'));

        if ($defaulttenantid > 0 && isset($tenantoptions[$defaulttenantid])) {
            $mform->setDefault('tenantid', $defaulttenantid);
        }
    }

    /**
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (!validate_email($data['email'])) {
            $errors['email'] = get_string('invalidemail');
        }

        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            $errors['username'] = get_string('required');
        } else if ($username !== \core_text::strtolower($username)) {
            $errors['username'] = get_string('usernamelowercase');
        } else if ($username !== \core_user::clean_field($username, 'username')) {
            $errors['username'] = get_string('invalidusername');
        }

        if (empty($data['newpassword'])) {
            $errors['newpassword'] = get_string('required');
        } else {
            $errmsg = '';
            $dummy = (object) [
                'username' => $username,
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'email' => $data['email'],
            ];
            if (!check_password_policy($data['newpassword'], $errmsg, $dummy)) {
                $errors['newpassword'] = $errmsg;
            }
        }

        return $errors;
    }
}
