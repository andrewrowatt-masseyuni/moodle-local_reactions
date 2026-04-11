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

namespace local_reactions\output;

use html_writer;
use local_reactions\report;
use moodle_url;

/**
 * Renderer for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Render the full course-level reactions report.
     *
     * @param report $report The populated report data object.
     * @return string HTML output.
     */
    public function render_report(report $report): string {
        $ratiodata = $report->get_reactions_vs_posts_ratio();

        $html  = $this->output->heading(get_string('reactionsreport', 'local_reactions'));
        $html .= $this->output->heading(get_string('report:engagement', 'local_reactions'), 3);

        if ((int) $ratiodata['total_posts'] === 0) {
            $html .= html_writer::tag(
                'p',
                get_string('noposts', 'local_reactions'),
                ['class' => 'alert alert-info']
            );
            return $html;
        }

        $html .= $this->render_engagement_section($ratiodata);
        $html .= $this->render_active_participants_section($report->get_active_participants());
        $html .= $this->render_zero_reactions_section($report->get_posts_with_zero_reactions(20));
        $html .= $this->render_most_reacted_section($report->get_most_reacted_posts_this_week(10));

        return $html;
    }

    /**
     * Render the "total reactions vs posts" engagement block.
     *
     * @param array $ratiodata The array returned by report::get_reactions_vs_posts_ratio().
     * @return string HTML output.
     */
    private function render_engagement_section(array $ratiodata): string {
        $html  = html_writer::start_tag('div', ['class' => 'mb-3']);
        $html .= html_writer::tag(
            'p',
            get_string('totalreactions', 'local_reactions') . ': ' . $ratiodata['total_reactions']
        );
        $html .= html_writer::tag(
            'p',
            get_string('totalposts', 'local_reactions') . ': ' . $ratiodata['total_posts']
        );
        $html .= html_writer::tag(
            'p',
            get_string('reactionsvspostratio', 'local_reactions') . ': ' . $ratiodata['ratio'] . ':1'
        );
        $html .= html_writer::tag(
            'p',
            html_writer::tag('em', get_string('engagementhint', 'local_reactions')),
            ['class' => 'text-muted small']
        );
        $html .= html_writer::end_tag('div');
        return $html;
    }

    /**
     * Render the "active reactors vs active posters" block.
     *
     * @param array $participants The array returned by report::get_active_participants().
     * @return string HTML output.
     */
    private function render_active_participants_section(array $participants): string {
        $html  = $this->output->heading(get_string('report:activeparticipation', 'local_reactions'), 3);
        $html .= html_writer::start_tag('div', ['class' => 'mb-3']);
        $html .= html_writer::tag(
            'p',
            get_string('activereactors', 'local_reactions') . ': ' . $participants['active_reactors']
        );
        $html .= html_writer::tag(
            'p',
            get_string('activeposters', 'local_reactions') . ': ' . $participants['active_posters']
        );
        $html .= html_writer::tag(
            'p',
            html_writer::tag('em', get_string('participationhint', 'local_reactions')),
            ['class' => 'text-muted small']
        );
        $html .= html_writer::end_tag('div');
        return $html;
    }

    /**
     * Render the "posts with zero reactions" block.
     *
     * @param array $zeroreactions Records returned by report::get_posts_with_zero_reactions().
     * @return string HTML output.
     */
    private function render_zero_reactions_section(array $zeroreactions): string {
        $html = $this->output->heading(get_string('report:needsattention', 'local_reactions'), 3);

        if (empty($zeroreactions)) {
            $html .= html_writer::tag(
                'p',
                get_string('postswithallreactions', 'local_reactions'),
                ['class' => 'alert alert-success']
            );
            return $html;
        }

        $html .= html_writer::tag(
            'p',
            get_string('postswithzeroreactions', 'local_reactions') . ': ' . count($zeroreactions)
        );
        $html .= html_writer::tag(
            'p',
            html_writer::tag('em', get_string('needsattentionhint', 'local_reactions')),
            ['class' => 'text-muted small mb-2']
        );

        $headers = [
            html_writer::tag('th', get_string('postheader', 'local_reactions')),
            html_writer::tag('th', get_string('forumheader', 'local_reactions')),
            html_writer::tag('th', get_string('dateheader', 'local_reactions')),
        ];

        $rows = [];
        foreach ($zeroreactions as $post) {
            $rows[] = $this->render_post_row($post, false);
        }

        return $html . $this->render_post_table($headers, $rows);
    }

    /**
     * Render the "most-reacted posts this week" block.
     *
     * @param array $mostreacted Records returned by report::get_most_reacted_posts_this_week().
     * @return string HTML output.
     */
    private function render_most_reacted_section(array $mostreacted): string {
        $html = $this->output->heading(get_string('report:topperformers', 'local_reactions'), 3);

        if (empty($mostreacted)) {
            $html .= html_writer::tag(
                'p',
                get_string('noreactedposts', 'local_reactions'),
                ['class' => 'alert alert-info']
            );
            return $html;
        }

        $html .= html_writer::tag('p', get_string('mostreactedposts', 'local_reactions'));
        $html .= html_writer::tag(
            'p',
            html_writer::tag('em', get_string('topperformershint', 'local_reactions')),
            ['class' => 'text-muted small mb-2']
        );

        $headers = [
            html_writer::tag('th', get_string('postheader', 'local_reactions')),
            html_writer::tag('th', get_string('forumheader', 'local_reactions')),
            html_writer::tag('th', get_string('reactionsheader', 'local_reactions')),
            html_writer::tag('th', get_string('dateheader', 'local_reactions')),
        ];

        $rows = [];
        foreach ($mostreacted as $post) {
            $rows[] = $this->render_post_row($post, true);
        }

        return $html . $this->render_post_table($headers, $rows);
    }

    /**
     * Render a single post row for one of the report tables.
     *
     * @param \stdClass $post A post record with cmid, discussionid, subject, forumname, created, reactioncount.
     * @param bool $showcount Whether to include a reactioncount cell.
     * @return string HTML <tr> markup.
     */
    private function render_post_row(\stdClass $post, bool $showcount): string {
        $posturl = new moodle_url(
            '/mod/forum/discuss.php',
            ['d' => $post->discussionid],
            'p' . $post->id
        );
        $forumurl = new moodle_url('/mod/forum/view.php', ['id' => $post->cmid]);

        $cells  = html_writer::tag('td', html_writer::link($posturl, format_string($post->subject)));
        $cells .= html_writer::tag('td', html_writer::link($forumurl, format_string($post->forumname)));
        if ($showcount) {
            $cells .= html_writer::tag('td', $post->reactioncount);
        }
        $cells .= html_writer::tag('td', userdate($post->created, get_string('strftimedatetimeshort')));

        return html_writer::tag('tr', $cells);
    }

    /**
     * Wrap header + row HTML in a striped Bootstrap table.
     *
     * @param array $headers Header cell strings (already wrapped in <th>).
     * @param array $rows Row strings (already wrapped in <tr>).
     * @return string HTML table.
     */
    private function render_post_table(array $headers, array $rows): string {
        $thead = html_writer::tag('thead', html_writer::tag('tr', implode('', $headers)));
        $tbody = html_writer::tag('tbody', implode('', $rows));
        return html_writer::tag('table', $thead . $tbody, ['class' => 'table table-striped']);
    }
}
