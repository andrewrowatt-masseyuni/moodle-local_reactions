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

    $mform->addElement(
        'checkbox',
        'local_reactions_compactview_list',
        get_string('compactview_list', 'local_reactions')
    );
    $mform->addHelpButton('local_reactions_compactview_list', 'compactview_list', 'local_reactions');
    $mform->hideIf('local_reactions_compactview_list', 'local_reactions_enabled');

    $mform->addElement(
        'checkbox',
        'local_reactions_compactview_discuss',
        get_string('compactview_discuss', 'local_reactions')
    );
    $mform->addHelpButton('local_reactions_compactview_discuss', 'compactview_discuss', 'local_reactions');
    $mform->hideIf('local_reactions_compactview_discuss', 'local_reactions_enabled');

    $mform->addElement(
        'checkbox',
        'local_reactions_allowmultiplereactions',
        get_string('allowmultiplereactions', 'local_reactions')
    );
    $mform->addHelpButton('local_reactions_allowmultiplereactions', 'allowmultiplereactions', 'local_reactions');
    $mform->hideIf('local_reactions_allowmultiplereactions', 'local_reactions_enabled');

    $mform->addElement(
        'checkbox',
        'local_reactions_onlypeerreactionsgrading',
        get_string('onlypeerreactionsgrading', 'local_reactions')
    );
    $mform->addHelpButton('local_reactions_onlypeerreactionsgrading', 'onlypeerreactionsgrading', 'local_reactions');
    $mform->hideIf('local_reactions_onlypeerreactionsgrading', 'local_reactions_enabled');

    // Populate defaults: either mirror the stored record, or fall back to the
    // "new forum" default (off for display toggles, on for multi/peer grading).
    // Map: form field => [db field on local_reactions_enabled, new-forum default].
    $fieldmap = [
        'local_reactions_enabled'                  => ['enabled', 0],
        'local_reactions_compactview_list'         => ['compactview_list', 0],
        'local_reactions_compactview_discuss'      => ['compactview_discuss', 0],
        'local_reactions_allowmultiplereactions'   => ['allowmultiplereactions', 1],
        'local_reactions_onlypeerreactionsgrading' => ['onlypeerreactionsgrading', 1],
    ];
    $cmid = (int) ($cm->coursemodule ?? 0);
    $record = $cmid ? \local_reactions\manager::get_forum_config($cmid) : null;
    foreach ($fieldmap as $formfield => [$dbfield, $newdefault]) {
        if ($record && isset($record->$dbfield)) {
            $mform->setDefault($formfield, !empty($record->$dbfield) ? 1 : 0);
        } else {
            $mform->setDefault($formfield, $newdefault);
        }
    }

    // Lock the "allow multiple" checkbox when the forum is already in multi-reaction
    // mode and reactions exist. Once reactions are present you cannot downgrade.
    if ($cmid) {
        $ismultiplereactionsenabled = !$record || !empty($record->allowmultiplereactions);
        if ($ismultiplereactionsenabled && \local_reactions\manager::forum_has_reactions($cm->instance)) {
            $mform->hardFreeze('local_reactions_allowmultiplereactions');
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

    if (!get_config('local_reactions', 'enabled')) {
        return $data;
    }

    // Only process forums.
    if (!isset($data->modulename) || $data->modulename !== 'forum') {
        return $data;
    }

    $enabled = !empty($data->local_reactions_enabled) ? 1 : 0;
    $compactviewlist = !empty($data->local_reactions_compactview_list) ? 1 : 0;
    $compactviewdiscuss = !empty($data->local_reactions_compactview_discuss) ? 1 : 0;
    $allowmultiple = !empty($data->local_reactions_allowmultiplereactions) ? 1 : 0;
    $onlypeergrading = !empty($data->local_reactions_onlypeerreactionsgrading) ? 1 : 0;
    $cmid = (int) $data->coursemodule;

    $existing = $DB->get_record('local_reactions_enabled', ['cmid' => $cmid]);

    // Server-side safety: prevent switching multiple→single when reactions already exist.
    // $data->instance is the forum instance ID populated by the module form handler,
    // so we don't need a $DB->get_field round-trip to course_modules.
    if ($existing && !empty($existing->allowmultiplereactions) && !$allowmultiple) {
        $forumid = (int) ($data->instance ?? 0);
        if ($forumid && \local_reactions\manager::forum_has_reactions($forumid)) {
            $allowmultiple = 1;
        }
    }

    if ($existing) {
        $existing->enabled = $enabled;
        $existing->compactview_list = $compactviewlist;
        $existing->compactview_discuss = $compactviewdiscuss;
        $existing->allowmultiplereactions = $allowmultiple;
        $existing->onlypeerreactionsgrading = $onlypeergrading;
        $DB->update_record('local_reactions_enabled', $existing);
    } else {
        $DB->insert_record('local_reactions_enabled', (object) [
            'cmid' => $cmid,
            'enabled' => $enabled,
            'compactview_list' => $compactviewlist,
            'compactview_discuss' => $compactviewdiscuss,
            'allowmultiplereactions' => $allowmultiple,
            'onlypeerreactionsgrading' => $onlypeergrading,
        ]);
    }

    // Keep the per-request cache consistent with what we just wrote.
    \local_reactions\manager::clear_forum_config_cache($cmid);

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
