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
use local_reactions\provider_registry;

// phpcs:disable Universal.OOStructures.AlphabeticExtendsImplements

/**
 * Privacy Subsystem implementation for local_reactions.
 *
 * Fans out context discovery, userlists, export, and deletion to the registered content
 * providers (forum, blog) so new content types add their own SQL without touching this file.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
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
        foreach (provider_registry::get_all() as $cp) {
            $parts = $cp->get_privacy_contexts_sql($userid);
            if ($parts === null) {
                continue;
            }
            [$sql, $params] = $parts;
            $contextlist->add_from_sql($sql, $params);
        }
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        foreach (provider_registry::get_all() as $cp) {
            $parts = $cp->get_privacy_users_sql($context);
            if ($parts === null) {
                continue;
            }
            [$sql, $params] = $parts;
            $userlist->add_from_sql('userid', $sql, $params);
        }
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
            $reactionrows = [];
            foreach (provider_registry::get_all() as $cp) {
                $parts = $cp->get_privacy_reaction_ids_sql($context, $user->id, null);
                if ($parts === null) {
                    continue;
                }
                [$sql, $params] = $parts;
                $ids = $DB->get_fieldset_sql($sql, $params);
                if (!empty($ids)) {
                    [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
                    $records = $DB->get_records_select(
                        'local_reactions',
                        "id $insql",
                        $inparams,
                        '',
                        'id, emoji, component, itemtype, itemid, timecreated'
                    );
                    foreach ($records as $r) {
                        $reactionrows[] = (object) [
                            'emoji' => $r->emoji,
                            'component' => $r->component,
                            'itemtype' => $r->itemtype,
                            'itemid' => $r->itemid,
                            'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
                        ];
                    }
                }
            }

            if (!empty($reactionrows)) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_reactions')],
                    (object) ['reactions' => $reactionrows]
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
        self::delete_reactions_in_context($context, null, null);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist a list of contexts approved for deletion.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (empty($contextlist->count())) {
            return;
        }
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_reactions_in_context($context, (int) $user->id, null);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        self::delete_reactions_in_context($context, null, $userids);
    }

    /**
     * Internal helper that deletes rows in {local_reactions} matching any provider's scoped SQL
     * for the given context, optionally filtered by a single user or a set of users.
     *
     * @param \context $context
     * @param int|null $userid
     * @param int[]|null $userids
     */
    private static function delete_reactions_in_context(\context $context, ?int $userid, ?array $userids): void {
        global $DB;
        foreach (provider_registry::get_all() as $cp) {
            $parts = $cp->get_privacy_reaction_ids_sql($context, $userid, $userids);
            if ($parts === null) {
                continue;
            }
            [$sql, $params] = $parts;
            $ids = $DB->get_fieldset_sql($sql, $params);
            if (!empty($ids)) {
                [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('local_reactions', "id $insql", $inparams);
            }
        }
    }
}
