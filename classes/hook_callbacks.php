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

namespace local_multitenancy;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

use core\hook\output\before_standard_footer_html_generation;
use core\hook\output\before_standard_head_html_generation;
use core\hook\output\before_standard_top_of_body_html_generation;

/**
 * Hook callbacks for local_multitenancy.
 *
 * @package    local_multitenancy
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * @param before_standard_head_html_generation $hook
     * @return void
     */
    public static function before_standard_head_html_generation(before_standard_head_html_generation $hook): void {
        \local_multitenancy_midurim_banner_register_assets();
        \local_multitenancy_login_register_assets();
    }

    /**
     * @param before_standard_top_of_body_html_generation $hook
     * @return void
     */
    public static function before_standard_top_of_body_html_generation(before_standard_top_of_body_html_generation $hook): void {
        $hook->add_html(\local_multitenancy_midurim_banner_html());
        $hook->add_html(\local_multitenancy_login_page_html());
    }

    /**
     * @param before_standard_footer_html_generation $hook
     * @return void
     */
    public static function before_standard_footer_html_generation(before_standard_footer_html_generation $hook): void {
        $hook->add_html(\local_multitenancy_standard_footer_html());
    }
}

