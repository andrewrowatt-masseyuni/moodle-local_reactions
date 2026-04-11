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

    /** @var int[] Forum IDs with reactions enabled */
    private $forumids;

    /** @var array<int,int> Map of forum instance ID => course module ID */
    private $forumtocmid = [];

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
     * Uses modinfo to iterate forum course modules so soft-deleted modules
     * (cm.deletioninprogress) are naturally excluded via the course cache,
     * and delegates the per-forum enable check to the cached config helper.
     *
     * @return array Array of forum instance IDs
     */
    private function get_enabled_forums(): array {
        $modinfo = get_fast_modinfo($this->courseid);
        $forumids = [];
        foreach ($modinfo->get_instances_of('forum') as $cm) {
            if (!empty($cm->deletioninprogress)) {
                continue;
            }
            $config = manager::get_forum_config($cm->id);
            if ($config && !empty($config->enabled)) {
                $forumid = (int) $cm->instance;
                $forumids[] = $forumid;
                $this->forumtocmid[$forumid] = (int) $cm->id;
            }
        }
        return $forumids;
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

        // Get total posts (p.id is the PK so DISTINCT is redundant).
        $sql = "SELECT COUNT(p.id) as total
                  FROM {forum_posts} p
                  JOIN {forum_discussions} d ON d.id = p.discussion
                 WHERE d.forum $insql";

        $totalposts = $DB->count_records_sql($sql, $params);

        // Get total reactions.
        $params['component'] = manager::COMPONENT_FORUM;
        $params['itemtype'] = manager::ITEMTYPE_POST;
        $sql = "SELECT COUNT(*) as total
                  FROM {local_reactions} r
                  JOIN {forum_posts} p ON p.id = r.itemid
                  JOIN {forum_discussions} d ON d.id = p.discussion
                 WHERE r.component = :component
                   AND r.itemtype = :itemtype
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
        $params['component'] = manager::COMPONENT_FORUM;
        $params['itemtype'] = manager::ITEMTYPE_POST;

        // Get active reactors.
        $sql = "SELECT COUNT(DISTINCT r.userid) as total
                  FROM {local_reactions} r
                  JOIN {forum_posts} p ON p.id = r.itemid
                  JOIN {forum_discussions} d ON d.id = p.discussion
                 WHERE r.component = :component
                   AND r.itemtype = :itemtype
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
        $params['component'] = manager::COMPONENT_FORUM;
        $params['itemtype'] = manager::ITEMTYPE_POST;

        // Forumids is already scoped to forum instance IDs (via modinfo),
        // so we don't need to join {course_modules} + {modules} just to verify the
        // module type or look up cmid - that mapping already lives in $forumtocmid.
        // Skip the TEXT column p.message as it is never rendered in the report.
        $sql = "SELECT p.id, p.subject, p.created, p.userid,
                       d.id as discussionid, d.name as discussionname,
                       f.id as forumid, f.name as forumname
                  FROM {forum_posts} p
                  JOIN {forum_discussions} d ON d.id = p.discussion
                  JOIN {forum} f ON f.id = d.forum
                 WHERE d.forum $insql
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {local_reactions} r
                        WHERE r.component = :component
                          AND r.itemtype = :itemtype
                          AND r.itemid = p.id
                   )
              ORDER BY p.created DESC";

        $records = $DB->get_records_sql($sql, $params, 0, $limit);
        foreach ($records as $record) {
            $record->cmid = $this->forumtocmid[(int) $record->forumid] ?? null;
        }
        return array_values($records);
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
        $params['component'] = manager::COMPONENT_FORUM;
        $params['itemtype'] = manager::ITEMTYPE_POST;
        $params['weekago'] = time() - (7 * 24 * 60 * 60);

        // As above: $this->forumids pre-scopes to forum instances, so {course_modules}
        // and {modules} joins are unnecessary - cmid is resolved via $forumtocmid.
        // Drops the TEXT column p.message which was never displayed.
        $sql = "SELECT p.id, p.subject, p.created, p.userid,
                       d.id as discussionid, d.name as discussionname,
                       f.id as forumid, f.name as forumname,
                       COUNT(r.id) as reactioncount
                  FROM {forum_posts} p
                  JOIN {forum_discussions} d ON d.id = p.discussion
                  JOIN {forum} f ON f.id = d.forum
                  JOIN {local_reactions} r ON r.itemid = p.id
                                           AND r.component = :component
                                           AND r.itemtype = :itemtype
                 WHERE d.forum $insql
                   AND r.timecreated >= :weekago
              GROUP BY p.id, p.subject, p.created, p.userid,
                       d.id, d.name, f.id, f.name
              ORDER BY reactioncount DESC, p.created DESC";

        $records = $DB->get_records_sql($sql, $params, 0, $limit);
        foreach ($records as $record) {
            $record->cmid = $this->forumtocmid[(int) $record->forumid] ?? null;
        }
        return array_values($records);
    }
}
