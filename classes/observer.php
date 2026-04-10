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

namespace local_reactions;

/**
 * Event observers for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Clean up reactions belonging to a forum post that has just been deleted.
     *
     * Keeps {local_reactions} consistent with {forum_posts} so counts from
     * get_reactions() and the peer-grading helpers agree with each other.
     *
     * @param \mod_forum\event\post_deleted $event The post_deleted event.
     */
    public static function post_deleted(\mod_forum\event\post_deleted $event): void {
        global $DB;
        $DB->delete_records('local_reactions', [
            'component' => manager::COMPONENT_FORUM,
            'itemtype'  => manager::ITEMTYPE_POST,
            'itemid'    => $event->objectid,
        ]);
    }
}
