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

/**
 * Tests for the "Show who reacted" name lists shown in reaction pill tooltips.
 *
 * @package    local_reactions
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_reactions\reactor_names
 */
final class reactor_names_test extends \advanced_testcase {
    /** @var \stdClass The course the forum lives in. */
    private \stdClass $course;

    /** @var \stdClass The forum activity. */
    private \stdClass $forum;

    /** @var \stdClass The forum's course module. */
    private \stdClass $cm;

    /** @var \context_module The forum's module context. */
    private \context_module $context;

    /** @var \stdClass The first post of the discussion. */
    private \stdClass $post;

    /** @var \stdClass The second post of the discussion. */
    private \stdClass $secondpost;

    /** @var \stdClass The discussion holding both posts. */
    private \stdClass $discussion;

    /**
     * Build a forum with one discussion of two posts, authored by a teacher.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        manager::clear_module_config_cache();

        $this->course = $this->getDataGenerator()->create_course();
        $this->forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('forum', $this->forum->id);
        $this->context = \context_module::instance($this->cm->id);

        $author = $this->enrol('Author', 'editingteacher');
        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $this->discussion = $forumgenerator->create_discussion([
            'course' => $this->course->id,
            'forum' => $this->forum->id,
            'userid' => $author->id,
        ]);
        $this->post = $DB->get_record('forum_posts', ['discussion' => $this->discussion->id], '*', MUST_EXIST);
        $this->secondpost = $forumgenerator->create_post([
            'discussion' => $this->discussion->id,
            'userid' => $author->id,
        ]);
    }

    /**
     * Create a user with a known first name and enrol them in the course.
     *
     * @param string $firstname The user's first name, which is what tooltips show.
     * @param string $rolename Role shortname to enrol them with.
     * @return \stdClass The created user.
     */
    private function enrol(string $firstname, string $rolename = 'student'): \stdClass {
        $user = $this->getDataGenerator()->create_user(['firstname' => $firstname, 'lastname' => 'Test']);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, $rolename);
        return $user;
    }

    /**
     * Write the per-activity reactions settings for the forum under test.
     *
     * @param array $overrides Columns to override on the {local_reactions_enabled} row.
     */
    private function set_activity_settings(array $overrides = []): void {
        $this->getDataGenerator()->get_plugin_generator('local_reactions')->create_enabled_forum(
            $overrides + ['cmid' => $this->cm->id, 'enabled' => 1, 'shownames' => 1]
        );
        manager::clear_module_config_cache();
    }

    /**
     * Add a reaction at a controlled time, so tests can pin the "most recent first" order.
     *
     * @param int $userid Who reacted.
     * @param string $emoji Emoji shortcode.
     * @param int $timecreated When they reacted.
     * @param int|null $itemid Post ID; defaults to the discussion's first post.
     */
    private function react(int $userid, string $emoji, int $timecreated, ?int $itemid = null): void {
        $this->getDataGenerator()->get_plugin_generator('local_reactions')->create_reaction([
            'itemid' => $itemid ?? $this->post->id,
            'userid' => $userid,
            'emoji' => $emoji,
            'timecreated' => $timecreated,
        ]);
    }

    /**
     * Fetch the tooltip text for one emoji on the discussion's first post.
     *
     * @param string $emoji Emoji shortcode.
     * @return string The tooltip text, or '' when there is none.
     */
    private function names_for(string $emoji): string {
        $names = reactor_names::for_items(
            manager::COMPONENT_FORUM,
            manager::ITEMTYPE_POST,
            [$this->post->id],
            $this->get_viewer_id(),
            $this->context
        );
        return $names[$this->post->id]['emoji'][$emoji] ?? '';
    }

    /**
     * The currently logged-in user's ID, which is the viewer the name lists are built for.
     *
     * @return int
     */
    private function get_viewer_id(): int {
        global $USER;
        return (int) $USER->id;
    }

    /**
     * With the setting off, no names are produced at all, whatever the viewer's role.
     */
    public function test_names_are_empty_when_setting_is_off(): void {
        $this->set_activity_settings(['shownames' => 0]);
        $reactor = $this->enrol('Andrew');
        $this->react($reactor->id, 'thumbsup', 1000);

        $this->setUser($this->enrol('Viewer'));

        $this->assertSame([], reactor_names::for_items(
            manager::COMPONENT_FORUM,
            manager::ITEMTYPE_POST,
            [$this->post->id],
            $this->get_viewer_id(),
            $this->context
        ));
    }

    /**
     * A student sees three other names at most, newest first, with the rest rolled into a tail.
     */
    public function test_student_sees_three_names_and_a_tail(): void {
        $this->set_activity_settings();
        foreach (['Oldest', 'Fourth', 'Third', 'Second', 'Newest'] as $index => $firstname) {
            $this->react($this->enrol($firstname)->id, 'thumbsup', 1000 + $index);
        }

        $this->setUser($this->enrol('Viewer'));

        $this->assertSame('Newest, Second, Third, and 2 others', $this->names_for('thumbsup'));
    }

    /**
     * The viewer's own reaction is named "You" at the front and never uses up one of the slots.
     */
    public function test_viewer_is_named_first_and_does_not_count_towards_the_limit(): void {
        $this->set_activity_settings();
        $viewer = $this->enrol('Viewer');
        foreach (['Oldest', 'Fourth', 'Third', 'Second', 'Newest'] as $index => $firstname) {
            $this->react($this->enrol($firstname)->id, 'thumbsup', 1000 + $index);
        }
        // The viewer reacted in the middle of the run; "You" still leads the list.
        $this->react($viewer->id, 'thumbsup', 1002);

        $this->setUser($viewer);

        $this->assertSame('You, Newest, Second, Third, and 2 others', $this->names_for('thumbsup'));
    }

    /**
     * Users who are not students are named with their role; students are named alone.
     */
    public function test_non_student_names_carry_their_role(): void {
        $this->set_activity_settings();
        $this->react($this->enrol('Jenny', 'editingteacher')->id, 'thumbsup', 2000);
        $this->react($this->enrol('Andrew')->id, 'thumbsup', 1000);

        $this->setUser($this->enrol('Viewer'));

        $this->assertSame('Jenny (Teacher), Andrew', $this->names_for('thumbsup'));
    }

    /**
     * A viewer holding local/reactions:viewallreactornames gets the site-wide allowance instead
     * of the three-name student cap.
     */
    public function test_capability_raises_the_limit_to_the_site_setting(): void {
        $this->set_activity_settings();
        set_config('shownameslimit', 4, 'local_reactions');
        foreach (['Oldest', 'Fourth', 'Third', 'Second', 'Newest'] as $index => $firstname) {
            $this->react($this->enrol($firstname)->id, 'thumbsup', 1000 + $index);
        }

        $this->setUser($this->enrol('Teacher', 'editingteacher'));

        $this->assertSame(
            'Newest, Second, Third, Fourth, and 1 other',
            $this->names_for('thumbsup')
        );
    }

    /**
     * Each emoji gets its own list, and the compact pill's list spans every emoji, naming each
     * person once at their most recent reaction.
     */
    public function test_per_emoji_and_combined_lists(): void {
        $this->set_activity_settings();
        $andrew = $this->enrol('Andrew');
        $bancy = $this->enrol('Bancy');
        $this->react($andrew->id, 'thumbsup', 1000);
        $this->react($andrew->id, 'heart', 3000);
        $this->react($bancy->id, 'heart', 2000);

        $this->setUser($this->enrol('Viewer'));

        $this->assertSame('Andrew', $this->names_for('thumbsup'));
        $this->assertSame('Andrew, Bancy', $this->names_for('heart'));

        $names = reactor_names::for_items(
            manager::COMPONENT_FORUM,
            manager::ITEMTYPE_POST,
            [$this->post->id],
            $this->get_viewer_id(),
            $this->context
        );
        $this->assertSame('Andrew, Bancy', $names[$this->post->id]['all']);
    }

    /**
     * Aggregating a discussion names someone once even when they reacted on several of its posts.
     */
    public function test_discussion_aggregation_names_each_person_once(): void {
        $this->set_activity_settings();
        $andrew = $this->enrol('Andrew');
        $bancy = $this->enrol('Bancy');
        $this->react($andrew->id, 'thumbsup', 1000, $this->post->id);
        $this->react($bancy->id, 'thumbsup', 2000, $this->post->id);
        $this->react($andrew->id, 'thumbsup', 3000, $this->secondpost->id);

        $this->setUser($this->enrol('Viewer'));

        $names = reactor_names::for_discussions(
            manager::COMPONENT_FORUM,
            manager::ITEMTYPE_POST,
            [$this->discussion->id],
            $this->get_viewer_id(),
            $this->context
        );

        $this->assertSame('Andrew, Bancy', $names[$this->discussion->id]['emoji']['thumbsup']);
    }

    /**
     * In peer-only grading mode the names drop self-reactions and non-students, matching the
     * counts the grading panel shows.
     */
    public function test_grading_names_honour_peer_only_filtering(): void {
        global $DB;
        $this->set_activity_settings(['onlypeerreactionsgrading' => 1]);

        // Re-author the post as a student so their own reaction counts as a self-reaction.
        $student = $this->enrol('Sam');
        $DB->set_field('forum_posts', 'userid', $student->id, ['id' => $this->post->id]);

        $this->react($student->id, 'thumbsup', 3000);
        $this->react($this->enrol('Jenny', 'editingteacher')->id, 'thumbsup', 2000);
        $this->react($this->enrol('Peer')->id, 'thumbsup', 1000);

        $this->setUser($this->enrol('Teacher', 'editingteacher'));

        $names = reactor_names::for_grading(
            manager::COMPONENT_FORUM,
            manager::ITEMTYPE_POST,
            [$this->post->id],
            $this->get_viewer_id(),
            $this->context
        );

        $this->assertSame('Peer', $names[$this->post->id]['emoji']['thumbsup']);
    }

    /**
     * Turning peer-only filtering off falls back to the unfiltered name list.
     */
    public function test_grading_names_without_peer_filtering(): void {
        $this->set_activity_settings(['onlypeerreactionsgrading' => 0]);
        $this->react($this->enrol('Jenny', 'editingteacher')->id, 'thumbsup', 2000);
        $this->react($this->enrol('Peer')->id, 'thumbsup', 1000);

        $this->setUser($this->enrol('Teacher', 'editingteacher'));

        $names = reactor_names::for_grading(
            manager::COMPONENT_FORUM,
            manager::ITEMTYPE_POST,
            [$this->post->id],
            $this->get_viewer_id(),
            $this->context
        );

        $this->assertSame('Jenny (Teacher), Peer', $names[$this->post->id]['emoji']['thumbsup']);
    }

    /**
     * A caller cannot pair one activity's context with another activity's item IDs to learn who
     * reacted somewhere they were never granted access to.
     */
    public function test_names_are_scoped_to_the_context_activity(): void {
        global $DB;
        $this->set_activity_settings();

        // A second forum, in the same course, whose post the caller has no business naming.
        $otherforum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $othercm = get_coursemodule_from_instance('forum', $otherforum->id);
        $this->getDataGenerator()->get_plugin_generator('local_reactions')->create_enabled_forum(
            ['cmid' => $othercm->id, 'enabled' => 1, 'shownames' => 1]
        );
        manager::clear_module_config_cache();

        $author = $this->enrol('Otherauthor', 'editingteacher');
        $otherdiscussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $this->course->id,
            'forum' => $otherforum->id,
            'userid' => $author->id,
        ]);
        $otherpost = $DB->get_record('forum_posts', ['discussion' => $otherdiscussion->id], '*', MUST_EXIST);
        $this->react($this->enrol('Secret')->id, 'thumbsup', 1000, $otherpost->id);

        $this->setUser($this->enrol('Viewer'));

        // The first forum's context must not unlock the second forum's names.
        $this->assertSame([], reactor_names::for_items(
            manager::COMPONENT_FORUM,
            manager::ITEMTYPE_POST,
            [$otherpost->id],
            $this->get_viewer_id(),
            $this->context
        ));

        // The second forum's own context still resolves them.
        $names = reactor_names::for_items(
            manager::COMPONENT_FORUM,
            manager::ITEMTYPE_POST,
            [$otherpost->id],
            $this->get_viewer_id(),
            \context_module::instance($othercm->id)
        );
        $this->assertSame('Secret', $names[$otherpost->id]['emoji']['thumbsup']);
    }

    /**
     * The limit depends on the capability, and an unusable site setting falls back to the default.
     */
    public function test_names_limit_depends_on_capability(): void {
        $student = $this->enrol('Student');
        $teacher = $this->enrol('Teacher', 'editingteacher');

        $this->setUser($student);
        $this->assertSame(reactor_names::STUDENT_LIMIT, reactor_names::get_limit($this->context));

        $this->setUser($teacher);
        set_config('shownameslimit', 25, 'local_reactions');
        $this->assertSame(25, reactor_names::get_limit($this->context));

        set_config('shownameslimit', 0, 'local_reactions');
        $this->assertSame(reactor_names::DEFAULT_LIMIT, reactor_names::get_limit($this->context));
    }
}
