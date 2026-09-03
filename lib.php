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
 * Module types that expose the per-activity reactions settings, mapped to the site-wide
 * admin setting that has to be on before the settings appear.
 *
 * @return array<string, string> modulename => admin setting name.
 */
function local_reactions_get_supported_modules(): array {
    return [
        'forum' => 'enabled',
        'data' => 'enableddata',
    ];
}

/**
 * Form elements making up the reactions settings group for a module type.
 *
 * Each entry maps the form field name to the language string key used for both its label and
 * its help button. The first entry is always the parent "enable" toggle; the remaining ones
 * are hidden while the parent is unchecked. The database activity omits the peer-grading
 * option, which only affects the forum's whole-forum grading panel.
 *
 * @param string $modulename The module the form belongs to, e.g. 'forum' or 'data'.
 * @return array<string, string> form field name => language string key.
 */
function local_reactions_get_form_elements(string $modulename): array {
    if ($modulename === 'data') {
        return [
            'local_reactions_enabled'                => 'enablereactionsdata',
            'local_reactions_compactview_list'       => 'compactview_datalist',
            'local_reactions_compactview_discuss'    => 'compactview_datasingle',
            'local_reactions_allowmultiplereactions' => 'allowmultiplereactionsdata',
        ];
    }
    return [
        'local_reactions_enabled'                  => 'enablereactions',
        'local_reactions_compactview_list'         => 'compactview_list',
        'local_reactions_compactview_discuss'      => 'compactview_discuss',
        'local_reactions_allowmultiplereactions'   => 'allowmultiplereactions',
        'local_reactions_onlypeerreactionsgrading' => 'onlypeerreactionsgrading',
    ];
}

/**
 * Map of form field => [db field on local_reactions_enabled, default for new activities].
 *
 * Display toggles default off; multi-reaction and peer-grading default on. Fields the module
 * does not offer are left out, so saving never overwrites them.
 *
 * @param string $modulename The module the form belongs to, e.g. 'forum' or 'data'.
 * @return array<string, array{0: string, 1: int}>
 */
function local_reactions_get_form_fieldmap(string $modulename = 'forum'): array {
    $fieldmap = [
        'local_reactions_enabled'                  => ['enabled', 0],
        'local_reactions_compactview_list'         => ['compactview_list', 0],
        'local_reactions_compactview_discuss'      => ['compactview_discuss', 0],
        'local_reactions_allowmultiplereactions'   => ['allowmultiplereactions', 1],
        'local_reactions_onlypeerreactionsgrading' => ['onlypeerreactionsgrading', 1],
    ];
    return array_intersect_key($fieldmap, local_reactions_get_form_elements($modulename));
}

/**
 * Whether the given activity already has reactions on its items, which locks the
 * "allow multiple reactions" setting in the on position.
 *
 * @param string $modulename The module name, e.g. 'forum' or 'data'.
 * @param int $instanceid The activity instance ID.
 * @return bool
 */
function local_reactions_instance_has_reactions(string $modulename, int $instanceid): bool {
    if (!$instanceid) {
        return false;
    }
    if ($modulename === 'data') {
        return \local_reactions\manager::data_has_reactions($instanceid);
    }
    return \local_reactions\manager::forum_has_reactions($instanceid);
}

/**
 * Add the "Reactions" settings section to a supported activity's settings form.
 *
 * @param moodleform $formwrapper The form wrapper.
 * @param MoodleQuickForm $mform The form object.
 */
