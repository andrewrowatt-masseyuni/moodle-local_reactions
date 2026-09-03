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

use local_reactions\provider\data_provider;

/**
 * Tests for the database activity content provider.
 *
 * @package    local_reactions
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reactions\provider\data_provider
 */
final class data_provider_test extends \advanced_testcase {
    /** @var \stdClass The database activity instance. */
    private \stdClass $data;

    /** @var \cm_info The database activity course module. */
    private \cm_info $cm;

    /** @var int[] The IDs of the created entries, in creation order. */
    private array $recordids = [];

    /** @var \stdClass The enrolled teacher. */
    private \stdClass $teacher;

    /**
     * Build a course with a database activity holding three entries, with reactions enabled.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->teacher = $generator->create_and_enrol($course, 'editingteacher');

        /** @var \mod_data_generator $datagenerator */
        $datagenerator = $generator->get_plugin_generator('mod_data');
        $this->data = $datagenerator->create_instance(['course' => $course->id]);
        $field = $datagenerator->create_field(
            (object) ['name' => 'Title', 'type' => 'text', 'description' => 'Title'],
            $this->data
        );

        $this->setUser($this->teacher);
        for ($i = 1; $i <= 3; $i++) {
            $this->recordids[] = (int) $datagenerator->create_entry(
                $this->data,
                [$field->field->id => "Entry $i"]
            );
        }

        $this->cm = get_fast_modinfo($course->id)->get_cm(
            get_coursemodule_from_instance('data', $this->data->id)->id
        );

