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
 * Admin checkbox that locks itself "on" once any user has accumulated more than
 * one reaction on a single blog post — switching back to single-reaction mode
 * after that point would silently drop existing reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_allowmultiplereactionsblog extends \admin_setting_configcheckbox {
    #[\Override]
    public function write_setting($data) {
        $current = (string) $this->get_setting();
        $newvalue = ((string) $data === $this->yes) ? $this->yes : $this->no;
        if (
            $current === $this->yes
                && $newvalue === $this->no
                && manager::blog_has_multiple_reactions_per_user()
        ) {
            return get_string('settings:allowmultiplereactionsblog_locked', 'local_reactions');
        }
        return parent::write_setting($data);
    }
}
