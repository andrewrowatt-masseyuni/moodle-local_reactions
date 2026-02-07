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
    const DEFAULT_EMOJIS = 'thumbsup:👍,heart:❤️,laugh:😂,think:🤔,celebrate:🎉,surprise:😮';

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
     * Toggle a reaction. If the user already has the same emoji, remove it.
     * If the user has a different emoji, replace it. If none, add it.
     *
     * @param string $component Component name e.g. mod_forum.
     * @param string $itemtype Item type e.g. post.
     * @param int $itemid Item ID.
     * @param int $userid User ID.
     * @param string $emoji Emoji shortcode.
     * @return array ['action' => 'added'|'removed'|'changed', 'emoji' => string]
     */
    public static function toggle_reaction(string $component, string $itemtype, int $itemid,
            int $userid, string $emoji): array {
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
        ]);

        if ($existing) {
            if ($existing->emoji === $emoji) {
                // Same emoji - remove the reaction.
                $DB->delete_records('local_reactions', ['id' => $existing->id]);
                return ['action' => 'removed', 'emoji' => $emoji];
            } else {
                // Different emoji - update.
                $existing->emoji = $emoji;
                $existing->timecreated = time();
                $DB->update_record('local_reactions', $existing);
                return ['action' => 'changed', 'emoji' => $emoji];
            }
        } else {
            // No existing reaction - add.
            $record = new \stdClass();
            $record->component = $component;
            $record->itemtype = $itemtype;
            $record->itemid = $itemid;
            $record->userid = $userid;
            $record->emoji = $emoji;
            $record->timecreated = time();
            $DB->insert_record('local_reactions', $record);
            return ['action' => 'added', 'emoji' => $emoji];
        }
    }

    /**
     * Get reaction counts and the current user's reaction for multiple items.
     *
     * @param string $component Component name.
     * @param string $itemtype Item type.
     * @param array $itemids Array of item IDs.
     * @param int $userid Current user ID.
     * @return array Keyed by itemid, each containing 'counts' and 'userreaction'.
     */
    public static function get_reactions(string $component, string $itemtype, array $itemids,
            int $userid): array {
        global $DB;

        if (empty($itemids)) {
            return [];
        }

        $result = [];
        foreach ($itemids as $itemid) {
            $result[$itemid] = [
                'counts' => [],
                'userreaction' => null,
            ];
        }

        // Get counts per emoji per item.
        list($insql, $params) = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
        $params['component'] = $component;
        $params['itemtype'] = $itemtype;

        $sql = "SELECT itemid, emoji, COUNT(*) as total
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
        $sql = "SELECT itemid, emoji
                  FROM {local_reactions}
                 WHERE component = :component
                   AND itemtype = :itemtype
                   AND itemid $insql
                   AND userid = :userid";

        $userreactions = $DB->get_records_sql($sql, $params);
        foreach ($userreactions as $row) {
            $result[$row->itemid]['userreaction'] = $row->emoji;
        }

        return $result;
    }
}
