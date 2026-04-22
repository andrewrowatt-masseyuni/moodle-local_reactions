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
 * Hook callback to inject the reactions AMD module on any page a provider claims.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_footer_html_generation {
    /**
     * Schedule each claiming provider's AMD init calls.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function callback(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE;

        foreach (provider_registry::get_all() as $provider) {
            $decision = $provider->resolve_for_page($PAGE);
            if ($decision === null) {
                continue;
            }
            foreach ($provider->get_js_calls($decision) as $call) {
                [$amdmodule, $method, $args] = $call;
                $PAGE->requires->js_call_amd($amdmodule, $method, $args);
            }
        }
    }
}
