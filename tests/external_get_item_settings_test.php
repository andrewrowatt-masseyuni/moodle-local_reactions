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

use core_external\external_api;
use local_reactions\external\get_item_settings;

/**
 * Tests for the get_item_settings external function used by the Moodle App.
 *
 * @package    local_reactions
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reactions\external\get_item_settings
 */
final class external_get_item_settings_test extends \advanced_testcase {
    /** @var \stdClass Course used by every test. */
    private \stdClass $course;

    /** @var \stdClass Forum with reactions turned on. */
    private \stdClass $forum;

    /** @var \stdClass Course module record for the forum. */
    private \stdClass $cm;

    /** @var int ID of a post in the test forum. */
    private int $postid;

    /**
     * Create a course with one forum that has reactions enabled.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('enabled', 1, 'local_reactions');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->forum = $generator->create_module('forum', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('forum', $this->forum->id, $this->course->id, false, MUST_EXIST);

        $this->set_module_config(['enabled' => 1]);

        // The function resolves everything from an item ID, so the fixture needs a real post.
        $author = $generator->create_and_enrol($this->course, 'student');
        $discussion = $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $this->course->id,
            'forum' => $this->forum->id,
            'userid' => $author->id,
        ]);
        $this->postid = (int) $discussion->firstpost;
    }

    /**
     * Write the per-activity reactions configuration for the test forum.
     *
     * @param array $overrides Fields to override on the defaults.
     */
    private function set_module_config(array $overrides): void {
        global $DB;

        $DB->delete_records('local_reactions_enabled', ['cmid' => $this->cm->id]);
        $DB->insert_record('local_reactions_enabled', (object) array_merge([
            'cmid' => $this->cm->id,
            'enabled' => 1,
            'compactview_list' => 0,
            'compactview_discuss' => 0,
            'allowmultiplereactions' => 1,
            'onlypeerreactionsgrading' => 1,
        ], $overrides));
        manager::clear_module_config_cache();
    }

    /**
     * Call the external function and run the result through the return description.
     *
     * @param array $itemids
     * @return array
     */
    private function call(array $itemids): array {
        return external_api::clean_returnvalue(
            get_item_settings::execute_returns(),
            get_item_settings::execute('mod_forum', 'post', $itemids)
        );
    }

    /**
     * A student in a reactions-enabled forum gets the full configuration plus the emoji set.
     */
    public function test_enabled_forum_returns_settings_and_emojis(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $result = $this->call([$this->postid]);

        $this->assertCount(1, $result['settings']);
        $settings = $result['settings'][0];
        $this->assertSame($this->postid, $settings['itemid']);
        $this->assertSame((int) $this->cm->id, $settings['cmid']);
        $this->assertSame(\context_module::instance($this->cm->id)->id, $settings['contextid']);
        $this->assertTrue($settings['enabled']);
        $this->assertTrue($settings['canreact']);
        $this->assertFalse($settings['compactview']);
        $this->assertTrue($settings['allowmultiplereactions']);
        $this->assertEmpty($result['warnings']);

        // The emoji set is what the app renders pills from, so it must come back keyed and ordered.
        $this->assertSame(
            array_keys(manager::get_emoji_set()),
            array_column($result['emojis'], 'shortcode')
        );
    }

    /**
     * compactview reports the single-item setting, which is the only view the app renders.
     */
    public function test_compactview_follows_the_discussion_setting(): void {
        $this->set_module_config(['enabled' => 1, 'compactview_list' => 1, 'compactview_discuss' => 1]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));

        $result = $this->call([$this->postid]);

        $this->assertTrue($result['settings'][0]['compactview']);
    }

    /**
     * Turning reactions off for the activity, or site-wide, reports the module as disabled.
     *
     * @param bool $siteenabled
     * @param int $moduleenabled
     * @dataProvider disabled_provider
     */
    public function test_disabled_reports_enabled_false(bool $siteenabled, int $moduleenabled): void {
        set_config('enabled', $siteenabled ? 1 : 0, 'local_reactions');
        $this->set_module_config(['enabled' => $moduleenabled]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));

        $result = $this->call([$this->postid]);

        $this->assertFalse($result['settings'][0]['enabled']);
        $this->assertFalse($result['settings'][0]['canreact']);
    }

    /**
     * Data provider for test_disabled_reports_enabled_false.
     *
     * @return array
     */
    public static function disabled_provider(): array {
        return [
            'site setting off' => [false, 1],
            'activity setting off' => [true, 0],
            'both off' => [false, 0],
        ];
    }

    /**
     * A user without local/reactions:react sees reactions but cannot add any.
     */
    public function test_canreact_false_without_capability(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $studentrole = $this->getDataGenerator()->create_role();
        role_assign($studentrole, $student->id, \context_module::instance($this->cm->id));
        assign_capability(
            'local/reactions:react',
            CAP_PROHIBIT,
            $studentrole,
            \context_module::instance($this->cm->id)
        );

        $result = $this->call([$this->postid]);

        $this->assertTrue($result['settings'][0]['enabled']);
        $this->assertFalse($result['settings'][0]['canreact']);
    }

    /**
     * Item IDs that cannot be resolved are reported as warnings, so one bad ID does not fail the
     * whole batch.
     */
    public function test_unresolvable_items_become_warnings(): void {
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));

        $result = $this->call([$this->postid, -1, 999999]);

        $this->assertSame([$this->postid], array_column($result['settings'], 'itemid'));
        $this->assertSame(['itemnotfound', 'itemnotfound'], array_column($result['warnings'], 'warningcode'));
    }

    /**
     * An unsupported component/itemtype pair is a caller error, not a warning.
     */
    public function test_unsupported_component_throws(): void {
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));

        $this->expectException(\invalid_parameter_exception::class);
        get_item_settings::execute('mod_nothing', 'widget', [$this->postid]);
    }

    /**
     * Repeated item IDs are collapsed, so the app can pass whatever it has on screen.
     */
    public function test_duplicate_itemids_are_returned_once(): void {
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));

        $result = $this->call([$this->postid, $this->postid, $this->postid]);

        $this->assertCount(1, $result['settings']);
    }
}
