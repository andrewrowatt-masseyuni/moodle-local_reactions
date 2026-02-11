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
 * Admin settings for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_reactions', get_string('pluginname', 'local_reactions'));

    $settings->add(new admin_setting_configcheckbox(
        'local_reactions/enabled',
        get_string('settings:enabled', 'local_reactions'),
        get_string('settings:enabled_desc', 'local_reactions'),
        1
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_reactions/emojis',
        get_string('settings:emojis', 'local_reactions'),
        get_string('settings:emojis_desc', 'local_reactions'),
        \local_reactions\manager::DEFAULT_EMOJIS
    ));

    $settings->add(new admin_setting_configtext(
        'local_reactions/pollinterval',
        get_string('settings:pollinterval', 'local_reactions'),
        get_string('settings:pollinterval_desc', 'local_reactions'),
        15,
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}
