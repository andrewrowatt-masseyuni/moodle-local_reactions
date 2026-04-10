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
 * Manager class for handling emoji reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var string Default emoji set as comma-separated shortcode:unicode pairs. */
    const DEFAULT_EMOJIS = 'thumbsup:👍,heart:❤️,laugh:😂,think:🤔,celebrate:🎉,surprise:😮,thanks:🙏';

    /**
     * Get the configured emoji set.
     *
     * @return array Associative array of shortcode => unicode emoji.
     */
    public static function get_emoji_set(): array {
        $config = get_config('local_reactions', 'emojis');
        if (empty($config)) {
            $config = self::DEFAULT_EMOJIS;
        }

        $emojis = [];
        $pairs = explode(',', $config);
        foreach ($pairs as $pair) {
            $parts = explode(':', trim($pair), 2);
            if (count($parts) === 2) {
                $emojis[trim($parts[0])] = trim($parts[1]);
            }
        }
        return $emojis;
    }

    /**
     * Toggle a reaction. If the user already has this emoji on the item, remove it.
     * Otherwise add it. When $allowmultiple is false, any existing reactions by this
     * user on the same item are removed before adding the new one (single-reaction mode).
     * When $allowmultiple is true (the default), users can have multiple different emoji
     * reactions on the same item, preserving the existing multi-reaction behavior.
     *
     * @param string $component Component name e.g. mod_forum.
     * @param string $itemtype Item type e.g. post.
     * @param int $itemid Item ID.
     * @param int $userid User ID.
     * @param string $emoji Emoji shortcode.
     * @param bool $allowmultiple When false, enforce single-reaction-per-post mode.
     * @return array ['action' => 'added'|'removed', 'emoji' => string]
     */
    public static function toggle_reaction(
        string $component,
        string $itemtype,
        int $itemid,
        int $userid,
        string $emoji,
        bool $allowmultiple = true
    ): array {
        global $DB;

        // Validate emoji is in the configured set.
        $emojiset = self::get_emoji_set();
        if (!isset($emojiset[$emoji])) {
            throw new \invalid_parameter_exception('Invalid emoji: ' . $emoji);
        }

        $existing = $DB->get_record('local_reactions', [
            'component' => $component,
            'itemtype' => $itemtype,
            'itemid' => $itemid,
            'userid' => $userid,
            'emoji' => $emoji,
        ]);

        if ($existing) {
            // Already reacted with this emoji - remove it.
            $DB->delete_records('local_reactions', ['id' => $existing->id]);
            return ['action' => 'removed', 'emoji' => $emoji];
        } else {
            // In single-reaction mode, wrap the delete-then-insert atomically so that
            // concurrent requests from the same user cannot both slip through and add
            // multiple reactions in a mode that is meant to allow only one.
            $transaction = null;
            if (!$allowmultiple) {
                $transaction = $DB->start_delegated_transaction();
                $DB->delete_records('local_reactions', [
                    'component' => $component,
                    'itemtype'  => $itemtype,
                    'itemid'    => $itemid,
                    'userid'    => $userid,
                ]);
            }
            // Add the reaction.
            $record = new \stdClass();
            $record->component = $component;
            $record->itemtype = $itemtype;
            $record->itemid = $itemid;
            $record->userid = $userid;
            $record->emoji = $emoji;
            $record->timecreated = time();
            $DB->insert_record('local_reactions', $record);
            if ($transaction) {
                $DB->commit_delegated_transaction($transaction);
            }
            return ['action' => 'added', 'emoji' => $emoji];
        }
    }

    /**
     * Get reaction counts and the current user's reactions for multiple items.
     *
     * @param string $component Component name.
     * @param string $itemtype Item type.
     * @param array $itemids Array of item IDs.
     * @param int $userid Current user ID.
     * @return array Keyed by itemid, each containing 'counts' and 'userreactions'.
     */
    public static function get_reactions(
        string $component,
        string $itemtype,
        array $itemids,
        int $userid
    ): array {
        global $DB;

        if (empty($itemids)) {
            return [];
        }

        $result = [];
        foreach ($itemids as $itemid) {
            $result[$itemid] = [
                'counts' => [],
                'userreactions' => [],
            ];
        }

        // Get counts per emoji per item.
        [$insql, $params] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
        $params['component'] = $component;
        $params['itemtype'] = $itemtype;

        $sql = "SELECT CONCAT(itemid, '_', emoji) AS uid, itemid, emoji, COUNT(*) as total
                  FROM {local_reactions}
                 WHERE component = :component
                   AND itemtype = :itemtype
                   AND itemid $insql
              GROUP BY itemid, emoji
              ORDER BY itemid, total DESC";

        $counts = $DB->get_records_sql($sql, $params);
        foreach ($counts as $row) {
            $result[$row->itemid]['counts'][$row->emoji] = (int) $row->total;
        }

        // Get current user's reactions.
        $params['userid'] = $userid;
        $sql = "SELECT id, itemid, emoji
                  FROM {local_reactions}
                 WHERE component = :component
                   AND itemtype = :itemtype
                   AND itemid $insql
                   AND userid = :userid";

        $userreactions = $DB->get_records_sql($sql, $params);
        foreach ($userreactions as $row) {
            $result[$row->itemid]['userreactions'][] = $row->emoji;
        }

        return $result;
    }

    /**
     * Get reaction counts for the given forum posts as displayed in the grading panel.
     *
     * When the per-forum "Only show peer reactions when grading" setting is enabled,
     * reactions made by the post author themselves and reactions made by users who do
     * not hold a student-archetype role in the course are excluded from the counts and
     * from the current user's reactions list.
     *
     * @param string $component Component name e.g. mod_forum.
     * @param string $itemtype Item type e.g. post.
     * @param array $itemids Array of forum post IDs.
     * @param int $userid Current user ID.
     * @param \context $context The module context for the forum being graded.
     * @return array Keyed by itemid, each containing 'counts' and 'userreactions'.
     */
    public static function get_reactions_for_grading(
        string $component,
        string $itemtype,
        array $itemids,
        int $userid,
        \context $context
    ): array {
        if (empty($itemids)) {
            return [];
        }

        if (!self::is_only_peer_grading_enabled($context)) {
            // No filtering: fall back to the standard per-post lookup.
            return self::get_reactions($component, $itemtype, $itemids, $userid);
        }

        // Build the set of student userids in this forum's course.
        $studentuserids = self::get_student_userids($context);

        $result = [];
        foreach ($itemids as $itemid) {
            $result[$itemid] = [
                'counts' => [],
                'userreactions' => [],
            ];
        }

        // Without any students enrolled, all peer-filtered counts are zero.
        if (empty($studentuserids)) {
            return $result;
        }

        self::populate_peer_counts($result, $component, $itemtype, $itemids, $studentuserids);
        self::populate_peer_user_reactions($result, $component, $itemtype, $itemids, $studentuserids, $userid);

        return $result;
    }

    /**
     * Determine whether the per-forum "only show peer reactions when grading" option
     * is enabled for the forum identified by the given module context.
     *
     * @param \context $context Module context for the forum.
     * @return bool True if peer-only filtering should be applied (defaults to true).
     */
    private static function is_only_peer_grading_enabled(\context $context): bool {
        global $DB;
        if (!($context instanceof \context_module)) {
            return true;
        }
        $record = $DB->get_record('local_reactions_enabled', ['cmid' => $context->instanceid]);
        if (!$record || !isset($record->onlypeerreactionsgrading)) {
            return true;
        }
        return !empty($record->onlypeerreactionsgrading);
    }

    /**
     * Fill in the per-post emoji counts on $result, restricted to reactions by students
     * and excluding self-reactions (reactor is post author).
     *
     * @param array $result Result array keyed by itemid (modified in-place).
     * @param string $component Component name.
     * @param string $itemtype Item type.
     * @param array $itemids Forum post IDs.
     * @param int[] $studentuserids List of student user IDs in the course.
     */
    private static function populate_peer_counts(
        array &$result,
        string $component,
        string $itemtype,
        array $itemids,
        array $studentuserids
    ): void {
        global $DB;

        [$itemsql, $itemparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'itemid');
        [$usersql, $userparams] = $DB->get_in_or_equal($studentuserids, SQL_PARAMS_NAMED, 'student');

        $params = array_merge($itemparams, $userparams, [
            'component' => $component,
            'itemtype' => $itemtype,
        ]);

        $sql = "SELECT CONCAT(r.itemid, '_', r.emoji) AS uid,
                       r.itemid,
                       r.emoji,
                       COUNT(*) AS total
                  FROM {local_reactions} r
                  JOIN {forum_posts} fp ON fp.id = r.itemid
                 WHERE r.component = :component
                   AND r.itemtype = :itemtype
                   AND r.itemid $itemsql
                   AND r.userid $usersql
                   AND r.userid <> fp.userid
              GROUP BY r.itemid, r.emoji
              ORDER BY r.itemid, total DESC";

        $counts = $DB->get_records_sql($sql, $params);
        foreach ($counts as $row) {
            $result[$row->itemid]['counts'][$row->emoji] = (int) $row->total;
        }
    }

    /**
     * Fill in the current user's own reactions on $result, restricted to reactions by
     * students and excluding self-reactions (current user is post author).
     *
     * @param array $result Result array keyed by itemid (modified in-place).
     * @param string $component Component name.
     * @param string $itemtype Item type.
     * @param array $itemids Forum post IDs.
     * @param int[] $studentuserids List of student user IDs in the course.
     * @param int $userid Current user ID.
     */
    private static function populate_peer_user_reactions(
        array &$result,
        string $component,
        string $itemtype,
        array $itemids,
        array $studentuserids,
        int $userid
    ): void {
        global $DB;

        // Only students contribute reactions in peer-only mode.
        if (!in_array((int) $userid, array_map('intval', $studentuserids), true)) {
            return;
        }

        [$itemsql, $itemparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'itemid');
        $params = array_merge($itemparams, [
            'component' => $component,
            'itemtype' => $itemtype,
            'userid' => $userid,
        ]);

        $sql = "SELECT r.id, r.itemid, r.emoji
                  FROM {local_reactions} r
                  JOIN {forum_posts} fp ON fp.id = r.itemid
                 WHERE r.component = :component
                   AND r.itemtype = :itemtype
                   AND r.itemid $itemsql
                   AND r.userid = :userid
                   AND r.userid <> fp.userid";

        $userreactions = $DB->get_records_sql($sql, $params);
        foreach ($userreactions as $row) {
            $result[$row->itemid]['userreactions'][] = $row->emoji;
        }
    }

    /**
     * Get the user IDs of users who have a role with the 'student' archetype
     * in the course that owns the given context.
     *
     * @param \context $context Any context (module or course); the course context is derived.
     * @return int[] List of student user IDs.
     */
    private static function get_student_userids(\context $context): array {
        $coursecontext = $context->get_course_context(false);
        if (!$coursecontext) {
            return [];
        }

        $studentroles = get_archetype_roles('student');
        if (empty($studentroles)) {
            return [];
        }

        $userids = [];
        foreach ($studentroles as $role) {
            $users = get_role_users($role->id, $coursecontext, true, 'u.id', 'u.id');
            foreach ($users as $u) {
                $userids[(int) $u->id] = (int) $u->id;
            }
        }
        return array_values($userids);
    }

    /**
     * Check whether any reactions exist for posts belonging to a given forum.
     *
     * @param int $forumid The forum instance ID (forum.id).
     * @return bool True if at least one reaction exists.
     */
    public static function forum_has_reactions(int $forumid): bool {
        global $DB;
        return $DB->record_exists_sql(
            'SELECT 1
               FROM {local_reactions} lr
               JOIN {forum_posts} fp ON lr.itemid = fp.id
               JOIN {forum_discussions} fd ON fp.discussion = fd.id
              WHERE fd.forum = :forumid
                AND lr.component = :component
                AND lr.itemtype = :itemtype',
            ['forumid' => $forumid, 'component' => 'mod_forum', 'itemtype' => 'post']
        );
    }

    /**
     * Get aggregated reaction counts across all posts in the given discussions.
     *
     * Unlike get_reactions() which returns per-post data, this method
     * aggregates across all posts belonging to each discussion.
     *
     * @param string $component Component name e.g. mod_forum.
     * @param string $itemtype Item type e.g. post.
     * @param array $discussionids Array of forum_discussions IDs.
     * @return array Keyed by discussionid, each containing 'counts' as emoji => total.
     */
    public static function get_reactions_by_discussions(
        string $component,
        string $itemtype,
        array $discussionids
    ): array {
        global $DB;

        if (empty($discussionids)) {
            return [];
        }

        $result = [];
        foreach ($discussionids as $discussionid) {
            $result[$discussionid] = [
                'counts' => [],
            ];
        }

        [$insql, $params] = $DB->get_in_or_equal($discussionids, SQL_PARAMS_NAMED);
        $params['component'] = $component;
        $params['itemtype'] = $itemtype;

        $sql = "SELECT CONCAT(fp.discussion, '_', r.emoji) AS uid,
                       fp.discussion AS discussionid,
                       r.emoji,
                       COUNT(*) AS total
                  FROM {local_reactions} r
                  JOIN {forum_posts} fp ON r.itemid = fp.id
                 WHERE r.component = :component
                   AND r.itemtype = :itemtype
                   AND fp.discussion $insql
              GROUP BY fp.discussion, r.emoji
              ORDER BY fp.discussion, total DESC";

        $counts = $DB->get_records_sql($sql, $params);
        foreach ($counts as $row) {
            $result[$row->discussionid]['counts'][$row->emoji] = (int) $row->total;
        }

        return $result;
    }
}
