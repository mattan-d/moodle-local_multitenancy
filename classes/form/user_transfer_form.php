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
 * Form to move a user to another tenant (with optional field edits).
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_transfer_form extends \moodleform {

    /**
     * @return void
     */
    protected function definition() {
        global $CFG;

        $mform = $this->_form;
        $user = $this->_customdata['user'];
        $sourcetenant = $this->_customdata['sourcetenant'];
        $targetoptions = $this->_customdata['targetoptions'];

        $mform->addElement(
            'static',
            'sourceinfo',
            get_string('usertransfer_source_tenant', 'local_multitenancy'),
            format_string($sourcetenant->name) . ' (' . s($sourcetenant->shortcode) . ')'
        );

        $mform->addElement(
            'select',
            'targettenantid',
            get_string('usertransfer_target_tenant', 'local_multitenancy'),
            $targetoptions
        );
        $mform->addRule('targettenantid', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('targettenantid', 'usertransfer_target_tenant', 'local_multitenancy');

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
        $currentauth = (string) ($user->auth ?? 'manual');
        if ($currentauth !== '' && !in_array($currentauth, $auths, true)) {
            $auths[] = $currentauth;
        }
        $mform->addElement('select', 'auth', get_string('authentication'), array_combine($auths, $auths));
        $mform->setType('auth', PARAM_AUTH);

        $mform->addElement(
            'passwordunmask',
            'newpassword',
            get_string('newpassword'),
            ['size' => 20]
        );
        $mform->addHelpButton('newpassword', 'usertransfer_newpassword', 'local_multitenancy');
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

        $timezones = \core_date::get_list_of_timezones($user->timezone ?? $CFG->timezone, true);
        $mform->addElement('select', 'timezone', get_string('timezone'), $timezones);
        $mform->setType('timezone', PARAM_TIMEZONE);

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

        $mform->addElement('hidden', 'sourcetenantid', (int) $sourcetenant->id);
        $mform->setType('sourcetenantid', PARAM_INT);
        $mform->addElement('hidden', 'userid', (int) $user->id);
        $mform->setType('userid', PARAM_INT);

        $this->add_action_buttons(true, get_string('save', 'moodle'));

        $this->set_data(self::defaults_from_user($user));
    }

    /**
     * Build form defaults from a tenant user record.
     *
     * @param \stdClass $user
     * @return \stdClass
     */
    public static function defaults_from_user(\stdClass $user): \stdClass {
        $data = new \stdClass();
        $data->username = (string) ($user->username ?? '');
        $data->firstname = (string) ($user->firstname ?? '');
        $data->lastname = (string) ($user->lastname ?? '');
        $data->email = (string) ($user->email ?? '');
        $data->idnumber = (string) ($user->idnumber ?? '');
        $data->auth = (string) ($user->auth ?? 'manual');
        $data->suspended = !empty($user->suspended) ? 1 : 0;
        $data->phone1 = (string) ($user->phone1 ?? '');
        $data->phone2 = (string) ($user->phone2 ?? '');
        $data->institution = (string) ($user->institution ?? '');
        $data->department = (string) ($user->department ?? '');
        $data->address = (string) ($user->address ?? '');
        $data->city = (string) ($user->city ?? '');
        $data->country = (string) ($user->country ?? '');
        $data->lang = (string) ($user->lang ?? '');
        $data->timezone = (string) ($user->timezone ?? $GLOBALS['CFG']->timezone);
        $data->alternatename = (string) ($user->alternatename ?? '');
        $data->middlename = (string) ($user->middlename ?? '');
        $data->firstnamephonetic = (string) ($user->firstnamephonetic ?? '');
        $data->lastnamephonetic = (string) ($user->lastnamephonetic ?? '');
        $data->description = (string) ($user->description ?? '');
        $data->newpassword = '';
        return $data;
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

        if (!empty($data['newpassword'])) {
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
