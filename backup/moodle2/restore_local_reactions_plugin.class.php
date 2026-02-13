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
 * Restore plugin for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore support for local_reactions at the module level.
 *
 * Restores per-forum reactions settings and individual reaction records.
 * Reaction records are deferred to after_restore_module() because forum
 * posts have not yet been restored when process_reaction() is called.
 */
class restore_local_reactions_plugin extends restore_local_plugin {
    /** @var array Reaction records to insert after forum posts are restored. */
    protected $pendingreactions = [];

    /**
     * Define the module-level plugin structure for restore.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        $paths = [
            new restore_path_element('reactions_enabled', $this->get_pathfor('/reactions_enabled')),
        ];

        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element('reaction', $this->get_pathfor('/reactions/reaction'));
        }

        return $paths;
    }

    /**
     * Process the restored reactions_enabled data.
     *
     * @param array $data The backed-up record data.
     */
    public function process_reactions_enabled($data) {
        global $DB;

        $data = (object) $data;
        $data->cmid = $this->task->get_moduleid();
        unset($data->id);

        $DB->insert_record('local_reactions_enabled', $data);
    }

    /**
     * Stash an individual reaction record for deferred processing.
     *
     * Forum posts are restored after the module structure step, so
     * post ID mappings are not available yet. We store reaction data
     * and insert it in after_restore_module().
     *
     * @param array $data The backed-up reaction data.
     */
    public function process_reaction($data) {
        $this->pendingreactions[] = (object) $data;
    }

    /**
     * Insert deferred reaction records now that forum posts have been restored.
     */
    public function after_restore_module() {
        global $DB;

        foreach ($this->pendingreactions as $data) {
            $newitemid = $this->get_mappingid('forum_post', $data->itemid);
            if (!$newitemid) {
                continue;
            }

            $newuserid = $this->get_mappingid('user', $data->userid);
            if (!$newuserid) {
                continue;
            }

            $data->itemid = $newitemid;
            $data->userid = $newuserid;
            unset($data->id);

            $DB->insert_record('local_reactions', $data);
        }
    }
}
