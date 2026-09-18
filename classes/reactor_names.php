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
 * Builds the "who reacted" text shown in a reaction pill's tooltip.
 *
 * Only activities with the per-activity "Show who reacted" setting turned on produce anything here;
 * every entry point returns an empty array otherwise, so callers can ask unconditionally.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reactor_names {
    /**
     * @var int Default cap on how many names a viewer holding local/reactions:viewallreactornames
     * sees, used when the site setting is unset or invalid.
     */
    public const DEFAULT_LIMIT = 10;

    /**
     * @var int Cap on how many names a viewer *without* local/reactions:viewallreactornames
     * (i.e. a student) sees. Not site-configurable.
     */
    public const STUDENT_LIMIT = 3;

    /**
     * Whether the activity owning this context lists the first names of the users who reacted.
     *
     * Only activities have the per-activity setting, so reactions outside one (blog entries)
     * always stay anonymous.
     *
     * @param \context $context The context reactions are being displayed in.
     * @return bool
     */
    public static function is_enabled(\context $context): bool {
        if (!($context instanceof \context_module)) {
            return false;
        }
        $record = manager::get_module_config($context->instanceid);
        return !empty($record) && !empty($record->enabled) && !empty($record->shownames);
    }

    /**
     * How many other people's names the current user may see on one pill.
     *
     * Viewers holding local/reactions:viewallreactornames (teachers and managers by default) get
     * the site-wide allowance; everyone else sees at most STUDENT_LIMIT. The viewer's own
     * "You" never counts against either cap.
     *
     * @param \context $context The context reactions are being displayed in.
     * @return int Maximum number of other reactors to name.
     */
    public static function get_limit(\context $context): int {
        if (!has_capability('local/reactions:viewallreactornames', $context)) {
            return self::STUDENT_LIMIT;
        }
        $limit = (int) get_config('local_reactions', 'shownameslimit');
        return $limit > 0 ? $limit : self::DEFAULT_LIMIT;
    }

    /**
     * Build the "who reacted" text for each emoji on each of the given items.
     *
     * Returns an empty array when the activity does not have the setting turned on, so callers can
     * always call this and simply find nothing to attach.
     *
     * @param string $component Component name e.g. mod_forum.
     * @param string $itemtype Item type e.g. post.
     * @param array $itemids Item IDs.
     * @param int $viewerid The user viewing the page.
     * @param \context $context The module context the items belong to.
     * @return array Keyed by itemid => ['emoji' => [shortcode => string], 'all' => string].
     */
    public static function for_items(
        string $component,
        string $itemtype,
        array $itemids,
        int $viewerid,
        \context $context
    ): array {
        global $DB;

        if (empty($itemids) || !self::is_enabled($context)) {
            return [];
        }

        [$scopejoin, $scopewhere, $scopeparams] = self::get_item_scope_sql($context, $component);
        if ($scopejoin === null) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
        $params = array_merge($params, $scopeparams, [
            'component' => $component,
            'itemtype' => $itemtype,
        ]);

        $sql = "SELECT r.id, r.itemid AS bucketid, r.emoji, r.userid, r.timecreated
                  FROM {local_reactions} r
                  $scopejoin
                 WHERE r.component = :component
                   AND r.itemtype = :itemtype
                   AND r.itemid $insql
                   AND $scopewhere
              ORDER BY r.timecreated DESC, r.id DESC";

        return self::build($DB->get_records_sql($sql, $params), $viewerid, $context);
    }

    /**
     * Build the "who reacted" text for each emoji aggregated across all posts in each discussion.
     *
     * A user who reacted with the same emoji on several posts of one discussion is named once, at
     * the position of their most recent reaction, so the names read as a list of people rather than
     * of reactions. That means the name list can be shorter than the count shown on the pill.
     *
     * @param string $component Component name e.g. mod_forum.
     * @param string $itemtype Item type e.g. post.
     * @param array $discussionids Forum discussion IDs.
     * @param int $viewerid The user viewing the page.
     * @param \context $context The forum's module context.
     * @return array Keyed by discussionid => ['emoji' => [shortcode => string], 'all' => string].
     */
    public static function for_discussions(
        string $component,
        string $itemtype,
        array $discussionids,
        int $viewerid,
        \context $context
    ): array {
        global $DB;

        if (empty($discussionids) || !self::is_enabled($context)) {
            return [];
        }

        $forumid = self::get_scope_instanceid($context, 'forum');
        if ($forumid === null || $component !== manager::COMPONENT_FORUM) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($discussionids, SQL_PARAMS_NAMED);
        $params = array_merge($params, [
            'component' => $component,
            'itemtype' => $itemtype,
            'scopeinstance' => $forumid,
        ]);

        $sql = "SELECT r.id, fp.discussion AS bucketid, r.emoji, r.userid, r.timecreated
                  FROM {local_reactions} r
                  JOIN {forum_posts} fp ON fp.id = r.itemid
                  JOIN {forum_discussions} fd ON fd.id = fp.discussion
                 WHERE r.component = :component
                   AND r.itemtype = :itemtype
                   AND fp.discussion $insql
                   AND fd.forum = :scopeinstance
              ORDER BY r.timecreated DESC, r.id DESC";

        return self::build($DB->get_records_sql($sql, $params), $viewerid, $context);
    }

    /**
     * Build the "who reacted" text for posts shown in the whole-forum grading panel.
     *
     * Mirrors get_reactions_for_grading(): when the forum only shows peer reactions while grading,
     * self-reactions and reactions by non-students are left out of the names as well as the counts.
     * A grader therefore never sees "You" here, which is the same deliberate omission that method
     * already makes for the grader's own reactions.
     *
     * @param string $component Component name e.g. mod_forum.
     * @param string $itemtype Item type e.g. post.
     * @param array $itemids Forum post IDs.
     * @param int $viewerid The user viewing the grading panel.
     * @param \context $context The forum's module context.
     * @return array Keyed by itemid => ['emoji' => [shortcode => string], 'all' => string].
     */
    public static function for_grading(
        string $component,
        string $itemtype,
        array $itemids,
        int $viewerid,
        \context $context
    ): array {
        global $DB;

        if (empty($itemids) || !self::is_enabled($context)) {
            return [];
        }
        if (!manager::is_only_peer_grading_enabled($context)) {
            return self::for_items($component, $itemtype, $itemids, $viewerid, $context);
        }

        $studentuserids = manager::get_student_userids($context);
        if (empty($studentuserids)) {
            return [];
        }

        $forumid = self::get_scope_instanceid($context, 'forum');
        if ($forumid === null || $component !== manager::COMPONENT_FORUM) {
            return [];
        }

        [$itemsql, $itemparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'itemid');
        [$usersql, $userparams] = $DB->get_in_or_equal($studentuserids, SQL_PARAMS_NAMED, 'student');
        $params = array_merge($itemparams, $userparams, [
            'component' => $component,
            'itemtype' => $itemtype,
            'scopeinstance' => $forumid,
        ]);

        $sql = "SELECT r.id, r.itemid AS bucketid, r.emoji, r.userid, r.timecreated
                  FROM {local_reactions} r
                  JOIN {forum_posts} fp ON fp.id = r.itemid
                  JOIN {forum_discussions} fd ON fd.id = fp.discussion
                 WHERE r.component = :component
                   AND r.itemtype = :itemtype
                   AND r.itemid $itemsql
                   AND r.userid $usersql
                   AND r.userid <> fp.userid
                   AND fd.forum = :scopeinstance
              ORDER BY r.timecreated DESC, r.id DESC";

        return self::build($DB->get_records_sql($sql, $params), $viewerid, $context);
    }

    /**
     * SQL restricting a reaction row set to the items that really live in the given activity.
     *
     * The web services take the context from the caller and never prove that the item IDs beside it
     * belong to that activity. That is harmless for anonymous counts, but names are personal data:
     * without this clause, anyone who can view one activity with "Show who reacted" on could ask who
     * reacted to items anywhere on the site. An unrecognised context/component pairing returns nulls
     * so callers fail closed and report no names at all.
     *
     * @param \context $context The module context the caller supplied.
     * @param string $component Component name e.g. mod_forum.
     * @return array{0: string|null, 1: string|null, 2: array} [$join, $where, $params].
     */
    private static function get_item_scope_sql(\context $context, string $component): array {
        if ($component === manager::COMPONENT_FORUM) {
            $forumid = self::get_scope_instanceid($context, 'forum');
            return $forumid === null ? [null, null, []] : [
                'JOIN {forum_posts} sfp ON sfp.id = r.itemid
                  JOIN {forum_discussions} sfd ON sfd.id = sfp.discussion',
                'sfd.forum = :scopeinstance',
                ['scopeinstance' => $forumid],
            ];
        }
        if ($component === manager::COMPONENT_DATA) {
            $dataid = self::get_scope_instanceid($context, 'data');
            return $dataid === null ? [null, null, []] : [
                'JOIN {data_records} sdr ON sdr.id = r.itemid',
                'sdr.dataid = :scopeinstance',
                ['scopeinstance' => $dataid],
            ];
        }
        return [null, null, []];
    }

    /**
     * The activity instance ID behind a module context, when it is of the expected module type.
     *
     * @param \context $context The context to resolve.
     * @param string $modulename The module the context is expected to belong to, e.g. 'forum'.
     * @return int|null The instance ID, or null if the context is not that kind of activity.
     */
    private static function get_scope_instanceid(\context $context, string $modulename): ?int {
        if (!($context instanceof \context_module)) {
            return null;
        }
        $cm = get_coursemodule_from_id($modulename, $context->instanceid, 0, false, IGNORE_MISSING);
        return $cm ? (int) $cm->instance : null;
    }

    /**
     * Turn reaction rows into the per-bucket, per-emoji name lists shown in pill tooltips.
     *
     * Rows must arrive most-recent-first; the first time a user is seen in a bucket fixes their
     * position, which is what makes the lists read newest-reaction-first without a second sort.
     *
     * @param array $rows Rows with bucketid, emoji, userid and timecreated, newest first.
     * @param int $viewerid The user viewing the page.
     * @param \context $context The module context the reactions belong to.
     * @return array Keyed by bucket ID => ['emoji' => [shortcode => string], 'all' => string].
     */
    private static function build(array $rows, int $viewerid, \context $context): array {
        if (empty($rows)) {
            return [];
        }

        $buckets = [];
        $alluserids = [];
        foreach ($rows as $row) {
            $bucketid = (int) $row->bucketid;
            $userid = (int) $row->userid;
            $emoji = $row->emoji;

            if (!isset($buckets[$bucketid])) {
                $buckets[$bucketid] = ['emoji' => [], 'all' => []];
            }
            if (!isset($buckets[$bucketid]['emoji'][$emoji])) {
                $buckets[$bucketid]['emoji'][$emoji] = [];
            }
            if (!in_array($userid, $buckets[$bucketid]['emoji'][$emoji], true)) {
                $buckets[$bucketid]['emoji'][$emoji][] = $userid;
            }
            if (!in_array($userid, $buckets[$bucketid]['all'], true)) {
                $buckets[$bucketid]['all'][] = $userid;
            }
            $alluserids[$userid] = $userid;
        }

        $labels = self::get_labels($alluserids, $context);
        $limit = self::get_limit($context);

        $result = [];
        foreach ($buckets as $bucketid => $bucket) {
            $peremoji = [];
            foreach ($bucket['emoji'] as $emoji => $userids) {
                $peremoji[$emoji] = self::format_list($userids, $viewerid, $labels, $limit);
            }
            $result[$bucketid] = [
                'emoji' => $peremoji,
                'all' => self::format_list($bucket['all'], $viewerid, $labels, $limit),
            ];
        }
        return $result;
    }

    /**
     * Render one bucket's ordered user IDs as the text shown in a pill tooltip.
     *
     * The viewer is pulled out of the list and named "You" at the front, so the limit always applies
     * to other people. Users whose name could not be resolved (deleted accounts) are never named but
     * still count towards the "and X others" tail, keeping the tail honest about how many people
     * reacted.
     *
     * @param int[] $userids Distinct user IDs, most recent reaction first.
     * @param int $viewerid The user viewing the page.
     * @param array $labels Map of userid to display label.
     * @param int $limit Maximum number of other people to name.
     * @return string The tooltip text, or an empty string when there is nothing to show.
     */
    private static function format_list(array $userids, int $viewerid, array $labels, int $limit): string {
        $others = array_values(array_filter($userids, static function ($userid) use ($viewerid) {
            return $userid !== $viewerid;
        }));

        $parts = [];
        if (count($others) !== count($userids)) {
            $parts[] = get_string('reactornames:you', 'local_reactions');
        }
        $shown = self::take_labelled($others, $labels, $limit);
        $parts = array_merge($parts, $shown);

        if (empty($parts)) {
            return '';
        }

        $list = implode(get_string('reactornames:separator', 'local_reactions'), $parts);
        $remaining = count($others) - count($shown);
        if ($remaining > 0) {
            $list = get_string(
                $remaining === 1 ? 'reactornames:andother' : 'reactornames:andothers',
                'local_reactions',
                (object) ['names' => $list, 'count' => $remaining]
            );
        }
        return $list;
    }

    /**
     * Take up to $limit labels off the front of an ordered list of user IDs.
     *
     * Users with no label (deleted accounts) are passed over without using up one of the slots, so
     * they end up in the caller's "and X others" tail rather than silently vanishing from the total.
     *
     * @param int[] $userids Ordered user IDs to draw from.
     * @param array $labels Map of userid to display label.
     * @param int $limit Maximum number of labels to take.
     * @return string[] The labels taken, in order.
     */
    private static function take_labelled(array $userids, array $labels, int $limit): array {
        $taken = [];
        foreach ($userids as $userid) {
            if (count($taken) >= $limit) {
                break;
            }
            if (isset($labels[$userid])) {
                $taken[] = $labels[$userid];
            }
        }
        return $taken;
    }

    /**
     * Build the display label for each reactor: their first name, with their role appended when
     * they hold a non-student role in the course, e.g. "Jenny (Teacher)".
     *
     * @param array $userids User IDs to label.
     * @param \context $context The module context the reactions belong to.
     * @return array<int,string> userid => label, omitting users with no usable first name.
     */
    private static function get_labels(array $userids, \context $context): array {
        global $DB;

        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (empty($userids)) {
            return [];
        }

        $users = $DB->get_records_list('user', 'id', $userids, '', 'id, firstname, deleted');
        $rolenames = self::get_nonstudent_role_names($userids, $context);

        $labels = [];
        foreach ($userids as $userid) {
            $user = $users[$userid] ?? null;
            if (!$user || !empty($user->deleted)) {
                continue;
            }
            $firstname = trim((string) $user->firstname);
            if ($firstname === '') {
                continue;
            }
            $labels[$userid] = isset($rolenames[$userid])
                ? get_string('reactornames:withrole', 'local_reactions', (object) [
                    'name' => $firstname,
                    'role' => $rolenames[$userid],
                ])
                : $firstname;
        }
        return $labels;
    }

    /**
     * Find the role name to show beside each user, for users who hold a non-student role here.
     *
     * Roles come back ordered most-specific-context first and then by role sort order, so the first
     * non-student role a user holds is the one worth showing. Users holding only student-archetype
     * roles, or none at all, are absent from the result and are shown by first name alone.
     *
     * @param int[] $userids User IDs to look up.
     * @param \context $context The module context reactions are displayed in.
     * @return array<int,string> userid => localised role name.
     */
    private static function get_nonstudent_role_names(array $userids, \context $context): array {
        $roles = role_get_names($context, ROLENAME_ALIAS, false);
        $userroles = get_users_roles($context, $userids, true);

        $rolenames = [];
        foreach ($userroles as $userid => $assignments) {
            foreach ($assignments as $assignment) {
                $role = $roles[$assignment->roleid] ?? null;
                if (!$role || $role->archetype === 'student') {
                    continue;
                }
                $rolenames[(int) $userid] = $role->localname;
                break;
            }
        }
        return $rolenames;
    }
}
