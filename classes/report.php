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
 * Report class for reactions statistics.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /** @var int Course ID */
    private $courseid;

    /** @var array Forum IDs with reactions enabled */
    private $forumids;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     */
    public function __construct(int $courseid) {
        $this->courseid = $courseid;
        $this->forumids = $this->get_enabled_forums();
    }

    /**
     * Get all forum IDs in this course that have reactions enabled.
     *
     * @return array Array of forum instance IDs
     */
    private function get_enabled_forums(): array {
        global $DB;

        $sql = "SELECT f.id
                  FROM {forum} f
                  JOIN {course_modules} cm ON cm.instance = f.id
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {local_reactions_enabled} re ON re.cmid = cm.id
                 WHERE f.course = :courseid
                   AND m.name = 'forum'
                   AND re.enabled = 1";

        $records = $DB->get_records_sql($sql, ['courseid' => $this->courseid]);
        return array_keys($records);
    }

    /**
     * Get total reactions vs posts ratio.
     *
     * @return array ['total_reactions' => int, 'total_posts' => int, 'ratio' => float]
     */
    public function get_reactions_vs_posts_ratio(): array {
        global $DB;

        if (empty($this->forumids)) {
            return ['total_reactions' => 0, 'total_posts' => 0, 'ratio' => 0];
        }

        [$insql, $params] = $DB->get_in_or_equal($this->forumids, SQL_PARAMS_NAMED);

        // Get total posts.
        $sql = "SELECT COUNT(DISTINCT p.id) as total
                  FROM {forum_posts} p
                  JOIN {forum_discussions} d ON d.id = p.discussion
                 WHERE d.forum $insql";

        $totalposts = $DB->count_records_sql($sql, $params);

        // Get total reactions.
        $sql = "SELECT COUNT(*) as total
                  FROM {local_reactions} r
                  JOIN {forum_posts} p ON p.id = r.itemid
                  JOIN {forum_discussions} d ON d.id = p.discussion
                 WHERE r.component = 'mod_forum'
                   AND r.itemtype = 'post'
                   AND d.forum $insql";

        $totalreactions = $DB->count_records_sql($sql, $params);

        $ratio = $totalposts > 0 ? $totalreactions / $totalposts : 0;

        return [
            'total_reactions' => $totalreactions,
            'total_posts' => $totalposts,
            'ratio' => round($ratio, 2),
        ];
    }

    /**
     * Get active reactors vs active posters.
     *
     * @return array ['active_reactors' => int, 'active_posters' => int]
     */
    public function get_active_participants(): array {
        global $DB;

        if (empty($this->forumids)) {
            return ['active_reactors' => 0, 'active_posters' => 0];
        }

        [$insql, $params] = $DB->get_in_or_equal($this->forumids, SQL_PARAMS_NAMED);

        // Get active reactors.
        $sql = "SELECT COUNT(DISTINCT r.userid) as total
                  FROM {local_reactions} r
                  JOIN {forum_posts} p ON p.id = r.itemid
                  JOIN {forum_discussions} d ON d.id = p.discussion
                 WHERE r.component = 'mod_forum'
                   AND r.itemtype = 'post'
                   AND d.forum $insql";

        $activereactors = $DB->count_records_sql($sql, $params);

        // Get active posters.
        $sql = "SELECT COUNT(DISTINCT p.userid) as total
                  FROM {forum_posts} p
                  JOIN {forum_discussions} d ON d.id = p.discussion
                 WHERE d.forum $insql";

        $activeposters = $DB->count_records_sql($sql, $params);

        return [
            'active_reactors' => $activereactors,
            'active_posters' => $activeposters,
        ];
    }

    /**
     * Get posts with zero reactions.
     *
     * @param int $limit Maximum number of posts to return
     * @return array Array of post objects with discussion and forum info
     */
    public function get_posts_with_zero_reactions(int $limit = 20): array {
        global $DB;

        if (empty($this->forumids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($this->forumids, SQL_PARAMS_NAMED);

        $sql = "SELECT p.id, p.subject, p.message, p.created, p.userid,
                       d.id as discussionid, d.name as discussionname,
                       f.id as forumid, f.name as forumname,
                       cm.id as cmid
                  FROM {forum_posts} p
                  JOIN {forum_discussions} d ON d.id = p.discussion
                  JOIN {forum} f ON f.id = d.forum
                  JOIN {modules} m ON m.name = 'forum'
                  JOIN {course_modules} cm ON cm.instance = f.id AND cm.module = m.id
                 WHERE d.forum $insql
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {local_reactions} r
                        WHERE r.component = 'mod_forum'
                          AND r.itemtype = 'post'
                          AND r.itemid = p.id
                   )
              ORDER BY p.created DESC";

        return array_values($DB->get_records_sql($sql, $params, 0, $limit));
    }

    /**
     * Get most-reacted posts this week.
     *
     * @param int $limit Maximum number of posts to return
     * @return array Array of post objects with reaction counts
     */
    public function get_most_reacted_posts_this_week(int $limit = 10): array {
        global $DB;

        if (empty($this->forumids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($this->forumids, SQL_PARAMS_NAMED);

        // Get timestamp for one week ago.
        $weekago = time() - (7 * 24 * 60 * 60);
        $params['weekago'] = $weekago;

        $sql = "SELECT p.id, p.subject, p.message, p.created, p.userid,
                       d.id as discussionid, d.name as discussionname,
                       f.id as forumid, f.name as forumname,
                       cm.id as cmid,
                       COUNT(r.id) as reactioncount
                  FROM {forum_posts} p
                  JOIN {forum_discussions} d ON d.id = p.discussion
                  JOIN {forum} f ON f.id = d.forum
                  JOIN {modules} m ON m.name = 'forum'
                  JOIN {course_modules} cm ON cm.instance = f.id AND cm.module = m.id
                  JOIN {local_reactions} r ON r.itemid = p.id
                                           AND r.component = 'mod_forum'
                                           AND r.itemtype = 'post'
                 WHERE d.forum $insql
                   AND r.timecreated >= :weekago
              GROUP BY p.id, p.subject, p.message, p.created, p.userid,
                       d.id, d.name, f.id, f.name, cm.id
              ORDER BY reactioncount DESC, p.created DESC";

        return array_values($DB->get_records_sql($sql, $params, 0, $limit));
    }
}
