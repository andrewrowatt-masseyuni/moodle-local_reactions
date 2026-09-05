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

namespace local_reactions\output;

/**
 * Moodle App output class for Reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /**
     * Return the JS that registers the app-side filter handler drawing reaction bars on forum posts.
     *
     * Called once per login by tool_mobile_get_content, for every user of the site. It returns
     * nothing user-specific and reads no user data, so it deliberately performs no capability
     * checks; whether a bar actually renders is decided per activity by
     * local_reactions_get_item_settings, which does check.
     *
     * The emoji set is deliberately not passed here. This runs only at login, so anything embedded
     * would go stale until the user logged out and back in again.
     *
     * @param array $args Arguments from the tool_mobile_get_content web service.
     * @return array Templates, JavaScript and other data for the app.
     */
    public static function mobile_init($args): array {
        global $CFG;

        return [
            'templates' => [],
            'javascript' => file_get_contents($CFG->dirroot . '/local/reactions/mobileapp/init.js'),
        ];
    }
}
