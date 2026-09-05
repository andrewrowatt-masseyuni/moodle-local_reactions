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
 * Moodle App support for Reactions.
 *
 * The app has no delegate for decorating a forum post, so this handler registers no delegate of
 * its own. Its init JS watches the DOM and grafts reaction bars onto posts as the app renders
 * them. See local/reactions/mobileapp/init.js.
 *
 * Note that tool_mobile caches this file's contents in the 'plugininfo' cache with no automatic
 * invalidation, so purge caches after editing it.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$addons = [
    'local_reactions' => [
        'handlers' => [
            'reactions' => [
                'delegate' => '', // No delegate fits; the JS runs standalone and watches the DOM.
                'init' => 'mobile_init',
                'styles' => [
                    'url' => $CFG->wwwroot . '/local/reactions/mobileapp/styles.css',
                    'version' => '6', // Bump on every change to styles.css or the app keeps its cached copy.
                ],
            ],
        ],
        'lang' => [
            ['addreaction', 'local_reactions'],
            ['noreactions', 'local_reactions'],
            ['reacttothispost', 'local_reactions'],
            ['removereaction', 'local_reactions'],
            ['totalreactions', 'local_reactions'],
        ],
    ],
];
