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
 * Web service definitions for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_reactions_toggle_reaction' => [
        'classname' => 'local_reactions\external\toggle_reaction',
        'description' => 'Toggle an emoji reaction on a forum post',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_reactions_get_reactions' => [
        'classname' => 'local_reactions\external\get_reactions',
        'description' => 'Get emoji reactions for forum posts',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
