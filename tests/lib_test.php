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
                'local_reactions_compactview_discuss', 'local_reactions_allowmultiplereactions',
                'local_reactions_shownames'],
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

    /**
     * Every per-activity option has a site-wide default per module type, and they all start off.
     *
     * @covers ::local_reactions_get_default_setting_name
     * @covers ::local_reactions_get_form_fieldmap
     */
    public function test_site_defaults_exist_and_are_off(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/reactions/lib.php');
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();

        $this->setAdminUser();
        $page = admin_get_root(true)->locate('local_reactions');

        $this->assertSame(
            'forumdefault_compactview_list',
            local_reactions_get_default_setting_name('forum', 'compactview_list')
        );

        foreach (array_keys(local_reactions_get_supported_modules()) as $modulename) {
            foreach (local_reactions_get_form_fieldmap($modulename) as [$dbfield, $newdefault]) {
                $name = local_reactions_get_default_setting_name($modulename, $dbfield);
                // The settings page keys each setting by its plugin and name run together.
                $setting = $page->settings->{'local_reactions' . $name} ?? null;
                $this->assertInstanceOf(\admin_setting_configcheckbox::class, $setting, "Missing setting '{$name}'");
                $this->assertSame(0, $setting->get_defaultsetting(), "'{$name}' should default to off");
                $this->assertSame('0', get_config('local_reactions', $name), "'{$name}' should be off after install");
                $this->assertSame(0, $newdefault);
            }
        }
    }

    /**
     * A new activity's form starts from its own module type's site-wide defaults.
     *
     * @covers ::local_reactions_apply_form_defaults
     */
    public function test_new_activity_form_uses_site_defaults(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/reactions/lib.php');
        require_once($CFG->libdir . '/formslib.php');
        $this->resetAfterTest();

        set_config('forumdefault_enabled', 1, 'local_reactions');
        set_config('forumdefault_compactview_list', 1, 'local_reactions');
        set_config('forumdefault_onlypeerreactionsgrading', 1, 'local_reactions');
        set_config('datadefault_shownames', 1, 'local_reactions');

        $mform = new \MoodleQuickForm('test', 'post', '');
        local_reactions_apply_form_defaults($mform, null, 'forum', true);
        $this->assertSame([
            'local_reactions_enabled' => 1,
            'local_reactions_compactview_list' => 1,
            'local_reactions_compactview_discuss' => 0,
            'local_reactions_allowmultiplereactions' => 0,
            'local_reactions_onlypeerreactionsgrading' => 1,
            'local_reactions_shownames' => 0,
        ], $mform->_defaultValues);

        // The database activity has its own defaults; the forum ones do not leak across.
        $mform = new \MoodleQuickForm('test', 'post', '');
        local_reactions_apply_form_defaults($mform, null, 'data', true);
        $this->assertSame([
            'local_reactions_enabled' => 0,
            'local_reactions_compactview_list' => 0,
            'local_reactions_compactview_discuss' => 0,
            'local_reactions_allowmultiplereactions' => 0,
            'local_reactions_shownames' => 1,
        ], $mform->_defaultValues);
    }

    /**
     * Editing an existing activity shows its stored settings, never the site-wide defaults, and an
     * activity with no stored settings is not shown as enabled just because the default is on.
     *
     * @covers ::local_reactions_apply_form_defaults
     */
    public function test_existing_activity_form_ignores_site_enable_default(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/reactions/lib.php');
        require_once($CFG->libdir . '/formslib.php');
        $this->resetAfterTest();

        foreach (local_reactions_get_form_fieldmap('forum') as [$dbfield]) {
            set_config(local_reactions_get_default_setting_name('forum', $dbfield), 1, 'local_reactions');
        }

        // No stored record: reactions are off, the other options fall back to the defaults.
        $mform = new \MoodleQuickForm('test', 'post', '');
        local_reactions_apply_form_defaults($mform, null, 'forum', false);
        $this->assertSame(0, $mform->_defaultValues['local_reactions_enabled']);
        $this->assertSame(1, $mform->_defaultValues['local_reactions_compactview_list']);
        $this->assertSame(1, $mform->_defaultValues['local_reactions_allowmultiplereactions']);

        // A stored record wins over every default.
        $record = (object) [
            'enabled' => 0,
            'compactview_list' => 0,
            'compactview_discuss' => 0,
            'allowmultiplereactions' => 0,
            'onlypeerreactionsgrading' => 0,
            'shownames' => 0,
        ];
        $mform = new \MoodleQuickForm('test', 'post', '');
        local_reactions_apply_form_defaults($mform, $record, 'forum', false);
        $this->assertSame([0], array_unique(array_values($mform->_defaultValues)));
    }
}
