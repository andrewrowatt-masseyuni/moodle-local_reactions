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
 * Map of form field => [db field on local_reactions_enabled, default for new forums].
 *
 * Display toggles default off; multi-reaction and peer-grading default on.
 *
 * @return array<string, array{0: string, 1: int}>
 */
function local_reactions_get_form_fieldmap(): array {
    return [
        'local_reactions_enabled'                  => ['enabled', 0],
        'local_reactions_compactview_list'         => ['compactview_list', 0],
        'local_reactions_compactview_discuss'      => ['compactview_discuss', 0],
        'local_reactions_allowmultiplereactions'   => ['allowmultiplereactions', 1],
        'local_reactions_onlypeerreactionsgrading' => ['onlypeerreactionsgrading', 1],
    ];
}

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

    // Parent toggle first, then the rest which hide when the parent is unchecked.
    $mform->addElement('checkbox', 'local_reactions_enabled', get_string('enablereactions', 'local_reactions'));
    $mform->addHelpButton('local_reactions_enabled', 'enablereactions', 'local_reactions');

    $children = [
        'local_reactions_compactview_list'         => 'compactview_list',
        'local_reactions_compactview_discuss'      => 'compactview_discuss',
        'local_reactions_allowmultiplereactions'   => 'allowmultiplereactions',
        'local_reactions_onlypeerreactionsgrading' => 'onlypeerreactionsgrading',
    ];
    foreach ($children as $fieldname => $stringkey) {
        $mform->addElement('checkbox', $fieldname, get_string($stringkey, 'local_reactions'));
        $mform->addHelpButton($fieldname, $stringkey, 'local_reactions');
        $mform->hideIf($fieldname, 'local_reactions_enabled');
    }

    $cmid = (int) ($cm->coursemodule ?? 0);
    $record = $cmid ? \local_reactions\manager::get_forum_config($cmid) : null;
    local_reactions_apply_form_defaults($mform, $record);

    // Lock the "allow multiple" checkbox when the forum is already in multi-reaction
    // mode and reactions exist. Once reactions are present you cannot downgrade.
    $multipleenabled = !$record || !empty($record->allowmultiplereactions);
    if ($cmid && $multipleenabled && \local_reactions\manager::forum_has_reactions($cm->instance)) {
        $mform->hardFreeze('local_reactions_allowmultiplereactions');
    }
}

/**
 * Apply defaults to every checkbox in the reactions form group, using the stored
 * record when one exists or the per-field "new forum" default otherwise.
 *
 * @param MoodleQuickForm $mform
 * @param \stdClass|null $record Existing local_reactions_enabled row or null.
 */
function local_reactions_apply_form_defaults($mform, ?stdClass $record): void {
    foreach (local_reactions_get_form_fieldmap() as $formfield => [$dbfield, $newdefault]) {
        $value = ($record && isset($record->$dbfield)) ? (!empty($record->$dbfield) ? 1 : 0) : $newdefault;
        $mform->setDefault($formfield, $value);
    }
}

/**
 * Save the per-forum reactions setting after module create/update.
 *
 * @param stdClass $data Data from the form submission.
 * @param stdClass $course The course (unused; required by the hook signature).
 * @return stdClass The data object.
 */
function local_reactions_coursemodule_edit_post_actions($data, $course): stdClass {
    global $DB;
    unset($course);

    if (!get_config('local_reactions', 'enabled')) {
        return $data;
    }

    // Only process forums.
    if (!isset($data->modulename) || $data->modulename !== 'forum') {
        return $data;
    }

    $cmid = (int) $data->coursemodule;
    $fields = ['cmid' => $cmid];
    foreach (local_reactions_get_form_fieldmap() as $formfield => $mapping) {
        $dbfield = $mapping[0];
        $fields[$dbfield] = !empty($data->$formfield) ? 1 : 0;
    }

    $existing = $DB->get_record('local_reactions_enabled', ['cmid' => $cmid]);

    // Server-side safety: prevent switching multiple→single when reactions already exist.
    $fields['allowmultiplereactions'] = local_reactions_enforce_multiple_safety(
        $existing,
        (int) $fields['allowmultiplereactions'],
        (int) ($data->instance ?? 0)
    );

    if ($existing) {
        $fields['id'] = $existing->id;
        $DB->update_record('local_reactions_enabled', (object) $fields);
    } else {
        $DB->insert_record('local_reactions_enabled', (object) $fields);
    }

    // Keep the per-request cache consistent with what we just wrote.
    \local_reactions\manager::clear_forum_config_cache($cmid);

    return $data;
}

/**
 * Return the effective allowmultiplereactions value, forcing it back on when an existing
 * record was multi-reaction and the forum already has reactions (cannot downgrade).
 *
 * @param \stdClass|false $existing Existing local_reactions_enabled row, or false when none.
 * @param int $requested The value submitted by the form (0 or 1).
 * @param int $forumid Forum instance ID from the form data.
 * @return int 0 or 1.
 */
function local_reactions_enforce_multiple_safety($existing, int $requested, int $forumid): int {
    if (!$existing || empty($existing->allowmultiplereactions) || $requested) {
        return $requested;
    }
    if ($forumid && \local_reactions\manager::forum_has_reactions($forumid)) {
        return 1;
    }
    return $requested;
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
