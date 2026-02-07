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
 * Upgrade steps for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function xmldb_local_reactions_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026020702) {
        // Add table for per-forum reactions toggle.
        $table = new xmldb_table('local_reactions_enabled');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('cmid_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['cmid'], 'course_modules', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026020702, 'local', 'reactions');
    }

    if ($oldversion < 2026020703) {
        // Switch from single-react to multi-react: replace the unique index
        // so users can have multiple different emoji on the same item.
        $table = new xmldb_table('local_reactions');

        // Drop old unique index (component, itemtype, itemid, userid).
        $oldindex = new xmldb_index('user_item_unique', XMLDB_INDEX_UNIQUE, ['component', 'itemtype', 'itemid', 'userid']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }

        // Add new unique index (component, itemtype, itemid, userid, emoji).
        $newindex = new xmldb_index('user_item_emoji_unique', XMLDB_INDEX_UNIQUE,
            ['component', 'itemtype', 'itemid', 'userid', 'emoji']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        upgrade_plugin_savepoint(true, 2026020703, 'local', 'reactions');
    }

    return true;
}
