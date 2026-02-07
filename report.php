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
 * Reactions report page.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/reactions:viewreport', $context);

$PAGE->set_url('/local/reactions/report.php', ['id' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('reactionsreport', 'local_reactions'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('report');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reactionsreport', 'local_reactions'));

// Get report data.
$report = new \local_reactions\report($courseid);

// 1. Total reactions vs posts ratio.
$ratiodata = $report->get_reactions_vs_posts_ratio();
echo $OUTPUT->heading(get_string('report:engagement', 'local_reactions'), 3);

if ($ratiodata['total_posts'] == 0) {
    echo html_writer::tag('p', get_string('noposts', 'local_reactions'), ['class' => 'alert alert-info']);
} else {
    echo html_writer::start_tag('div', ['class' => 'mb-3']);
    echo html_writer::tag('p', 
        get_string('totalreactions', 'local_reactions') . ': ' . $ratiodata['total_reactions']);
    echo html_writer::tag('p', 
        get_string('totalposts', 'local_reactions') . ': ' . $ratiodata['total_posts']);
    echo html_writer::tag('p', 
        get_string('reactionsvspostratio', 'local_reactions') . ': ' . $ratiodata['ratio'] . ':1');
    echo html_writer::tag('p', 
        html_writer::tag('em', 'Shows if students are reading but not contributing.'), 
        ['class' => 'text-muted small']);
    echo html_writer::end_tag('div');

    // 2. Active reactors vs active posters.
    $participantdata = $report->get_active_participants();
    echo $OUTPUT->heading(get_string('report:activeparticipation', 'local_reactions'), 3);
    echo html_writer::start_tag('div', ['class' => 'mb-3']);
    echo html_writer::tag('p', 
        get_string('activereactors', 'local_reactions') . ': ' . $participantdata['active_reactors']);
    echo html_writer::tag('p', 
        get_string('activeposters', 'local_reactions') . ': ' . $participantdata['active_posters']);
    echo html_writer::tag('p', 
        html_writer::tag('em', 'Identifies different participation styles.'), 
        ['class' => 'text-muted small']);
    echo html_writer::end_tag('div');

    // 3. Posts with zero reactions.
    $zeroreactions = $report->get_posts_with_zero_reactions(20);
    echo $OUTPUT->heading(get_string('report:needsattention', 'local_reactions'), 3);
    
    if (empty($zeroreactions)) {
        echo html_writer::tag('p', 
            'All posts have received at least one reaction!', 
            ['class' => 'alert alert-success']);
    } else {
        echo html_writer::tag('p', 
            get_string('postswithzeroreactions', 'local_reactions') . ': ' . count($zeroreactions));
        echo html_writer::tag('p', 
            html_writer::tag('em', 'Might need teacher response to kickstart engagement.'), 
            ['class' => 'text-muted small mb-2']);
        
        echo html_writer::start_tag('table', ['class' => 'table table-striped']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Post');
        echo html_writer::tag('th', 'Forum');
        echo html_writer::tag('th', 'Date');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        
        foreach ($zeroreactions as $post) {
            $posturl = new moodle_url('/mod/forum/discuss.php', [
                'd' => $post->discussionid,
            ], 'p' . $post->id);
            $forumurl = new moodle_url('/mod/forum/view.php', ['id' => $post->cmid]);
            
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', 
                html_writer::link($posturl, format_string($post->subject)));
            echo html_writer::tag('td', 
                html_writer::link($forumurl, format_string($post->forumname)));
            echo html_writer::tag('td', userdate($post->created, get_string('strftimedatetimeshort')));
            echo html_writer::end_tag('tr');
        }
        
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }

    // 4. Most-reacted posts this week.
    $mostreacted = $report->get_most_reacted_posts_this_week(10);
    echo $OUTPUT->heading(get_string('report:topperformers', 'local_reactions'), 3);
    
    if (empty($mostreacted)) {
        echo html_writer::tag('p', 
            get_string('noreactedposts', 'local_reactions'), 
            ['class' => 'alert alert-info']);
    } else {
        echo html_writer::tag('p', 
            get_string('mostreactedposts', 'local_reactions'));
        echo html_writer::tag('p', 
            html_writer::tag('em', 'Quick pulse check on what\'s engaging students.'), 
            ['class' => 'text-muted small mb-2']);
        
        echo html_writer::start_tag('table', ['class' => 'table table-striped']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Post');
        echo html_writer::tag('th', 'Forum');
        echo html_writer::tag('th', 'Reactions');
        echo html_writer::tag('th', 'Date');
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        
        foreach ($mostreacted as $post) {
            $posturl = new moodle_url('/mod/forum/discuss.php', [
                'd' => $post->discussionid,
            ], 'p' . $post->id);
            $forumurl = new moodle_url('/mod/forum/view.php', ['id' => $post->cmid]);
            
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', 
                html_writer::link($posturl, format_string($post->subject)));
            echo html_writer::tag('td', 
                html_writer::link($forumurl, format_string($post->forumname)));
            echo html_writer::tag('td', $post->reactioncount);
            echo html_writer::tag('td', userdate($post->created, get_string('strftimedatetimeshort')));
            echo html_writer::end_tag('tr');
        }
        
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }
}

echo $OUTPUT->footer();
