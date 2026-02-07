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

namespace local_reactions\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use local_reactions\manager;

/**
 * External function to toggle an emoji reaction.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class toggle_reaction extends external_api {

    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'component' => new external_value(PARAM_COMPONENT, 'Component name'),
            'itemtype' => new external_value(PARAM_ALPHANUMEXT, 'Item type'),
            'itemid' => new external_value(PARAM_INT, 'Item ID'),
            'emoji' => new external_value(PARAM_ALPHANUMEXT, 'Emoji shortcode'),
        ]);
    }

    /**
     * Toggle a reaction and return updated counts.
     *
     * @param string $component
     * @param string $itemtype
     * @param int $itemid
     * @param string $emoji
     * @return array
     */
    public static function execute(string $component, string $itemtype, int $itemid,
            string $emoji): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'component' => $component,
            'itemtype' => $itemtype,
            'itemid' => $itemid,
            'emoji' => $emoji,
        ]);

        // Only forum posts supported for now.
        if ($params['component'] !== 'mod_forum' || $params['itemtype'] !== 'post') {
            throw new \invalid_parameter_exception('Only mod_forum posts are supported');
        }

        // Get the forum post and validate context.
        $vaultfactory = \mod_forum\local\container::get_vault_factory();
        $postvault = $vaultfactory->get_post_vault();
        $post = $postvault->get_from_id($params['itemid']);
        if (!$post) {
            throw new \invalid_parameter_exception('Post not found');
        }

        $discussionvault = $vaultfactory->get_discussion_vault();
        $discussion = $discussionvault->get_from_id($post->get_discussion_id());
        $forumvault = $vaultfactory->get_forum_vault();
        $forum = $forumvault->get_from_id($discussion->get_forum_id());
        $context = $forum->get_context();

        self::validate_context($context);
        require_capability('local/reactions:react', $context);

        $result = manager::toggle_reaction(
            $params['component'],
            $params['itemtype'],
            $params['itemid'],
            $USER->id,
            $params['emoji']
        );

        // Return updated reactions for this item.
        $reactions = manager::get_reactions(
            $params['component'],
            $params['itemtype'],
            [$params['itemid']],
            $USER->id
        );

        $itemreactions = $reactions[$params['itemid']];
        $counts = [];
        foreach ($itemreactions['counts'] as $emojicode => $count) {
            $counts[] = [
                'emoji' => $emojicode,
                'count' => $count,
            ];
        }

        return [
            'action' => $result['action'],
            'userreactions' => $itemreactions['userreactions'],
            'counts' => $counts,
        ];
    }

    /**
     * Return value definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'action' => new external_value(PARAM_ALPHA, 'Action taken: added or removed'),
            'userreactions' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Emoji shortcode')
            ),
            'counts' => new external_multiple_structure(
                new external_single_structure([
                    'emoji' => new external_value(PARAM_ALPHANUMEXT, 'Emoji shortcode'),
                    'count' => new external_value(PARAM_INT, 'Reaction count'),
                ])
            ),
        ]);
    }
}
