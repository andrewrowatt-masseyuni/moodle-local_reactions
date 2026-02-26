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
 * Data generator for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Data generator for local_reactions plugin.
 */
class local_reactions_generator extends component_generator_base {
    /**
     * Create a reaction record.
     *
     * @param array $data Must contain userid, emoji, and either itemid or post (subject).
     * @return stdClass The created record.
     */
    public function create_reaction(array $data): stdClass {
        global $DB;

        // Look up post by subject if 'post' field is given.
        if (isset($data['post'])) {
            $data['itemid'] = $DB->get_field('forum_posts', 'id', ['subject' => $data['post']], MUST_EXIST);
            unset($data['post']);
        }

        $record = new stdClass();
        $record->component = $data['component'] ?? 'mod_forum';
        $record->itemtype = $data['itemtype'] ?? 'post';
        $record->itemid = $data['itemid'];
        $record->userid = $data['userid'];
        $record->emoji = $data['emoji'];
        $record->timecreated = $data['timecreated'] ?? time();

        $record->id = $DB->insert_record('local_reactions', $record);
        return $record;
    }

    /**
     * Enable reactions for a forum.
     *
     * @param array $data Must contain forum (name) and course (shortname).
     * @return stdClass The created record.
     */
    public function create_enabled_forum(array $data): stdClass {
        global $DB;

        // Look up forum cmid from forum name + course shortname.
        if (isset($data['forum']) && isset($data['course'])) {
            $courseid = $DB->get_field('course', 'id', ['shortname' => $data['course']], MUST_EXIST);
            $forumid = $DB->get_field('forum', 'id', [
                'name' => $data['forum'],
                'course' => $courseid,
            ], MUST_EXIST);
            $moduleid = $DB->get_field('modules', 'id', ['name' => 'forum'], MUST_EXIST);
            $cmid = $DB->get_field('course_modules', 'id', [
                'instance' => $forumid,
                'module' => $moduleid,
                'course' => $courseid,
            ], MUST_EXIST);
        } else {
            $cmid = $data['cmid'];
        }

        $record = new stdClass();
        $record->cmid = $cmid;
        $record->enabled = $data['enabled'] ?? 1;
        $record->compactview_list = $data['compactview_list'] ?? 0;
        $record->compactview_discuss = $data['compactview_discuss'] ?? 0;
        $record->allowmultiplereactions = isset($data['allowmultiplereactions'])
            ? (int) $data['allowmultiplereactions'] : 1;

        $existing = $DB->get_record('local_reactions_enabled', ['cmid' => $cmid]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_reactions_enabled', $record);
        } else {
            $record->id = $DB->insert_record('local_reactions_enabled', $record);
        }

        return $record;
    }
}
