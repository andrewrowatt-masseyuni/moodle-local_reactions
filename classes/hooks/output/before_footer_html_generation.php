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

namespace local_reactions\hooks\output;

/**
 * Hook callback to inject reactions JS on forum pages.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_footer_html_generation {
    /**
     * Inject the reactions AMD module on forum pages.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function callback(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $DB;

        if (!get_config('local_reactions', 'enabled')) {
            return;
        }

        // Only load on forum pages.
        $pagetype = $PAGE->pagetype;
        if ($pagetype !== 'mod-forum-discuss' && $pagetype !== 'mod-forum-view') {
            return;
        }

        // Check reactions are enabled for this specific forum.
        $cm = $PAGE->cm;
        if (!$cm) {
            return;
        }
        $record = $DB->get_record('local_reactions_enabled', ['cmid' => $cm->id]);
        if (!$record || !$record->enabled) {
            return;
        }

        // Check the user has at least view capability.
        $context = $PAGE->context;
        if (!has_capability('local/reactions:view', $context)) {
            return;
        }

        $emojiset = \local_reactions\manager::get_emoji_set();
        $pollinterval = (int) get_config('local_reactions', 'pollinterval');

        if ($pagetype === 'mod-forum-discuss') {
            // Individual discussion page: full interactive reactions.
            $canreact = has_capability('local/reactions:react', $context);

            $PAGE->requires->js_call_amd('local_reactions/reactions', 'init', [
                [
                    'contextid' => $context->id,
                    'component' => 'mod_forum',
                    'itemtype' => 'post',
                    'canreact' => $canreact,
                    'emojis' => $emojiset,
                    'compactview' => !empty($record->compactview_discuss),
                    'pollinterval' => $pollinterval,
                ],
            ]);
        } else if ($pagetype === 'mod-forum-view') {
            // Discussion list page: read-only aggregated reaction pills.
            $PAGE->requires->js_call_amd('local_reactions/discussion_list_reactions', 'init', [
                [
                    'contextid' => $context->id,
                    'component' => 'mod_forum',
                    'itemtype' => 'post',
                    'emojis' => $emojiset,
                    'compactview' => !empty($record->compactview_list),
                    'pollinterval' => $pollinterval,
                ],
            ]);
        }
    }
}
