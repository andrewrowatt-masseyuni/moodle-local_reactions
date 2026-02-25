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
 * Upgrade script for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the local_reactions plugin.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool
 */
function xmldb_local_reactions_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026022501) {
        // Add allowmultiplereactions column to local_reactions_enabled.
        $table = new xmldb_table('local_reactions_enabled');
        $field = new xmldb_field(
            'allowmultiplereactions',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '1'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026022501, 'local', 'reactions');
    }

    return true;
}
