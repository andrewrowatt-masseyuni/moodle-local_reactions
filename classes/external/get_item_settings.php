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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use local_reactions\manager;
use local_reactions\provider_registry;

/**
 * External function returning the reactions configuration for a set of items.
 *
 * On the web this configuration is baked into the AMD init call by the content providers'
 * resolve_for_page(). The Moodle App has no page render to hook into, and no reliable way to learn
 * the course module an on-screen post belongs to, so it asks by item ID instead and lets the
 * provider resolve the rest. Parameters mirror get_reactions deliberately.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_item_settings extends external_api {
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
        ]);
    }

    /**
     * Return the reactions configuration for each requested item.
     *
     * Items the user cannot see, or that cannot be resolved, are reported as warnings rather than
     * throwing, so one unusable ID does not fail the whole batch.
     *
     * @param string $component
     * @param string $itemtype
     * @param array $itemids
     * @return array
     */
    public static function execute(string $component, string $itemtype, array $itemids): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'component' => $component,
            'itemtype' => $itemtype,
            'itemids' => $itemids,
        ]);

        $provider = provider_registry::get_for_component_itemtype($params['component'], $params['itemtype']);
        if (!$provider) {
            throw new \invalid_parameter_exception(
                'Unsupported component/itemtype: ' . $params['component'] . '/' . $params['itemtype']
            );
        }

        $globallyenabled = $provider->is_globally_enabled();
        $settings = [];
        $warnings = [];

        foreach (array_unique($params['itemids']) as $itemid) {
            // Each item costs a provider lookup. That is a handful of indexed point queries for a
            // forum post, and the app asks once per batch of posts appearing on screen.
            $context = $provider->get_context_for_item($itemid);
            if (!$context) {
                $warnings[] = self::warning($itemid, 'itemnotfound', 'Item not found.');
                continue;
            }

            try {
                self::validate_context($context);
            } catch (\Exception $e) {
                $warnings[] = self::warning($itemid, 'nopermissions', $e->getMessage());
                continue;
            }

            // Per-activity settings are keyed by cmid. Items outside an activity (blog entries)
            // have no such row, and simply report as not enabled.
            $cmid = $context instanceof \context_module ? (int) $context->instanceid : 0;
            $config = ($cmid && $globallyenabled) ? manager::get_module_config($cmid) : null;
            $enabled = !empty($config) && !empty($config->enabled);

            if ($enabled) {
                try {
                    $provider->require_view_capability($context);
                } catch (\moodle_exception $e) {
                    $enabled = false;
                }
            }

            $settings[] = [
                'itemid' => (int) $itemid,
                'cmid' => $cmid,
                'contextid' => (int) $context->id,
                'enabled' => $enabled,
                'canreact' => $enabled && self::can_react($provider, $context),
                'compactview' => $enabled && !empty($config->compactview_discuss),
                'allowmultiplereactions' => $enabled && !empty($config->allowmultiplereactions),
            ];
        }

        $emojis = [];
        foreach (manager::get_emoji_set() as $shortcode => $emoji) {
            $emojis[] = [
                'shortcode' => $shortcode,
                'emoji' => $emoji,
            ];
        }

        return [
            'settings' => $settings,
            'emojis' => $emojis,
            'warnings' => $warnings,
        ];
    }

    /**
     * Whether the current user may react in this context.
     *
     * Asks the provider rather than checking local/reactions:react directly, so a provider that
     * gates reacting on a different capability stays authoritative.
     *
     * @param \local_reactions\provider\content_provider $provider
     * @param \context $context
     * @return bool
     */
    private static function can_react($provider, \context $context): bool {
        try {
            $provider->require_react_capability($context);
        } catch (\moodle_exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Build a warning row for an item that could not be resolved.
     *
     * @param int $itemid
     * @param string $warningcode
     * @param string $message
     * @return array
     */
    private static function warning(int $itemid, string $warningcode, string $message): array {
        return [
            'item' => 'item',
            'itemid' => $itemid,
            'warningcode' => $warningcode,
            'message' => $message,
        ];
    }

    /**
     * Return value definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'settings' => new external_multiple_structure(
                new external_single_structure([
                    'itemid' => new external_value(PARAM_INT, 'Item ID'),
                    'cmid' => new external_value(PARAM_INT, 'Course module ID, or 0 if the item is not in an activity'),
                    'contextid' => new external_value(PARAM_INT, 'Context ID to pass to the other reactions functions'),
                    'enabled' => new external_value(PARAM_BOOL, 'Whether reactions are enabled and visible here'),
                    'canreact' => new external_value(PARAM_BOOL, 'Whether the user may add or remove reactions'),
                    'compactview' => new external_value(PARAM_BOOL, 'Whether the single-item view uses the compact pill'),
                    'allowmultiplereactions' => new external_value(
                        PARAM_BOOL,
                        'Whether a user may hold more than one emoji on the same item'
                    ),
                ])
            ),
            'emojis' => new external_multiple_structure(
                new external_single_structure([
                    'shortcode' => new external_value(PARAM_ALPHANUMEXT, 'Emoji shortcode'),
                    'emoji' => new external_value(PARAM_TEXT, 'Unicode emoji character'),
                ])
            ),
            'warnings' => new external_warnings(),
        ]);
    }
}
