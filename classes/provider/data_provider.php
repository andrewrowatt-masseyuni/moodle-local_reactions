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
 * Content provider for database activity (mod_data) entries.
 *
 * Unlike forum posts and blog entries, mod_data renders each entry through a teacher-editable
 * HTML template, so the rendered markup carries no record ID and no guaranteed per-entry wrapper.
 * This provider therefore supports two ways of locating entries in the DOM:
 *
 *  1. Anchor mode (works with any template, including presets). The teacher adds
 *     `<div data-region="local-reactions-anchor" data-recordid="##id##"></div>` to the list and/or
 *     single template. The record ID comes straight from the DOM and the bar renders inside the
 *     anchor, exactly where the teacher put it.
 *  2. Default-template mode (zero configuration). When the activity still uses Moodle's default
 *     templates the entry wrappers are known (`.defaulttemplate-listentry` /
 *     `#defaulttemplate-single`), so this provider re-runs the same entry search view.php just ran
 *     and hands the AMD module the record IDs in render order. The module maps them onto the entry
 *     elements positionally and refuses to render at all if the two counts disagree.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_provider implements content_provider {
    /** @var string[] Pagetypes where database activity reactions render. */
    private const PAGETYPES = ['mod-data-view'];

    /**
     * @var string Marker looked for in the entry template to switch to anchor mode. Kept in sync
     * with the `data-region` value documented in the README and the activity settings help text.
     */
    private const ANCHOR_MARKER = 'local-reactions-anchor';

    /** @var string CSS selector matching the anchor elements in anchor mode. */
    private const ANCHOR_SELECTOR = '[data-region="local-reactions-anchor"]';

    /** @var \stdClass|null|false Memoised resolve_for_page() result (false = not computed yet). */
    private $decision = false;

    #[\Override]
    public function get_component(): string {
        return manager::COMPONENT_DATA;
    }

    #[\Override]
    public function get_itemtype(): string {
        return manager::ITEMTYPE_RECORD;
    }

    #[\Override]
    public function is_globally_enabled(): bool {
        return (bool) get_config('local_reactions', 'enableddata');
    }

    #[\Override]
    public function get_pagetypes(): array {
        return self::PAGETYPES;
    }

    #[\Override]
    public function resolve_for_page(\moodle_page $page): ?\stdClass {
        // Both output hooks call this on the same request; resolving the visible records costs a
        // full entry search, so compute it at most once per page render.
        if ($this->decision !== false) {
            return $this->decision;
        }
        return $this->decision = $this->compute_decision($page);
    }

    /**
     * Work out whether reactions should load on this page, and how entries can be located in the DOM.
     *
     * @param \moodle_page $page
     * @return \stdClass|null
     */
    private function compute_decision(\moodle_page $page): ?\stdClass {
        global $CFG, $DB;

        if (!$this->is_globally_enabled()) {
            return null;
        }
        if (!in_array($page->pagetype, self::PAGETYPES, true)) {
            return null;
        }
        $cm = $page->cm;
        if (!$cm || $cm->modname !== 'data') {
            return null;
        }
        $record = manager::get_module_config($cm->id);
        if (!$record || !$record->enabled) {
            return null;
        }
        $context = $page->context;
        if (!has_capability('local/reactions:view', $context) || !has_capability('mod/data:viewentry', $context)) {
            return null;
        }
        if (!$this->is_entry_browse_view()) {
            return null;
        }

        require_once($CFG->dirroot . '/mod/data/locallib.php');
        $data = $DB->get_record('data', ['id' => $cm->instance], '*', IGNORE_MISSING);
        if (!$data) {
            return null;
        }

        // Core's view.php only treats the URL record ID as "show this one entry" when the URL does
        // not also identify the course module; mirror that so our mode matches what was rendered.
        $rid = optional_param('id', 0, PARAM_INT) ? 0 : optional_param('rid', 0, PARAM_INT);
        $issingle = ($rid > 0 || optional_param('mode', '', PARAM_ALPHA) === 'single');
        $templatecontent = (string) ($issingle ? ($data->singletemplate ?? '') : ($data->listtemplate ?? ''));
        $useanchors = (strpos($templatecontent, self::ANCHOR_MARKER) !== false);
        if (!$useanchors && !$this->has_default_entry_wrapper($templatecontent, $issingle)) {
            // A customised template with neither an anchor nor the default entry wrapper leaves us
            // nothing to attach the bar to, so skip the page entirely rather than pay for the entry
            // search and emit a skeleton that would never be replaced.
            return null;
        }

        $decision = new \stdClass();
        $decision->pagetype = $page->pagetype;
        $decision->context = $context;
        $decision->issingle = $issingle;
        $decision->useanchors = $useanchors;
        $decision->compactview = $issingle
            ? !empty($record->compactview_discuss)
            : !empty($record->compactview_list);
        $decision->canreact = has_capability('local/reactions:react', $context)
            && has_capability('mod/data:viewentry', $context);
        $decision->pollinterval = (int) get_config('local_reactions', 'pollinterval');
        $decision->recordids = $useanchors ? [] : $this->resolve_visible_recordids($data, $cm, $context, $rid);
        return $decision;
    }

    /**
     * Does the entry template still wrap entries in the element the default templates use?
     *
     * An empty template means the activity has not customised it, so core renders its default and
     * the wrapper is guaranteed to be there.
     *
     * @param string $templatecontent The activity's stored template content.
     * @param bool $issingle Whether the single view template is in play.
     * @return bool
     */
    private function has_default_entry_wrapper(string $templatecontent, bool $issingle): bool {
        if ($templatecontent === '') {
            return true;
        }
        $wrapper = $issingle ? 'defaulttemplate-single' : 'defaulttemplate-listentry';
        return strpos($templatecontent, $wrapper) !== false;
    }

    /**
     * Is this request rendering the normal entry browser, rather than one of view.php's other screens?
     *
     * The advanced search form and the delete/multi-delete confirmations all share the
     * mod-data-view pagetype but either show no entries or show a set of entries that does not
     * match the current search, so reactions stay off there.
     *
     * @return bool
     */
    private function is_entry_browse_view(): bool {
        if (optional_param('mode', '', PARAM_ALPHA) === 'asearch') {
            return false;
        }
        if (optional_param('confirm', 0, PARAM_INT)) {
            // The delete already happened; the page falls through to the normal listing.
            return true;
        }
        return !optional_param('delete', 0, PARAM_INT)
            && optional_param('serialdelete', '', PARAM_RAW) === ''
            && empty(optional_param_array('delcheck', [], PARAM_INT));
    }

    /**
     * Return the IDs of the entries rendered on this page, in render order.
     *
     * Re-runs core's `data_search_entries()` with the same inputs view.php used earlier in this
     * request: the browsing preferences it made sticky in $SESSION, the paging parameters from the
     * URL, and the per-user entries-per-page preference it had already updated.
     *
     * @param \stdClass $data The database activity instance record.
     * @param \cm_info|\stdClass $cm The course module.
     * @param \context $context The module context.
     * @param int $rid Record ID from the URL when a single entry was requested, otherwise 0.
     * @return int[] Record IDs in the order they were rendered.
     */
    private function resolve_visible_recordids(\stdClass $data, $cm, \context $context, int $rid): array {
        global $DB, $SESSION;

        $prefs = $SESSION->dataprefs[$data->id] ?? [];
        $search = $prefs['search'] ?? '';
        $searcharray = $prefs['search_array'] ?? [];
        $advanced = $prefs['advanced'] ?? 0;
        $sort = $prefs['sort'] ?? $data->defaultsort;
        $order = $prefs['order'] ?? (($data->defaultsortdir == 0) ? 'ASC' : 'DESC');

        $page = optional_param('page', 0, PARAM_INT);
        $mode = optional_param('mode', '', PARAM_ALPHA);
        // Core's view.php clamps the entries-per-page preference to 2 and writes the clamped value back.
        $perpage = max(2, (int) get_user_preferences('data_perpage_' . $data->id, 10));
        $currentgroup = groups_get_activity_group($cm);

        $record = null;
        if ($rid) {
            $record = $DB->get_record('data_records', ['id' => $rid, 'dataid' => $data->id]) ?: null;
        }

        [$records] = data_search_entries(
            $data,
            $cm,
            $context,
            $mode,
            $currentgroup,
            $search,
            $sort,
            $order,
            $page,
            $perpage,
            $advanced,
            $searcharray,
            $record
        );

        return array_map('intval', array_keys($records ?: []));
    }

    #[\Override]
    public function render_skeleton_css(\stdClass $decision): ?string {
        if ($decision->useanchors) {
            $selector = self::ANCHOR_SELECTOR . '::after';
        } else if ($decision->issingle) {
            $selector = '#defaulttemplate-single .defaulttemplate-single-body::after';
        } else {
            $selector = '.defaulttemplate-listentry .defaulttemplate-list-body::after';
        }
        $width = !empty($decision->compactview) ? '80px' : '52px';
        return $selector . '{'
            . 'content:\'\';'
            . 'display:block;'
            . 'height:28px;'
            . 'margin:8px 0 4px 0;'
            . "width:{$width};"
            . 'border-radius:16px;'
            . 'background:linear-gradient(90deg,#e8e8e8 25%,#f0f0f0 50%,#e8e8e8 75%);'
            . 'background-size:200% 100%;'
            . 'animation:local-reactions-shimmer 1.5s ease-in-out infinite;'
            . '}';
    }

    #[\Override]
    public function get_js_calls(\stdClass $decision): array {
        $cfg = [
            'contextid' => $decision->context->id,
            'component' => $this->get_component(),
            'itemtype' => $this->get_itemtype(),
            'canreact' => (bool) $decision->canreact,
            'emojis' => manager::get_emoji_set(),
            'compactview' => (bool) $decision->compactview,
            'pollinterval' => $decision->pollinterval,
            'selectors' => self::get_interactive_selectors($decision),
        ];
        return [[
            'local_reactions/reactions',
            'init',
            [$cfg],
        ]];
    }

    /**
     * CSS selectors and insertion points used by reactions.js on database activity pages.
     *
     * @param \stdClass $decision The object returned by resolve_for_page().
     * @return array
     */
    public static function get_interactive_selectors(\stdClass $decision): array {
        if ($decision->useanchors) {
            // The teacher placed the anchor, so the record ID is in the DOM and the bar goes
            // inside the anchor element itself.
            return [
                'item' => self::ANCHOR_SELECTOR,
                'itemIdAttr' => 'data-recordid',
                'appendToItem' => true,
            ];
        }
        if ($decision->issingle) {
            return [
                'item' => '#defaulttemplate-single',
                'itemIdOrder' => $decision->recordids,
                'appendFallbackSelectors' => ['.defaulttemplate-single-body'],
            ];
        }
        return [
            'item' => '.defaulttemplate-listentry',
            'itemIdOrder' => $decision->recordids,
            'appendFallbackSelectors' => ['.defaulttemplate-list-body'],
        ];
    }

    #[\Override]
    public function get_context_for_item(int $itemid): ?\context {
        $cm = $this->resolve_cm_from_record($itemid);
        return $cm ? \context_module::instance($cm->id) : null;
    }

    /**
     * Resolve the course module owning a given database entry.
     *
     * @param int $recordid Database activity entry ID ({data_records}.id).
     * @return \stdClass|null The course module record, or null if the entry does not exist.
     */
    private function resolve_cm_from_record(int $recordid): ?\stdClass {
        global $DB;
        $dataid = $DB->get_field('data_records', 'dataid', ['id' => $recordid], IGNORE_MISSING);
        if (!$dataid) {
            return null;
        }
        $cm = get_coursemodule_from_instance('data', $dataid, 0, false, IGNORE_MISSING);
        return $cm ?: null;
    }

    #[\Override]
    public function require_view_capability(\context $context): void {
        require_capability('local/reactions:view', $context);
        require_capability('mod/data:viewentry', $context);
    }

    #[\Override]
    public function require_react_capability(\context $context): void {
        require_capability('local/reactions:react', $context);
        require_capability('mod/data:viewentry', $context);
    }

    #[\Override]
    public function get_runtime_settings_for_item(int $itemid): ?\stdClass {
        if (!$this->is_globally_enabled()) {
            return null;
        }
        $cm = $this->resolve_cm_from_record($itemid);
        if (!$cm) {
            return null;
        }
        $config = manager::get_module_config($cm->id);
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
                  JOIN {data_records} dr ON dr.dataid = cm.instance
                  JOIN {local_reactions} lr ON lr.component = :component
                                           AND lr.itemtype = :itemtype
                                           AND lr.itemid = dr.id
                 WHERE lr.userid = :userid
                   AND m.name = :modulename";
        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'component' => $this->get_component(),
            'itemtype' => $this->get_itemtype(),
            'userid' => $userid,
            'modulename' => 'data',
        ];
        return [$sql, $params];
    }

    #[\Override]
    public function get_privacy_users_sql(\context $context): ?array {
        $dataid = $this->resolve_data_id_from_module_context($context);
        if ($dataid === null) {
            return null;
        }
        $sql = "SELECT lr.userid AS userid
                  FROM {data_records} dr
                  JOIN {local_reactions} lr ON lr.component = :component
                                           AND lr.itemtype = :itemtype
                                           AND lr.itemid = dr.id
                 WHERE dr.dataid = :dataid";
        $params = [
            'dataid' => $dataid,
            'component' => $this->get_component(),
            'itemtype' => $this->get_itemtype(),
        ];
        return [$sql, $params];
    }

    #[\Override]
    public function get_privacy_reaction_ids_sql(\context $context, ?int $userid, ?array $userids): ?array {
        global $DB;
        $dataid = $this->resolve_data_id_from_module_context($context);
        if ($dataid === null) {
            return null;
        }
        $params = [
            'dataid' => $dataid,
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
                  FROM {data_records} dr
                  JOIN {local_reactions} lr ON lr.component = :component
                                           AND lr.itemtype = :itemtype
                                           AND lr.itemid = dr.id
                 WHERE dr.dataid = :dataid"
                . $where;
        return [$sql, $params];
    }

    /**
     * Return the database activity instance ID for a CONTEXT_MODULE context that belongs to a
     * database activity, or null if the context is not a database activity module context.
     *
     * @param \context $context
     * @return int|null
     */
    private function resolve_data_id_from_module_context(\context $context): ?int {
        if (!$context instanceof \context_module) {
            return null;
        }
        $cm = get_coursemodule_from_id('data', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return null;
        }
        return (int) $cm->instance;
    }
}
