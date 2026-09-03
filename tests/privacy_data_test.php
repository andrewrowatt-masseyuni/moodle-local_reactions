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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_reactions\privacy\provider;

/**
 * Privacy tests covering reactions on database activity entries.
 *
 * @package    local_reactions
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reactions\privacy\provider
 * @covers     \local_reactions\provider\data_provider
 */
final class privacy_data_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass The database activity instance. */
    private \stdClass $data;

    /** @var \context_module The database activity context. */
    private \context_module $context;

    /** @var int The ID of the entry being reacted to. */
    private int $recordid;

    /** @var \stdClass First reacting user. */
    private \stdClass $userone;

    /** @var \stdClass Second reacting user. */
    private \stdClass $usertwo;

    /**
     * Build a database activity with one entry that two users have reacted to.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->userone = $generator->create_and_enrol($course, 'student');
        $this->usertwo = $generator->create_and_enrol($course, 'student');

        /** @var \mod_data_generator $datagenerator */
        $datagenerator = $generator->get_plugin_generator('mod_data');
        $this->data = $datagenerator->create_instance(['course' => $course->id]);
        $field = $datagenerator->create_field(
            (object) ['name' => 'Title', 'type' => 'text', 'description' => 'Title'],
            $this->data
        );

        $this->setUser($this->userone);
        $this->recordid = (int) $datagenerator->create_entry(
            $this->data,
            [$field->field->id => 'An entry']
        );

        $cm = get_coursemodule_from_instance('data', $this->data->id, 0, false, MUST_EXIST);
        $this->context = \context_module::instance($cm->id);

        $reactions = $generator->get_plugin_generator('local_reactions');
        foreach ([$this->userone, $this->usertwo] as $user) {
            $reactions->create_reaction([
                'component' => manager::COMPONENT_DATA,
                'itemtype' => manager::ITEMTYPE_RECORD,
                'itemid' => $this->recordid,
                'userid' => $user->id,
                'emoji' => 'thumbsup',
            ]);
        }
    }

    /**
     * Count the reactions currently stored against the test entry.
     *
     * @return int
     */
    private function count_reactions(): int {
        global $DB;
        return $DB->count_records('local_reactions', [
            'component' => manager::COMPONENT_DATA,
            'itemtype' => manager::ITEMTYPE_RECORD,
            'itemid' => $this->recordid,
        ]);
    }

    /**
     * A user's reactions put the owning database activity in their context list.
     */
    public function test_context_list_includes_the_activity(): void {
        $contextlist = provider::get_contexts_for_userid((int) $this->userone->id);
        // The DB layer hands back numeric columns as strings, so normalise before comparing.
        $this->assertContains((int) $this->context->id, array_map('intval', $contextlist->get_contextids()));
    }

    /**
     * Everyone who reacted in the activity is reported for that context.
     */
    public function test_users_in_context_lists_every_reactor(): void {
        $userlist = new userlist($this->context, 'local_reactions');
        provider::get_users_in_context($userlist);

        $userids = $userlist->get_userids();
        $this->assertContains((int) $this->userone->id, $userids);
        $this->assertContains((int) $this->usertwo->id, $userids);
    }

    /**
     * Exporting a user writes their database activity reactions under the activity's context.
     */
    public function test_export_writes_the_users_reactions(): void {
        $contextlist = new approved_contextlist($this->userone, 'local_reactions', [$this->context->id]);
        provider::export_user_data($contextlist);

        $exported = writer::with_context($this->context)->get_data([get_string('pluginname', 'local_reactions')]);
        $this->assertCount(1, $exported->reactions);
        $this->assertSame('thumbsup', $exported->reactions[0]->emoji);
        $this->assertSame(manager::COMPONENT_DATA, $exported->reactions[0]->component);
    }

    /**
     * Deleting one user's data leaves the other user's reactions in place.
     */
    public function test_delete_for_one_user_leaves_the_others(): void {
        $contextlist = new approved_contextlist($this->userone, 'local_reactions', [$this->context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertSame(1, $this->count_reactions());
    }

    /**
     * Deleting an approved set of users removes exactly those users' reactions.
     */
    public function test_delete_for_a_user_list(): void {
        $userlist = new approved_userlist($this->context, 'local_reactions', [(int) $this->usertwo->id]);
        provider::delete_data_for_users($userlist);

        $this->assertSame(1, $this->count_reactions());
    }

    /**
     * Deleting the whole activity context removes every reaction in it.
     */
    public function test_delete_for_all_users_in_context(): void {
        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertSame(0, $this->count_reactions());
    }
}