        set_config('enableddata', 1, 'local_reactions');
        $this->set_activity_enabled(1);
    }

    /**
     * Drop the faked request parameters and the cached provider instances.
     */
    protected function tearDown(): void {
        $_GET = [];
        provider_registry::reset_cache();
        parent::tearDown();
    }

    /**
     * Write the per-activity reactions settings row for the test activity.
     *
     * @param int $enabled 1 to enable reactions on the activity.
     */
    private function set_activity_enabled(int $enabled): void {
        global $DB;
        $DB->delete_records('local_reactions_enabled', ['cmid' => $this->cm->id]);
        $DB->insert_record('local_reactions_enabled', (object) [
            'cmid' => $this->cm->id,
            'enabled' => $enabled,
            'compactview_list' => 0,
            'compactview_discuss' => 0,
            'allowmultiplereactions' => 1,
            'onlypeerreactionsgrading' => 1,
        ]);
        manager::clear_module_config_cache($this->cm->id);
    }

    /**
     * Build a page set up the way mod/data/view.php leaves it, with the given URL parameters.
     *
     * @param array $params The view.php request parameters.
     * @return \moodle_page
     */
    private function make_page(array $params): \moodle_page {
        $_GET = $params;
        $page = new \moodle_page();
        $page->set_cm($this->cm);
        $page->set_url('/mod/data/view.php', $params);
        $page->set_pagetype('mod-data-view');
        return $page;
    }

    /**
     * The list view hands the AMD module every visible entry, in the order they were rendered.
     */
    public function test_list_view_resolves_every_visible_entry_in_order(): void {
        $provider = new data_provider();
        $decision = $provider->resolve_for_page($this->make_page(['d' => $this->data->id]));

        $this->assertNotNull($decision);
        $this->assertFalse($decision->issingle);
        $this->assertFalse($decision->useanchors);
        $this->assertSame($this->recordids, $decision->recordids);

        $selectors = data_provider::get_interactive_selectors($decision);
        $this->assertSame('.defaulttemplate-listentry', $selectors['item']);
        $this->assertSame($this->recordids, $selectors['itemIdOrder']);
        $this->assertSame(['.defaulttemplate-list-body'], $selectors['appendFallbackSelectors']);
    }

    /**
     * Opening one entry by record ID resolves that entry alone, with the single view selectors.
     */
    public function test_single_view_by_record_id_resolves_that_entry_only(): void {
        $provider = new data_provider();
        $decision = $provider->resolve_for_page($this->make_page([
            'd' => $this->data->id,
            'rid' => $this->recordids[1],
        ]));

        $this->assertNotNull($decision);
        $this->assertTrue($decision->issingle);
        $this->assertSame([$this->recordids[1]], $decision->recordids);

        $selectors = data_provider::get_interactive_selectors($decision);
        $this->assertSame('#defaulttemplate-single', $selectors['item']);
        $this->assertSame(['.defaulttemplate-single-body'], $selectors['appendFallbackSelectors']);
    }

    /**
     * Paging through the single view resolves the one entry shown on the requested page.
     */
    public function test_single_view_paging_resolves_the_entry_on_that_page(): void {
        $provider = new data_provider();
        $decision = $provider->resolve_for_page($this->make_page([
            'd' => $this->data->id,
            'mode' => 'single',
            'page' => 2,
        ]));

        $this->assertNotNull($decision);
        $this->assertTrue($decision->issingle);
        $this->assertSame([$this->recordids[2]], $decision->recordids);
    }

    /**
     * A template carrying the anchor switches to DOM-supplied IDs and skips the entry search.
     */
    public function test_anchor_template_skips_the_entry_search(): void {
        global $DB;

        $DB->set_field(
            'data',
            'listtemplate',
            '<div class="e">[[Title]]'
            . '<div data-region="local-reactions-anchor" data-recordid="##id##"></div></div>',
            ['id' => $this->data->id]
        );

        $provider = new data_provider();
        $decision = $provider->resolve_for_page($this->make_page(['d' => $this->data->id]));

        $this->assertNotNull($decision);
        $this->assertTrue($decision->useanchors);
        $this->assertSame([], $decision->recordids);

        $selectors = data_provider::get_interactive_selectors($decision);
        $this->assertSame('[data-region="local-reactions-anchor"]', $selectors['item']);
        $this->assertSame('data-recordid', $selectors['itemIdAttr']);
        $this->assertTrue($selectors['appendToItem']);
    }

    /**
     * The documented anchor markup really does render each entry's record ID into the page.
     */
    public function test_anchor_template_renders_the_record_id_into_the_anchor(): void {
        global $DB;

        $DB->set_field(
            'data',
            'listtemplate',
            '<div class="e">[[Title]]'
            . '<div data-region="local-reactions-anchor" data-recordid="##id##"></div></div>',
            ['id' => $this->data->id]
        );

        $cmrecord = get_coursemodule_from_instance('data', $this->data->id, 0, false, MUST_EXIST);
        $manager = \mod_data\manager::create_from_coursemodule($cmrecord);
        $parser = $manager->get_template('listtemplate', [
            'baseurl' => new \moodle_url('/mod/data/view.php', ['d' => $this->data->id]),
        ]);
        $html = $parser->parse_entries($DB->get_records('data_records', ['dataid' => $this->data->id], 'id'));

        preg_match_all('/data-recordid="(\d+)"/', $html, $matches);
        $this->assertSame(array_map('strval', $this->recordids), $matches[1]);
    }

    /**
     * A customised template with neither an anchor nor the default wrapper turns reactions off.
     */
    public function test_customised_template_without_an_anchor_is_left_alone(): void {
        global $DB;

        $DB->set_field('data', 'listtemplate', '<div class="mine">[[Title]]</div>', ['id' => $this->data->id]);

        $provider = new data_provider();
        $this->assertNull($provider->resolve_for_page($this->make_page(['d' => $this->data->id])));
    }

    /**
     * A customised template that keeps the default wrapper still gets positional reactions.
     */
    public function test_customised_template_keeping_the_default_wrapper_still_works(): void {
        global $DB;

        $DB->set_field(
            'data',
            'listtemplate',
            '<div class="defaulttemplate-listentry"><div class="defaulttemplate-list-body">[[Title]]</div></div>',
            ['id' => $this->data->id]
        );

        $provider = new data_provider();
        $decision = $provider->resolve_for_page($this->make_page(['d' => $this->data->id]));

        $this->assertNotNull($decision);
        $this->assertFalse($decision->useanchors);
        $this->assertSame($this->recordids, $decision->recordids);
    }

    /**
     * Reactions stay off when the activity's own toggle is off.
     */
    public function test_no_decision_when_the_activity_toggle_is_off(): void {
        $this->set_activity_enabled(0);

        $provider = new data_provider();
        $this->assertNull($provider->resolve_for_page($this->make_page(['d' => $this->data->id])));
    }

    /**
     * Reactions stay off when the site-wide database activity setting is off.
     */
    public function test_no_decision_when_the_site_setting_is_off(): void {
        set_config('enableddata', 0, 'local_reactions');

        $provider = new data_provider();
        $this->assertNull($provider->resolve_for_page($this->make_page(['d' => $this->data->id])));
    }

    /**
     * The advanced search form and the delete confirmation never get reactions.
     */
    public function test_no_decision_on_the_advanced_search_and_delete_screens(): void {
        $provider = new data_provider();
        $this->assertNull($provider->resolve_for_page($this->make_page([
            'd' => $this->data->id,
            'mode' => 'asearch',
        ])));

        $provider = new data_provider();
        $this->assertNull($provider->resolve_for_page($this->make_page([
            'd' => $this->data->id,
            'delete' => $this->recordids[0],
        ])));
    }

    /**
     * Both output hooks on one page share a single resolved decision, so the entry search runs once.
     */
    public function test_the_decision_is_computed_only_once_per_page(): void {
        $provider = new data_provider();
        $page = $this->make_page(['d' => $this->data->id]);

        $first = $provider->resolve_for_page($page);
        $second = $provider->resolve_for_page($page);
        $this->assertSame($first, $second);
    }

    /**
     * Web service lookups resolve an entry to its activity context and settings, and reject unknown IDs.
     */
    public function test_item_lookups_resolve_the_owning_activity(): void {
        $provider = new data_provider();

        $context = $provider->get_context_for_item($this->recordids[0]);
        $this->assertInstanceOf(\context_module::class, $context);
        $this->assertSame((int) $this->cm->id, (int) $context->instanceid);

        $settings = $provider->get_runtime_settings_for_item($this->recordids[0]);
        $this->assertTrue($settings->enabled);
        $this->assertTrue($settings->allowmultiple);

        $this->assertNull($provider->get_context_for_item(-1));
        $this->assertNull($provider->get_runtime_settings_for_item(-1));
    }

    /**
     * The provider registry routes mod_data/record reactions to this provider.
     */
    public function test_the_registry_dispatches_mod_data_reactions_to_this_provider(): void {
        provider_registry::reset_cache();
        $provider = provider_registry::get_for_component_itemtype(
            manager::COMPONENT_DATA,
            manager::ITEMTYPE_RECORD
        );
        $this->assertInstanceOf(data_provider::class, $provider);
    }

    /**
     * Deleting an entry cleans up the reactions that pointed at it.
     */
    public function test_deleting_an_entry_removes_its_reactions(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/data/lib.php');

        $generator = $this->getDataGenerator()->get_plugin_generator('local_reactions');
        $generator->create_reaction([
            'component' => manager::COMPONENT_DATA,
            'itemtype' => manager::ITEMTYPE_RECORD,
            'itemid' => $this->recordids[0],
            'userid' => $this->teacher->id,
            'emoji' => 'thumbsup',
        ]);
        $this->assertTrue(manager::data_has_reactions($this->data->id));

        data_delete_record($this->recordids[0], $this->data, $this->cm->course, $this->cm->id);

        $this->assertFalse($DB->record_exists('local_reactions', [
            'component' => manager::COMPONENT_DATA,
            'itemtype' => manager::ITEMTYPE_RECORD,
            'itemid' => $this->recordids[0],
        ]));
        $this->assertFalse(manager::data_has_reactions($this->data->id));
    }
}
