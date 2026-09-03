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
     * Supports four ways of identifying the item being reacted to:
     *   - explicit 'itemid' (plus matching 'component' / 'itemtype')
     *   - 'post' (forum post subject) — resolves to {forum_posts} and defaults
     *     component/itemtype to mod_forum/post.
     *   - 'blogentry' (blog entry subject) — resolves to {post} with module='blog'
     *     and defaults component/itemtype to core_blog/entry.
     *   - 'dataentry' (content of any field on the entry) — resolves to {data_records}
     *     and defaults component/itemtype to mod_data/record.
     *
     * @param array $data Must contain userid, emoji, and one of the above identifiers.
     * @return stdClass The created record.
     */
    public function create_reaction(array $data): stdClass {
        global $DB;

        if (
            !isset($data['itemid']) && !isset($data['post']) && !isset($data['blogentry'])
                && !isset($data['dataentry'])
        ) {
            throw new coding_exception(
                'create_reaction requires one of: itemid, post (forum post subject), '
                . 'blogentry (blog entry subject), dataentry (database entry field content)'
            );
        }

        if (isset($data['dataentry'])) {
            // Database activity entry lookup — matched on the content of one of its fields,
            // which test scenarios keep unique. data_content.content is a TEXT column, so the
            // comparison has to go through sql_compare_text().
            $sql = 'SELECT dc.recordid
                      FROM {data_content} dc
                     WHERE ' . $DB->sql_compare_text('dc.content') . ' = ' . $DB->sql_compare_text(':content') . '
                  ORDER BY dc.recordid ASC';
            $recordids = $DB->get_fieldset_sql($sql, ['content' => $data['dataentry']]);
            if (empty($recordids)) {
                throw new coding_exception('No database activity entry found with content: ' . $data['dataentry']);
            }
            $data['itemid'] = reset($recordids);
            $data['component'] = $data['component'] ?? 'mod_data';
            $data['itemtype'] = $data['itemtype'] ?? 'record';
            unset($data['dataentry']);
        } else if (isset($data['post'])) {
            // Forum post lookup — subjects are unique in test scenarios.
            $data['itemid'] = $DB->get_field('forum_posts', 'id', ['subject' => $data['post']], MUST_EXIST);
            $data['component'] = $data['component'] ?? 'mod_forum';
            $data['itemtype'] = $data['itemtype'] ?? 'post';
            unset($data['post']);
        } else if (isset($data['blogentry'])) {
            // Blog entry lookup — {post} rows with module='blog'.
            $data['itemid'] = $DB->get_field(
                'post',
                'id',
                ['subject' => $data['blogentry'], 'module' => 'blog'],
                MUST_EXIST
            );
            $data['component'] = $data['component'] ?? 'core_blog';
            $data['itemtype'] = $data['itemtype'] ?? 'entry';
            unset($data['blogentry']);
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
        if (isset($data['forum']) && isset($data['course'])) {
            $data['cmid'] = $this->resolve_cmid('forum', $data['forum'], $data['course']);
        }
        return $this->save_enabled_record($data);
    }

    /**
     * Enable reactions for a database activity.
     *
     * @param array $data Must contain data (activity name) and course (shortname).
     * @return stdClass The created record.
     */
    public function create_enabled_database(array $data): stdClass {
        if (isset($data['data']) && isset($data['course'])) {
            $data['cmid'] = $this->resolve_cmid('data', $data['data'], $data['course']);
        }
        return $this->save_enabled_record($data);
    }

    /**
     * Resolve a course module ID from a module type, activity name and course shortname.
     *
     * @param string $modulename The module name, e.g. 'forum' or 'data'.
     * @param string $activityname The activity's name.
     * @param string $courseshortname The course shortname.
     * @return int The course module ID.
     */
    protected function resolve_cmid(string $modulename, string $activityname, string $courseshortname): int {
        global $DB;

        $courseid = $DB->get_field('course', 'id', ['shortname' => $courseshortname], MUST_EXIST);
        $instanceid = $DB->get_field($modulename, 'id', [
            'name' => $activityname,
            'course' => $courseid,
        ], MUST_EXIST);
        $moduleid = $DB->get_field('modules', 'id', ['name' => $modulename], MUST_EXIST);
        return (int) $DB->get_field('course_modules', 'id', [
            'instance' => $instanceid,
            'module' => $moduleid,
            'course' => $courseid,
        ], MUST_EXIST);
    }

    /**
     * Insert or update the {local_reactions_enabled} row for a course module.
     *
     * @param array $data Must contain cmid; other columns fall back to their defaults.
     * @return stdClass The created or updated record.
     */
    protected function save_enabled_record(array $data): stdClass {
        global $DB;

        $cmid = $data['cmid'];

        $record = new stdClass();
        $record->cmid = $cmid;
        $record->enabled = $data['enabled'] ?? 1;
        $record->compactview_list = $data['compactview_list'] ?? 0;
        $record->compactview_discuss = $data['compactview_discuss'] ?? 0;
        $record->allowmultiplereactions = isset($data['allowmultiplereactions'])
            ? (int) $data['allowmultiplereactions'] : 1;
        $record->onlypeerreactionsgrading = isset($data['onlypeerreactionsgrading'])
            ? (int) $data['onlypeerreactionsgrading'] : 1;

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
