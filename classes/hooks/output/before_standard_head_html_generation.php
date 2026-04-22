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

use local_reactions\provider_registry;

/**
 * Hook callback to inject space-reserving CSS for the reactions bar.
 *
 * Injects an inline style into the page head that uses pseudo-elements to reserve vertical space
 * for the reactions bar before JavaScript loads. The JS module removes this style once it inserts
 * its own skeleton loaders.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_standard_head_html_generation {
    /**
     * Inject space-reserving CSS on any page a registered provider claims.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function callback(\core\hook\output\before_standard_head_html_generation $hook): void {
        global $PAGE;

        $cssfragments = [];
        foreach (provider_registry::get_all() as $provider) {
            $decision = $provider->resolve_for_page($PAGE);
            if ($decision === null) {
                continue;
            }
            $css = $provider->render_skeleton_css($decision);
            if ($css !== null && $css !== '') {
                $cssfragments[] = $css;
            }
        }

        if (!empty($cssfragments)) {
            $hook->add_html('<style id="local-reactions-reserve">' . implode('', $cssfragments) . '</style>');
        }
    }
}
