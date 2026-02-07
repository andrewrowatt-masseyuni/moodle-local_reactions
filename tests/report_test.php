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
 * Tests for Reactions report
 *
 * @package    local_reactions
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_reactions\report
 */
final class report_test extends \advanced_testcase {
    /**
     * Test reactions vs posts ratio calculation.
     */
    public function test_get_reactions_vs_posts_ratio(): void {
        global $DB;
        $this->resetAfterTest();

        // Create course, forum, and enable reactions.
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        
        // Enable reactions for this forum.
        $DB->insert_record('local_reactions_enabled', (object) [
            'cmid' => $cm->id,
            'enabled' => 1,
        ]);

        // Create users.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        // Create discussion and posts.
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')
            ->create_discussion(['course' => $course->id, 'forum' => $forum->id, 'userid' => $user1->id]);
        
        $post1 = $DB->get_record('forum_posts', ['discussion' => $discussion->id]);
        $post2 = $this->getDataGenerator()->get_plugin_generator('mod_forum')
            ->create_post(['discussion' => $discussion->id, 'userid' => $user2->id]);

        // Add reactions.
        $DB->insert_record('local_reactions', (object) [
            'component' => 'mod_forum',
            'itemtype' => 'post',
            'itemid' => $post1->id,
            'userid' => $user2->id,
            'emoji' => 'thumbsup',
            'timecreated' => time(),
        ]);
        
        $DB->insert_record('local_reactions', (object) [
            'component' => 'mod_forum',
            'itemtype' => 'post',
            'itemid' => $post1->id,
            'userid' => $user2->id,
            'emoji' => 'heart',
            'timecreated' => time(),
        ]);

        // Test report.
        $report = new report($course->id);
        $ratio = $report->get_reactions_vs_posts_ratio();

        $this->assertEquals(2, $ratio['total_reactions']);
        $this->assertEquals(2, $ratio['total_posts']);
        $this->assertEquals(1.0, $ratio['ratio']);
    }

    /**
     * Test active participants calculation.
     */
    public function test_get_active_participants(): void {
        global $DB;
        $this->resetAfterTest();

        // Create course, forum, and enable reactions.
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        
        $DB->insert_record('local_reactions_enabled', (object) [
            'cmid' => $cm->id,
            'enabled' => 1,
        ]);

        // Create users.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        // Create discussion and posts.
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')
            ->create_discussion(['course' => $course->id, 'forum' => $forum->id, 'userid' => $user1->id]);
        
        $post1 = $DB->get_record('forum_posts', ['discussion' => $discussion->id]);
        $post2 = $this->getDataGenerator()->get_plugin_generator('mod_forum')
            ->create_post(['discussion' => $discussion->id, 'userid' => $user2->id]);

        // Add reactions (user3 reacts but doesn't post).
        $DB->insert_record('local_reactions', (object) [
            'component' => 'mod_forum',
            'itemtype' => 'post',
            'itemid' => $post1->id,
            'userid' => $user3->id,
            'emoji' => 'thumbsup',
            'timecreated' => time(),
        ]);

        // Test report.
        $report = new report($course->id);
        $participants = $report->get_active_participants();

        $this->assertEquals(1, $participants['active_reactors']);
        $this->assertEquals(2, $participants['active_posters']);
    }

    /**
     * Test posts with zero reactions.
     */
    public function test_get_posts_with_zero_reactions(): void {
        global $DB;
        $this->resetAfterTest();

        // Create course, forum, and enable reactions.
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        
        $DB->insert_record('local_reactions_enabled', (object) [
            'cmid' => $cm->id,
            'enabled' => 1,
        ]);

        // Create users.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        // Create discussion and posts.
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')
            ->create_discussion(['course' => $course->id, 'forum' => $forum->id, 'userid' => $user1->id]);
        
        $post1 = $DB->get_record('forum_posts', ['discussion' => $discussion->id]);
        $post2 = $this->getDataGenerator()->get_plugin_generator('mod_forum')
            ->create_post(['discussion' => $discussion->id, 'userid' => $user2->id]);

        // Add reaction to only one post.
        $DB->insert_record('local_reactions', (object) [
            'component' => 'mod_forum',
            'itemtype' => 'post',
            'itemid' => $post1->id,
            'userid' => $user2->id,
            'emoji' => 'thumbsup',
            'timecreated' => time(),
        ]);

        // Test report.
        $report = new report($course->id);
        $posts = $report->get_posts_with_zero_reactions();

        $this->assertCount(1, $posts);
        $this->assertEquals($post2->id, $posts[0]->id);
    }

