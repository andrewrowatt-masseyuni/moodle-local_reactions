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
 * Library functions for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add "Enable reactions" checkbox to the forum settings form.
 *
 * @param moodleform $formwrapper The form wrapper.
 * @param MoodleQuickForm $mform The form object.
 */
function local_reactions_coursemodule_standard_elements($formwrapper, $mform) {
    global $DB;

    if (!get_config('local_reactions', 'enabled')) {
        return;
    }

    $cm = $formwrapper->get_current();

    // Only show for forums.
    if ($cm->modulename !== 'forum') {
        return;
    }

    $mform->addElement(
        'header',
        'local_reactions_header',
        get_string('reactionssettings', 'local_reactions')
    );

    $mform->addElement(
        'checkbox',
        'local_reactions_enabled',
        get_string('enablereactions', 'local_reactions')
    );
    $mform->addHelpButton('local_reactions_enabled', 'enablereactions', 'local_reactions');

    // Set current value from the database.
    if ($cmid = $cm->coursemodule) {
        $record = $DB->get_record('local_reactions_enabled', ['cmid' => $cmid]);
        if ($record && $record->enabled) {
            $mform->setDefault('local_reactions_enabled', 1);
        }
    }
}

/**
 * Save the per-forum reactions setting after module create/update.
 *
 * @param stdClass $data Data from the form submission.
 * @param stdClass $course The course.
 * @return stdClass The data object.
 */
function local_reactions_coursemodule_edit_post_actions($data, $course): stdClass {
    global $DB;

    // Only process forums.
    if (!isset($data->modulename) || $data->modulename !== 'forum') {
        return $data;
    }

    $enabled = !empty($data->local_reactions_enabled) ? 1 : 0;
    $cmid = $data->coursemodule;

    $existing = $DB->get_record('local_reactions_enabled', ['cmid' => $cmid]);
    if ($existing) {
        $existing->enabled = $enabled;
        $DB->update_record('local_reactions_enabled', $existing);
    } else {
        $DB->insert_record('local_reactions_enabled', (object) [
            'cmid' => $cmid,
            'enabled' => $enabled,
        ]);
    }

    return $data;
}

/**
 * Extend course navigation to add reactions report link.
 *
 * @param navigation_node $navigation The navigation node to extend
 * @param stdClass $course The course object
 * @param context_course $context The course context
 */
function local_reactions_extend_navigation_course($navigation, $course, $context) {
    if (!get_config('local_reactions', 'enabled')) {
        return;
    }

    if (has_capability('local/reactions:viewreport', $context)) {
        $url = new moodle_url('/local/reactions/report.php', ['id' => $course->id]);
        $navigation->add(
            get_string('reactionsreport', 'local_reactions'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'reactionsreport',
            new pix_icon('i/report', '')
        );
    }
}