function local_reactions_coursemodule_standard_elements($formwrapper, $mform) {
    $cm = $formwrapper->get_current();

    $supported = local_reactions_get_supported_modules();
    $modulename = $cm->modulename ?? '';
    if (!isset($supported[$modulename])) {
        return;
    }
    if (!get_config('local_reactions', $supported[$modulename])) {
        return;
    }

    $mform->addElement(
        'header',
        'local_reactions_header',
        get_string('reactionssettings', 'local_reactions')
    );

    // Parent toggle first, then the rest which hide when the parent is unchecked.
    $elements = local_reactions_get_form_elements($modulename);
    $parentfield = array_key_first($elements);
    foreach ($elements as $fieldname => $stringkey) {
        $mform->addElement('checkbox', $fieldname, get_string($stringkey, 'local_reactions'));
        $mform->addHelpButton($fieldname, $stringkey, 'local_reactions');
        if ($fieldname !== $parentfield) {
            $mform->hideIf($fieldname, $parentfield);
        }
    }

    $cmid = (int) ($cm->coursemodule ?? 0);
    $record = $cmid ? \local_reactions\manager::get_module_config($cmid) : null;
    local_reactions_apply_form_defaults($mform, $record, $modulename);

    // Lock the "allow multiple" checkbox when the activity is already in multi-reaction
    // mode and reactions exist. Once reactions are present you cannot downgrade.
    $multipleenabled = !$record || !empty($record->allowmultiplereactions);
    if ($cmid && $multipleenabled && local_reactions_instance_has_reactions($modulename, (int) $cm->instance)) {
        $mform->hardFreeze('local_reactions_allowmultiplereactions');
    }
}

/**
 * Apply defaults to every checkbox in the reactions form group, using the stored
 * record when one exists or the per-field "new forum" default otherwise.
 *
 * @param MoodleQuickForm $mform
 * @param \stdClass|null $record Existing local_reactions_enabled row or null.
 * @param string $modulename The module the form belongs to, e.g. 'forum' or 'data'.
 */
function local_reactions_apply_form_defaults($mform, ?stdClass $record, string $modulename = 'forum'): void {
    foreach (local_reactions_get_form_fieldmap($modulename) as $formfield => [$dbfield, $newdefault]) {
        $value = ($record && isset($record->$dbfield)) ? (!empty($record->$dbfield) ? 1 : 0) : $newdefault;
        $mform->setDefault($formfield, $value);
    }
}

/**
 * Save the per-activity reactions settings after module create/update.
 *
 * @param stdClass $data Data from the form submission.
 * @param stdClass $course The course (unused; required by the hook signature).
 * @return stdClass The data object.
 */
function local_reactions_coursemodule_edit_post_actions($data, $course): stdClass {
    global $DB;
    unset($course);

    $supported = local_reactions_get_supported_modules();
    $modulename = $data->modulename ?? '';
    if (!isset($supported[$modulename])) {
        return $data;
    }
    if (!get_config('local_reactions', $supported[$modulename])) {
        return $data;
    }

    $cmid = (int) $data->coursemodule;
    $fields = ['cmid' => $cmid];
    foreach (local_reactions_get_form_fieldmap($modulename) as $formfield => $mapping) {
        $dbfield = $mapping[0];
        $fields[$dbfield] = !empty($data->$formfield) ? 1 : 0;
    }

    $existing = $DB->get_record('local_reactions_enabled', ['cmid' => $cmid]);

    // Server-side safety: prevent switching multiple→single when reactions already exist.
    $fields['allowmultiplereactions'] = local_reactions_enforce_multiple_safety(
        $existing,
        (int) $fields['allowmultiplereactions'],
        $modulename,
        (int) ($data->instance ?? 0)
    );

    if ($existing) {
        $fields['id'] = $existing->id;
        $DB->update_record('local_reactions_enabled', (object) $fields);
    } else {
        $DB->insert_record('local_reactions_enabled', (object) $fields);
    }

    // Keep the per-request cache consistent with what we just wrote.
    \local_reactions\manager::clear_module_config_cache($cmid);

    return $data;
}

/**
 * Return the effective allowmultiplereactions value, forcing it back on when an existing
 * record was multi-reaction and the activity already has reactions (cannot downgrade).
 *
 * @param \stdClass|false $existing Existing local_reactions_enabled row, or false when none.
 * @param int $requested The value submitted by the form (0 or 1).
 * @param string $modulename The module name, e.g. 'forum' or 'data'.
 * @param int $instanceid Activity instance ID from the form data.
 * @return int 0 or 1.
 */
function local_reactions_enforce_multiple_safety($existing, int $requested, string $modulename, int $instanceid): int {
    if (!$existing || empty($existing->allowmultiplereactions) || $requested) {
        return $requested;
    }
    if (local_reactions_instance_has_reactions($modulename, $instanceid)) {
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