    /**
     * Test most-reacted posts this week.
     */
    public function test_get_most_reacted_posts_this_week(): void {
        global $DB;
        $this->resetAfterTest();

        // Create course, forum, and enable reactions.
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        
        $DB->insert_record('local_reactions_enabled', (object) [
            'cmid' => $cm->id,
            'enabled' => 1,
        ]);

        // Create users.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        // Create discussion and posts.
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')
            ->create_discussion(['course' => $course->id, 'forum' => $forum->id, 'userid' => $user1->id]);
        
        $post1 = $DB->get_record('forum_posts', ['discussion' => $discussion->id]);
        $post2 = $this->getDataGenerator()->get_plugin_generator('mod_forum')
            ->create_post(['discussion' => $discussion->id, 'userid' => $user2->id]);

        // Add reactions to post1 (3 reactions) in the past week.
        $thisweek = time() - (2 * 24 * 60 * 60); // 2 days ago.
        $DB->insert_record('local_reactions', (object) [
            'component' => 'mod_forum',
            'itemtype' => 'post',
            'itemid' => $post1->id,
            'userid' => $user2->id,
            'emoji' => 'thumbsup',
            'timecreated' => $thisweek,
        ]);
        
        $DB->insert_record('local_reactions', (object) [
            'component' => 'mod_forum',
            'itemtype' => 'post',
            'itemid' => $post1->id,
            'userid' => $user2->id,
            'emoji' => 'heart',
            'timecreated' => $thisweek,
        ]);
        
        $DB->insert_record('local_reactions', (object) [
            'component' => 'mod_forum',
            'itemtype' => 'post',
            'itemid' => $post1->id,
            'userid' => $user1->id,
            'emoji' => 'thumbsup',
            'timecreated' => $thisweek,
        ]);

        // Add reaction to post2 (1 reaction) but older than a week.
        $oldtime = time() - (10 * 24 * 60 * 60); // 10 days ago.
        $DB->insert_record('local_reactions', (object) [
            'component' => 'mod_forum',
            'itemtype' => 'post',
            'itemid' => $post2->id,
            'userid' => $user1->id,
            'emoji' => 'thumbsup',
            'timecreated' => $oldtime,
        ]);

        // Test report.
        $report = new report($course->id);
        $posts = $report->get_most_reacted_posts_this_week();

        // Should only return post1 with 3 reactions from this week.
        $this->assertCount(1, $posts);
        $this->assertEquals($post1->id, $posts[0]->id);
        $this->assertEquals(3, $posts[0]->reactioncount);
    }

    /**
     * Test report with no enabled forums.
     */
    public function test_report_with_no_enabled_forums(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $report = new report($course->id);

        // All methods should return empty/zero values.
        $ratio = $report->get_reactions_vs_posts_ratio();
        $this->assertEquals(0, $ratio['total_reactions']);
        $this->assertEquals(0, $ratio['total_posts']);
        $this->assertEquals(0, $ratio['ratio']);

        $participants = $report->get_active_participants();
        $this->assertEquals(0, $participants['active_reactors']);
        $this->assertEquals(0, $participants['active_posters']);

        $posts = $report->get_posts_with_zero_reactions();
        $this->assertEmpty($posts);

        $mostreacted = $report->get_most_reacted_posts_this_week();
        $this->assertEmpty($mostreacted);
    }
}
