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
 * Content provider for forum posts.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_provider implements content_provider {
    /** @var string[] Pagetypes where forum reactions render. */
    private const PAGETYPES = ['mod-forum-view', 'mod-forum-discuss', 'mod-forum-post'];

    #[\Override]
    public function get_component(): string {
        return manager::COMPONENT_FORUM;
    }

    #[\Override]
    public function get_itemtype(): string {
        return manager::ITEMTYPE_POST;
    }

    #[\Override]
    public function is_globally_enabled(): bool {
        return (bool) get_config('local_reactions', 'enabled');
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
        $cm = $page->cm;
        if (!$cm) {
            return null;
        }
        $record = manager::get_forum_config($cm->id);
        if (!$record || !$record->enabled) {
            return null;
        }
        $context = $page->context;
        if (!has_capability('local/reactions:view', $context)) {
            return null;
        }

        $isdiscussionlist = ($page->pagetype === 'mod-forum-view');

        $decision = new \stdClass();
        $decision->pagetype = $page->pagetype;
        $decision->context = $context;
        $decision->compactview = $isdiscussionlist
            ? !empty($record->compactview_list)
            : !empty($record->compactview_discuss);
        $decision->canreact = has_capability('local/reactions:react', $context);
        $decision->pollinterval = (int) get_config('local_reactions', 'pollinterval');
        $decision->isdiscussionlist = $isdiscussionlist;
        return $decision;
    }

    #[\Override]
    public function render_skeleton_css(\stdClass $decision): ?string {
        // The CSS selector and box dimensions differ per forum page type; all variants share the
        // local-reactions-shimmer keyframes declared in styles.css.
        $skeletons = [
            'mod-forum-post' => [
                'selector' => '.content-alignment-container::after',
                'extra' => 'display:block;height:28px;margin-top:8px;',
            ],
            'mod-forum-view' => [
                'selector' => '[data-region="discussion-list-item"] th.topic .p-3::after',
                'extra' => 'display:block;height:28px;margin-top:0px;',
            ],
            'mod-forum-discuss' => [
                'selector' => 'article[data-post-id] .d-flex.flex-wrap:has(>[data-region="post-actions-container"])::before',
                'extra' => 'height:26px;margin:calc(0.5rem + 2px) 0 4px 0;',
            ],
        ];
        if (!isset($skeletons[$decision->pagetype])) {
            return null;
        }
        $s = $skeletons[$decision->pagetype];
        $width = !empty($decision->compactview) ? '80px' : '52px';
        return $s['selector'] . '{'
            . 'content:\'\';'
            . $s['extra']
            . "width:{$width};"
            . 'border-radius:16px;'
            . 'background:linear-gradient(90deg,#e8e8e8 25%,#f0f0f0 50%,#e8e8e8 75%);'
            . 'background-size:200% 100%;'
            . 'animation:local-reactions-shimmer 1.5s ease-in-out infinite;'
            . '}';
    }

    #[\Override]
    public function get_js_calls(\stdClass $decision): array {
        $emojiset = manager::get_emoji_set();
        $base = [
            'contextid' => $decision->context->id,
            'component' => $this->get_component(),
            'itemtype' => $this->get_itemtype(),
            'emojis' => $emojiset,
            'compactview' => (bool) $decision->compactview,
            'pollinterval' => $decision->pollinterval,
        ];

        if ($decision->isdiscussionlist) {
            return [[
                'local_reactions/discussion_list_reactions',
                'init',
                [$base],
            ]];
        }

        $interactivecfg = array_merge($base, [
            'canreact' => (bool) $decision->canreact,
            'selectors' => self::get_interactive_selectors(),
        ]);
        return [[
            'local_reactions/reactions',
            'init',
            [$interactivecfg],
        ]];
    }

    #[\Override]
    public function get_context_for_item(int $itemid): ?\context {
        $vaultfactory = \mod_forum\local\container::get_vault_factory();
        $postvault = $vaultfactory->get_post_vault();
        $post = $postvault->get_from_id($itemid);
        if (!$post) {
            return null;
        }
        $discussionvault = $vaultfactory->get_discussion_vault();
        $discussion = $discussionvault->get_from_id($post->get_discussion_id());
        $forumvault = $vaultfactory->get_forum_vault();
        $forum = $forumvault->get_from_id($discussion->get_forum_id());
        return $forum->get_context();
    }

    #[\Override]
    public function require_view_capability(\context $context): void {
        require_capability('local/reactions:view', $context);
    }

    #[\Override]
    public function require_react_capability(\context $context): void {
        require_capability('local/reactions:react', $context);
    }

    #[\Override]
    public function get_runtime_settings_for_item(int $itemid): ?\stdClass {
        if (!$this->is_globally_enabled()) {
            return null;
        }
        $vaultfactory = \mod_forum\local\container::get_vault_factory();
        $postvault = $vaultfactory->get_post_vault();
        $post = $postvault->get_from_id($itemid);
        if (!$post) {
            return null;
        }
        $discussionvault = $vaultfactory->get_discussion_vault();
        $discussion = $discussionvault->get_from_id($post->get_discussion_id());
        $forumvault = $vaultfactory->get_forum_vault();
        $forum = $forumvault->get_from_id($discussion->get_forum_id());
        $cm = get_coursemodule_from_instance('forum', $forum->get_id(), 0, false, MUST_EXIST);
        $config = manager::get_forum_config($cm->id);
        if (!$config || !$config->enabled) {
            return null;
        }
        $result = new \stdClass();
        $result->enabled = true;
        $result->allowmultiple = (bool) $config->allowmultiplereactions;
        return $result;
    }

    #[\Override]
    public function get_privacy_contexts_sql(int $userid): ?array {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {forum_discussions} fd ON fd.forum = cm.instance
                  JOIN {forum_posts} fp ON fp.discussion = fd.id
                  JOIN {local_reactions} lr ON lr.component = :component
                                           AND lr.itemtype = :itemtype
                                           AND lr.itemid = fp.id
                 WHERE lr.userid = :userid
                   AND m.name = :modulename";
        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'component' => $this->get_component(),
            'itemtype' => $this->get_itemtype(),
            'userid' => $userid,
            'modulename' => 'forum',
        ];
        return [$sql, $params];
    }

    #[\Override]
    public function get_privacy_users_sql(\context $context): ?array {
        if (!$context instanceof \context_module) {
            return null;
        }
        $sql = "SELECT lr.userid AS userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {forum_discussions} fd ON fd.forum = cm.instance
                  JOIN {forum_posts} fp ON fp.discussion = fd.id
                  JOIN {local_reactions} lr ON lr.component = :component
                                           AND lr.itemtype = :itemtype
                                           AND lr.itemid = fp.id
                 WHERE cm.id = :cmid
                   AND m.name = :modulename";
        $params = [
            'cmid' => $context->instanceid,
            'component' => $this->get_component(),
            'itemtype' => $this->get_itemtype(),
            'modulename' => 'forum',
        ];
        return [$sql, $params];
    }

    #[\Override]
    public function get_privacy_reaction_ids_sql(\context $context, ?int $userid, ?array $userids): ?array {
        global $DB;
        if (!$context instanceof \context_module) {
            return null;
        }
        $params = [
            'cmid' => $context->instanceid,
            'component' => $this->get_component(),
            'itemtype' => $this->get_itemtype(),
            'modulename' => 'forum',
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
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {forum_discussions} fd ON fd.forum = cm.instance
                  JOIN {forum_posts} fp ON fp.discussion = fd.id
                  JOIN {local_reactions} lr ON lr.component = :component
                                           AND lr.itemtype = :itemtype
                                           AND lr.itemid = fp.id
                 WHERE cm.id = :cmid
                   AND m.name = :modulename"
                . $where;
        return [$sql, $params];
    }

    /**
     * CSS selectors + insertion points used by reactions.js on forum discussion/post pages.
     *
     * @return array
     */
    public static function get_interactive_selectors(): array {
        return [
            'item' => 'article[data-post-id]',
            'itemIdAttr' => 'data-post-id',
            'insertBeforeSelector' => '[data-region="post-actions-container"]',
            'appendFallbackSelectors' => [
                '.content-alignment-container',
                '[data-region-content="forum-post-core"]',
            ],
            'mutationRoot' => '[data-content="forum-discussion"]',
        ];
    }
}
