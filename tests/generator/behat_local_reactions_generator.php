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

/**
 * Behat data generator for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Behat data generator for local_reactions.
 */
class behat_local_reactions_generator extends behat_generator_base {
    /**
     * Get the creatable entities for this component.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'reactions' => [
                'singular' => 'reaction',
                'datagenerator' => 'reaction',
                'required' => ['user', 'post', 'emoji'],
                'switchids' => ['user' => 'userid'],
            ],
            'enabled forums' => [
                'singular' => 'enabled forum',
                'datagenerator' => 'enabled_forum',
                'required' => ['forum', 'course'],
            ],
        ];
    }

    /**
     * Look up a forum post ID by subject.
     *
     * @param string $subject The post subject.
     * @return int The post ID.
     */
    protected function get_post_id(string $subject): int {
        global $DB;
        return (int) $DB->get_field('forum_posts', 'id', ['subject' => $subject], MUST_EXIST);
    }
}
