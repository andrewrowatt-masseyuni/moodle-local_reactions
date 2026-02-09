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
 * Hook callback to inject space-reserving CSS for the discussion list reactions bar.
 *
 * Injects an inline style into the page head that uses ::after pseudo-elements
 * to reserve vertical space for the reactions bar before JavaScript loads.
 * The JS module removes this style once it inserts its own skeleton loaders.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_standard_head_html_generation {
    /**
     * Inject space-reserving CSS on the forum discussion list page.
     * This is only added if the plugin is enabled, reactions are enabled for the current forum.
     * Users without the view capability will not have the CSS injected, to avoid reserving space unnecessarily.
     * A complex implementation (include the JavaScript aspects) but offers the best user experince.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function callback(\core\hook\output\before_standard_head_html_generation $hook): void {
        global $PAGE, $DB;

        if (!get_config('local_reactions', 'enabled')) {
            return;
        }

        if ($PAGE->pagetype !== 'mod-forum-view') {
            return;
        }

        $cm = $PAGE->cm;
        if (!$cm) {
            return;
        }
        $record = $DB->get_record('local_reactions_enabled', ['cmid' => $cm->id]);
        if (!$record || !$record->enabled) {
            return;
        }

        $context = $PAGE->context;
        if (!has_capability('local/reactions:view', $context)) {
            return;
        }

        $width = !empty($record->compactview_list) ? '80px' : '52px';

        $css = '[data-region="discussion-list-item"] th.topic .p-3::after {'
            . 'content:\'\';'
            . 'display:block;'
            . "width:{$width};"
            . 'height:28px;'
            . 'margin-top:0px;'
            . 'border-radius:16px;'
            . 'background:linear-gradient(90deg,#e8e8e8 25%,#f0f0f0 50%,#e8e8e8 75%);'
            . 'background-size:200% 100%;'
            . 'animation:local-reactions-reserve-shimmer 1.5s ease-in-out infinite;'
            . '}'
            . '@keyframes local-reactions-reserve-shimmer{'
            . '0%{background-position:200% 0}'
            . '100%{background-position:-200% 0}'
            . '}';

        $hook->add_html('<style id="local-reactions-reserve">' . $css . '</style>');
    }
}
