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
 * Backup plugin for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Backup support for local_reactions at the module level.
 *
 * Backs up per-forum reactions settings and, when user data is included,
 * the individual reaction records.
 */
class backup_local_reactions_plugin extends backup_local_plugin {
    /**
     * Define the module-level plugin structure for backup.
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        // Per-forum settings (always included).
        $settings = new backup_nested_element('reactions_enabled', ['id'], [
            'enabled', 'compactview_list', 'compactview_discuss', 'allowmultiplereactions',
        ]);
        $pluginwrapper->add_child($settings);
        $settings->set_source_table('local_reactions_enabled', ['cmid' => backup::VAR_MODID]);

        // Individual reaction records (only when user data is included).
        $userinfo = $this->get_setting_value('userinfo');
        if ($userinfo) {
            $reactions = new backup_nested_element('reactions');
            $reaction = new backup_nested_element('reaction', ['id'], [
                'component', 'itemtype', 'itemid', 'userid', 'emoji', 'timecreated',
            ]);
            $pluginwrapper->add_child($reactions);
            $reactions->add_child($reaction);

            $reaction->set_source_sql(
                '
                SELECT lr.*
                  FROM {local_reactions} lr
                  JOIN {forum_posts} fp ON fp.id = lr.itemid
                  JOIN {forum_discussions} fd ON fd.id = fp.discussion
                 WHERE fd.forum = ?
                   AND lr.component = ?
                   AND lr.itemtype = ?',
                [backup::VAR_ACTIVITYID,
                 backup_helper::is_sqlparam('mod_forum'),
                backup_helper::is_sqlparam('post')]
            );

            $reaction->annotate_ids('user', 'userid');
        }

        return $plugin;
    }
}
