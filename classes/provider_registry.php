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

use local_reactions\provider\content_provider;

/**
 * Registry of content_provider implementations.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_registry {
    /** @var content_provider[]|null Per-request cache of registered providers. */
    private static ?array $providers = null;

    /**
     * Return all registered content providers.
     *
     * @return content_provider[]
     */
    public static function get_all(): array {
        if (self::$providers !== null) {
            return self::$providers;
        }
        self::$providers = [
            new \local_reactions\provider\forum_provider(),
            new \local_reactions\provider\blog_provider(),
        ];
        return self::$providers;
    }

    /**
     * Find the provider matching the given (component, itemtype) pair.
     *
     * @param string $component
     * @param string $itemtype
     * @return content_provider|null
     */
    public static function get_for_component_itemtype(string $component, string $itemtype): ?content_provider {
        foreach (self::get_all() as $provider) {
            if ($provider->get_component() === $component && $provider->get_itemtype() === $itemtype) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Clear the in-memory provider cache. Test-only helper.
     */
    public static function reset_cache(): void {
        self::$providers = null;
    }
}
