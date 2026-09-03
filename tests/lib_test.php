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
 * Tests for Reactions
 *
 * @package    local_reactions
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {
    /**
     * Example of a unittest
     *
     * TODO change the 'covers' tag to the class or function in the plugin.
     * @covers ::get_config
     */
    public function test_plugin_installed(): void {
        $this->assertNotEmpty(get_config('local_reactions', 'version'));
    }

    /**
     * The settings form offers the peer-grading option to forums only; every other field is shared.
     *
     * @covers ::local_reactions_get_form_elements
     * @covers ::local_reactions_get_form_fieldmap
     */
    public function test_form_fields_differ_per_module(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/reactions/lib.php');

        $forumfields = array_keys(local_reactions_get_form_fieldmap('forum'));
        $datafields = array_keys(local_reactions_get_form_fieldmap('data'));

        $this->assertContains('local_reactions_onlypeerreactionsgrading', $forumfields);
        $this->assertNotContains('local_reactions_onlypeerreactionsgrading', $datafields);
        $this->assertSame(
            ['local_reactions_enabled', 'local_reactions_compactview_list',
                'local_reactions_compactview_discuss', 'local_reactions_allowmultiplereactions'],
            $datafields
        );

        // Every form element must have a label string and a matching help string.
        foreach (['forum', 'data'] as $modulename) {
            foreach (local_reactions_get_form_elements($modulename) as $stringkey) {
                $this->assertTrue(
                    get_string_manager()->string_exists($stringkey, 'local_reactions'),
                    "Missing string '{$stringkey}'"
                );
                $this->assertTrue(
                    get_string_manager()->string_exists($stringkey . '_help', 'local_reactions'),
                    "Missing string '{$stringkey}_help'"
                );
            }
        }
    }

    /**
     * Only the module types the plugin supports get the settings section.
     *
     * @covers ::local_reactions_get_supported_modules
     */
    public function test_supported_modules_map_to_admin_settings(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/reactions/lib.php');

        $this->assertSame(
            ['forum' => 'enabled', 'data' => 'enableddata'],
            local_reactions_get_supported_modules()
        );
    }
}
