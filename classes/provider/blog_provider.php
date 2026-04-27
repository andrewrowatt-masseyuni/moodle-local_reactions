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

use local_reactions\manager;

/**
 * Content provider for Moodle core blog entries.
 *
 * Blog entries live in the {post} table with module='blog' and are exposed at SYSTEM context.
 * The site-wide admin setting `local_reactions/enabledblog` gates whether reactions load on blog pages.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class blog_provider implements content_provider {
    /** @var string[] Pagetypes where blog reactions render. */
    private const PAGETYPES = ['blog-index'];

    #[\Override]
    public function get_component(): string {
        return manager::COMPONENT_BLOG;
    }

    #[\Override]
    public function get_itemtype(): string {
        return manager::ITEMTYPE_ENTRY;
    }

    #[\Override]
    public function is_globally_enabled(): bool {
        return (bool) get_config('local_reactions', 'enabledblog');
    }

    #[\Override]
    public function get_pagetypes(): array {
        return self::PAGETYPES;
    }

    #[\Override]
    public function resolve_for_page(\moodle_page $page): ?\stdClass {
        if (!$this->is_globally_enabled()) {
            return null;
        }
        if (!in_array($page->pagetype, self::PAGETYPES, true)) {
            return null;
        }

        // Blog reactions sit at SYSTEM context — gate on moodle/blog:view, which is the same capability
        // core Moodle uses to control reading other users' blog entries. Guests (not logged in) cannot react.
        if (!isloggedin() || isguestuser()) {
            return null;
        }
        $systemcontext = \context_system::instance();
        if (!has_capability('moodle/blog:view', $systemcontext)) {
            return null;
        }

        $decision = new \stdClass();
        $decision->pagetype = $page->pagetype;
        $decision->context = $systemcontext;
        $decision->compactview = false;
        $decision->canreact = true;
        $decision->pollinterval = (int) get_config('local_reactions', 'pollinterval');
        return $decision;
    }

    #[\Override]
    public function render_skeleton_css(\stdClass $decision): ?string {
        // Reserve vertical space for the bar below each blog entry's content block, before the commands row.
        return 'div.blog_entry .no-overflow.content .commands::before{'
            . 'content:\'\';'
            . 'display:block;'
            . 'height:28px;'
            . 'margin:8px 0 4px 0;'
            . 'width:52px;'
            . 'border-radius:16px;'
            . 'background:linear-gradient(90deg,#e8e8e8 25%,#f0f0f0 50%,#e8e8e8 75%);'
            . 'background-size:200% 100%;'
            . 'animation:local-reactions-shimmer 1.5s ease-in-out infinite;'
            . '}';
    }

    #[\Override]
    public function get_js_calls(\stdClass $decision): array {
        $emojiset = manager::get_emoji_set();
        $cfg = [
            'contextid' => $decision->context->id,
            'component' => $this->get_component(),
            'itemtype' => $this->get_itemtype(),
            'canreact' => (bool) $decision->canreact,
            'emojis' => $emojiset,
            'compactview' => (bool) $decision->compactview,
            'pollinterval' => $decision->pollinterval,
            'selectors' => self::get_interactive_selectors(),
        ];
        return [[
            'local_reactions/reactions',
            'init',
            [$cfg],
        ]];
    }

    #[\Override]
    public function get_context_for_item(int $itemid): ?\context {
        global $DB;
        if (!$DB->record_exists('post', ['id' => $itemid, 'module' => 'blog'])) {
            return null;
        }
        return \context_system::instance();
    }

    #[\Override]
    public function require_view_capability(\context $context): void {
        if (!isloggedin() || isguestuser()) {
            throw new \required_capability_exception($context, 'moodle/blog:view', 'nopermissions', '');
        }
        require_capability('moodle/blog:view', $context);
    }

    #[\Override]
    public function require_react_capability(\context $context): void {
        if (!isloggedin() || isguestuser()) {
            throw new \required_capability_exception($context, 'moodle/blog:view', 'nopermissions', '');
        }
        require_capability('moodle/blog:view', $context);
    }

    #[\Override]
    public function get_runtime_settings_for_item(int $itemid): ?\stdClass {
        if (!$this->is_globally_enabled()) {
            return null;
        }
        $context = $this->get_context_for_item($itemid);
        if (!$context) {
            return null;
        }
        $result = new \stdClass();
        $result->enabled = true;
        $result->allowmultiple = (bool) get_config('local_reactions', 'allowmultiplereactionsblog');
        return $result;
    }

    #[\Override]
    public function get_privacy_contexts_sql(int $userid): ?array {
        // Blog reactions exist at SYSTEM context. If the user has any, add system context.
        // EXISTS avoids the cross-join that a JOIN without a key condition would produce.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :contextlevel
                   AND EXISTS (
                       SELECT 1
                         FROM {local_reactions} lr
                        WHERE lr.component = :component
                          AND lr.itemtype = :itemtype
                          AND lr.userid = :userid
                   )";
        $params = [
            'contextlevel' => CONTEXT_SYSTEM,
            'component' => $this->get_component(),
            'itemtype' => $this->get_itemtype(),
            'userid' => $userid,
        ];
        return [$sql, $params];
    }

    #[\Override]
    public function get_privacy_users_sql(\context $context): ?array {
        if (!$context instanceof \context_system) {
            return null;
        }
        $sql = "SELECT lr.userid AS userid
                  FROM {local_reactions} lr
                 WHERE lr.component = :component
                   AND lr.itemtype = :itemtype";
        $params = [
            'component' => $this->get_component(),
            'itemtype' => $this->get_itemtype(),
        ];
        return [$sql, $params];
    }

    #[\Override]
    public function get_privacy_reaction_ids_sql(\context $context, ?int $userid, ?array $userids): ?array {
        global $DB;
        if (!$context instanceof \context_system) {
            return null;
        }
        $params = [
            'component' => $this->get_component(),
            'itemtype' => $this->get_itemtype(),
        ];
        $where = '';
        if ($userid !== null) {
            $params['userid'] = $userid;
            $where = ' AND lr.userid = :userid';
        } else if (!empty($userids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
            $params += $inparams;
            $where = " AND lr.userid $insql";
        }
        $sql = "SELECT lr.id
                  FROM {local_reactions} lr
                 WHERE lr.component = :component
                   AND lr.itemtype = :itemtype"
                . $where;
        return [$sql, $params];
    }

    /**
     * CSS selectors and insertion points used by reactions.js on blog pages.
     *
     * @return array
     */
    public static function get_interactive_selectors(): array {
        return [
            'item' => 'div.blog_entry[id^="b"]',
            'itemIdPrefix' => 'b',
            'insertBeforeSelector' => '.commands',
            'appendFallbackSelectors' => ['.no-overflow.content'],
        ];
    }
}
