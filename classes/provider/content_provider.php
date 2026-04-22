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

namespace local_reactions\provider;

/**
 * Contract for a content type that emoji reactions can attach to (e.g. forum posts, blog entries).
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface content_provider {
    /**
     * Component string stored in {local_reactions}.component, e.g. 'mod_forum' or 'core_blog'.
     *
     * @return string
     */
    public function get_component(): string;

    /**
     * Item type string stored in {local_reactions}.itemtype, e.g. 'post' or 'entry'.
     *
     * @return string
     */
    public function get_itemtype(): string;

    /**
     * Is the site-wide admin toggle for this content type enabled?
     *
     * @return bool
     */
    public function is_globally_enabled(): bool;

    /**
     * Pagetypes this provider listens to, for early filtering in hook callbacks.
     *
     * @return string[]
     */
    public function get_pagetypes(): array;

    /**
     * Decide whether reactions should load on the given page for the current user.
     *
     * Returns a decision object carrying the context, pagetype and any per-page settings (compactview, canreact,
     * allowmultiple) used to render the skeleton CSS and the AMD config; or null if reactions should not load.
     *
     * @param \moodle_page $page
     * @return \stdClass|null
     */
    public function resolve_for_page(\moodle_page $page): ?\stdClass;

    /**
     * Return an inline CSS skeleton string (without surrounding style tags) that reserves vertical space
     * for the reactions bar before the AMD module loads, or null if no skeleton is needed.
     *
     * @param \stdClass $decision The object returned by resolve_for_page().
     * @return string|null
     */
    public function render_skeleton_css(\stdClass $decision): ?string;

    /**
     * Return the AMD module calls to schedule on this page.
     *
     * Each entry is [$amdmodule, $method, $args]. For most providers this is a single
     * `local_reactions/reactions` init call; the forum discussion list also triggers the read-only
     * discussion_list module.
     *
     * @param \stdClass $decision The object returned by resolve_for_page().
     * @return array<int, array{0:string,1:string,2:array}>
     */
    public function get_js_calls(\stdClass $decision): array;

    /**
     * Resolve the Moodle context to use for capability checks when someone toggles a reaction
     * on an item of this type.
     *
     * Return null if the item does not exist or does not belong to this provider.
     *
     * @param int $itemid
     * @return \context|null
     */
    public function get_context_for_item(int $itemid): ?\context;

    /**
     * Throw if the current user is not allowed to view reactions on an item of this type in the given context.
     *
     * Providers whose context level is CONTEXT_MODULE typically check `local/reactions:view`; providers at
     * system context (e.g. blog) use the native read capability for that content type instead.
     *
     * @param \context $context
     */
    public function require_view_capability(\context $context): void;

    /**
     * Throw if the current user is not allowed to react to an item of this type in the given context.
     *
     * @param \context $context
     */
    public function require_react_capability(\context $context): void;

    /**
     * Return whether reactions are enabled for the specific item (site toggle + any per-instance setting),
     * plus the allowmultiple flag for the toggle_reaction webservice.
     *
     * @param int $itemid
     * @return \stdClass|null Object with bool `enabled` and bool `allowmultiple`, or null if not applicable.
     */
    public function get_runtime_settings_for_item(int $itemid): ?\stdClass;

    /**
     * Return [$sql, $params] producing a list of context.id values where this userid has reactions of this type,
     * or null if this provider does not contribute any contexts.
     *
     * @param int $userid
     * @return array|null
     */
    public function get_privacy_contexts_sql(int $userid): ?array;

    /**
     * Return [$sql, $params] producing a list of userids with reactions in the given context, or null
     * if this provider does not own reactions in this context level.
     *
     * The SQL must select a column aliased `userid`.
     *
     * @param \context $context
     * @return array|null
     */
    public function get_privacy_users_sql(\context $context): ?array;

    /**
     * Return [$sql, $params] producing {local_reactions}.id values in the given context, optionally
     * filtered to a single user or a set of users. Returns null if this provider does not own the context.
     *
     * @param \context $context
     * @param int|null $userid
     * @param int[]|null $userids
     * @return array|null
     */
    public function get_privacy_reaction_ids_sql(\context $context, ?int $userid, ?array $userids): ?array;
}
