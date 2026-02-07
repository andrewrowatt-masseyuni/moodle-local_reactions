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
 * External function to get reactions for multiple items.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_reactions extends external_api {

    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'component' => new external_value(PARAM_COMPONENT, 'Component name'),
            'itemtype' => new external_value(PARAM_ALPHANUMEXT, 'Item type'),
            'itemids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Item ID')
            ),
            'contextid' => new external_value(PARAM_INT, 'Context ID for permission checks'),
        ]);
    }

    /**
     * Get reactions for the given items.
     *
     * @param string $component
     * @param string $itemtype
     * @param array $itemids
     * @param int $contextid
     * @return array
     */
    public static function execute(string $component, string $itemtype, array $itemids,
            int $contextid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'component' => $component,
            'itemtype' => $itemtype,
            'itemids' => $itemids,
            'contextid' => $contextid,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('local/reactions:view', $context);

        $reactions = manager::get_reactions(
            $params['component'],
            $params['itemtype'],
            $params['itemids'],
            $USER->id
        );

        $items = [];
        foreach ($reactions as $itemid => $data) {
            $counts = [];
            foreach ($data['counts'] as $emoji => $count) {
                $counts[] = [
                    'emoji' => $emoji,
                    'count' => $count,
                ];
            }
            $items[] = [
                'itemid' => $itemid,
                'userreactions' => $data['userreactions'],
                'counts' => $counts,
            ];
        }

        return ['items' => $items];
    }

    /**
     * Return value definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'itemid' => new external_value(PARAM_INT, 'Item ID'),
                    'userreactions' => new external_multiple_structure(
                        new external_value(PARAM_ALPHANUMEXT, 'Emoji shortcode')
                    ),
                    'counts' => new external_multiple_structure(
                        new external_single_structure([
                            'emoji' => new external_value(PARAM_ALPHANUMEXT, 'Emoji shortcode'),
                            'count' => new external_value(PARAM_INT, 'Reaction count'),
                        ])
                    ),
                ])
            ),
        ]);
    }
}
