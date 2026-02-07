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

namespace local_reactions\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy Subsystem implementation for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Return the fields which contain personal data.
     *
     * @param collection $collection a reference to the collection to use to store the metadata.
     * @return collection the updated collection of metadata items.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_reactions',
            [
                'userid' => 'privacy:metadata:local_reactions:userid',
                'emoji' => 'privacy:metadata:local_reactions:emoji',
                'component' => 'privacy:metadata:local_reactions:component',
                'itemtype' => 'privacy:metadata:local_reactions:itemtype',
                'itemid' => 'privacy:metadata:local_reactions:itemid',
                'timecreated' => 'privacy:metadata:local_reactions:timecreated',
            ],
            'privacy:metadata:local_reactions'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid the userid.
     * @return contextlist the list of contexts containing user info for the user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Reactions are stored for forum posts - we need to find the forum module contexts.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {forum_discussions} fd ON fd.forum = cm.instance
                  JOIN {forum_posts} fp ON fp.discussion = fd.id
                  JOIN {local_reactions} lr ON lr.component = :component
                                           AND lr.itemtype = :itemtype
                                           AND lr.itemid = fp.id
                 WHERE lr.userid = :userid
                   AND m.name = :modulename";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'component' => 'mod_forum',
            'itemtype' => 'post',
            'userid' => $userid,
            'modulename' => 'forum',
        ];

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $sql = "SELECT lr.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {forum_discussions} fd ON fd.forum = cm.instance
                  JOIN {forum_posts} fp ON fp.discussion = fd.id
                  JOIN {local_reactions} lr ON lr.component = :component
                                           AND lr.itemtype = :itemtype
                                           AND lr.itemid = fp.id
                 WHERE cm.id = :cmid
                   AND m.name = :modulename";

        $params = [
            'cmid' => $context->instanceid,
            'component' => 'mod_forum',
            'itemtype' => 'post',
            'modulename' => 'forum',
        ];

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export personal data for the given approved_contextlist. User and context information is contained within the contextlist.
     *
     * @param approved_contextlist $contextlist a list of contexts approved for export.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            // Get all reactions for this user in this context.
            $sql = "SELECT lr.id, lr.emoji, lr.component, lr.itemtype, lr.itemid, lr.timecreated
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                      JOIN {forum_discussions} fd ON fd.forum = cm.instance
                      JOIN {forum_posts} fp ON fp.discussion = fd.id
                      JOIN {local_reactions} lr ON lr.component = :component
                                               AND lr.itemtype = :itemtype
                                               AND lr.itemid = fp.id
                     WHERE cm.id = :cmid
                       AND m.name = :modulename
                       AND lr.userid = :userid";

            $params = [
                'cmid' => $context->instanceid,
                'component' => 'mod_forum',
                'itemtype' => 'post',
                'modulename' => 'forum',
                'userid' => $user->id,
            ];

            $reactions = $DB->get_records_sql($sql, $params);

            if (!empty($reactions)) {
                $data = [];
                foreach ($reactions as $reaction) {
                    $data[] = (object) [
                        'emoji' => $reaction->emoji,
                        'component' => $reaction->component,
                        'itemtype' => $reaction->itemtype,
                        'itemid' => $reaction->itemid,
                        'timecreated' => \core_privacy\local\request\transform::datetime($reaction->timecreated),
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_reactions')],
                    (object) ['reactions' => $data]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context the context to delete in.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        // Delete all reactions for forum posts in this module.
        $sql = "SELECT lr.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {forum_discussions} fd ON fd.forum = cm.instance
                  JOIN {forum_posts} fp ON fp.discussion = fd.id
                  JOIN {local_reactions} lr ON lr.component = :component
                                           AND lr.itemtype = :itemtype
                                           AND lr.itemid = fp.id
                 WHERE cm.id = :cmid
                   AND m.name = :modulename";

        $params = [
            'cmid' => $context->instanceid,
            'component' => 'mod_forum',
            'itemtype' => 'post',
            'modulename' => 'forum',
        ];

        $reactionids = $DB->get_fieldset_sql($sql, $params);

        if (!empty($reactionids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($reactionids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_reactions', "id $insql", $inparams);
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist a list of contexts approved for deletion.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            // Delete all reactions for this user in this context.
            $sql = "SELECT lr.id
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                      JOIN {forum_discussions} fd ON fd.forum = cm.instance
                      JOIN {forum_posts} fp ON fp.discussion = fd.id
                      JOIN {local_reactions} lr ON lr.component = :component
                                               AND lr.itemtype = :itemtype
                                               AND lr.itemid = fp.id
                     WHERE cm.id = :cmid
                       AND m.name = :modulename
                       AND lr.userid = :userid";

            $params = [
                'cmid' => $context->instanceid,
                'component' => 'mod_forum',
                'itemtype' => 'post',
                'modulename' => 'forum',
                'userid' => $user->id,
            ];

            $reactionids = $DB->get_fieldset_sql($sql, $params);

            if (!empty($reactionids)) {
                [$insql, $inparams] = $DB->get_in_or_equal($reactionids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('local_reactions', "id $insql", $inparams);
            }
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // Delete all reactions for these users in this context.
        $sql = "SELECT lr.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {forum_discussions} fd ON fd.forum = cm.instance
                  JOIN {forum_posts} fp ON fp.discussion = fd.id
                  JOIN {local_reactions} lr ON lr.component = :component
                                           AND lr.itemtype = :itemtype
                                           AND lr.itemid = fp.id
                 WHERE cm.id = :cmid
                   AND m.name = :modulename
                   AND lr.userid $usersql";

        $params = array_merge([
            'cmid' => $context->instanceid,
            'component' => 'mod_forum',
            'itemtype' => 'post',
            'modulename' => 'forum',
        ], $userparams);

        $reactionids = $DB->get_fieldset_sql($sql, $params);

        if (!empty($reactionids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($reactionids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_reactions', "id $insql", $inparams);
        }
    }
}
