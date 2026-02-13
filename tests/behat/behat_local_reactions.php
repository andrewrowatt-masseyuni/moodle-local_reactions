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
 * Behat step definitions for local_reactions.
 *
 * @package    local_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;
use Behat\Mink\Exception\ElementNotFoundException;

/**
 * Behat step definitions for local_reactions.
 */
class behat_local_reactions extends behat_base {
    /**
     * Navigate directly to the reactions report for a course.
     *
     * @Given I am on the reactions report for :coursefullname
     * @param string $coursefullname The full name of the course.
     */
    public function i_am_on_the_reactions_report_for(string $coursefullname): void {
        global $DB;
        $courseid = $DB->get_field('course', 'id', ['fullname' => $coursefullname], MUST_EXIST);
        $url = new moodle_url('/local/reactions/report.php', ['id' => $courseid]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Wait for reactions bars to be loaded via AJAX on the page.
     *
     * @Given I wait for reactions to load
     */
    public function i_wait_for_reactions_to_load(): void {
        $this->ensure_element_exists('[data-region="reactions-bar"]', 'css_element');
    }

    /**
     * Check that an emoji pill shows a specific count.
     *
     * @Then the :emoji reaction count should be :count
     * @param string $emoji The emoji shortcode (e.g. thumbsup, heart).
     * @param int $count The expected count.
     */
    public function the_reaction_count_should_be(string $emoji, int $count): void {
        $selector = "[data-emoji='{$emoji}'][data-count='{$count}']";
        $this->ensure_element_exists($selector, 'css_element');
    }

    /**
     * Check that the compact pill shows a specific total count.
     *
     * @Then the compact reaction total should be :count
     * @param int $count The expected total count.
     */
    public function the_compact_reaction_total_should_be(int $count): void {
        $selector = ".local-reactions-pill-compact[data-total-count='{$count}']";
        $this->ensure_element_exists($selector, 'css_element');
    }

    /**
     * Open the emoji picker by clicking the smiley trigger.
     *
     * @When I open the reactions picker
     */
    public function i_open_the_reactions_picker(): void {
        $trigger = $this->find('css_element', '.local-reactions-trigger[data-action="open-picker"]');
        $trigger->click();
        // Wait for picker to become visible.
        $this->ensure_element_exists('[data-region="reactions-picker"]:not([hidden])', 'css_element');
    }

    /**
     * Click an emoji in the reactions picker.
     *
     * @When I react with :emoji
     * @param string $emoji The emoji shortcode to click.
     */
    public function i_react_with(string $emoji): void {
        $button = $this->find(
            'css_element',
            "[data-region='reactions-picker'] [data-emoji='{$emoji}']"
        );
        $button->click();
        // Wait for AJAX to complete and bar to rebuild.
        $this->getSession()->wait(
            2000,
            'document.querySelector("[data-region=\'reactions-bar\'] button:not([disabled])")'
        );
    }

    /**
     * Check that a specific emoji pill does not exist on the page.
     *
     * @Then I should not see the :emoji reaction pill
     * @param string $emoji The emoji shortcode.
     */
    public function i_should_not_see_the_reaction_pill(string $emoji): void {
        try {
            $this->find('css_element', ".local-reactions-pill[data-emoji='{$emoji}']");
            throw new ExpectationException(
                "The '{$emoji}' reaction pill was found but should not exist.",
                $this->getSession()
            );
        } catch (ElementNotFoundException $e) {
            // Expected: the pill should not exist on the page.
            return;
        }
    }

    /**
     * Navigate to the reply page (post.php?reply=ID) for a post by its subject.
     *
     * @Given I am on the reply page for :postsubject
     * @param string $postsubject The subject of the post to reply to.
     */
    public function i_am_on_the_reply_page_for(string $postsubject): void {
        global $DB;
        $postid = $DB->get_field('forum_posts', 'id', ['subject' => $postsubject], MUST_EXIST);
        $url = new moodle_url('/mod/forum/post.php', ['reply' => $postid]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Wait for live (non-cached) reactions to finish loading.
     *
     * @Given I wait for live reactions to load
     */
    public function i_wait_for_live_reactions_to_load(): void {
        $this->ensure_element_exists('[data-region="reactions-bar"][data-source="live"]', 'css_element');
    }

    /**
     * Check that a reactions bar has a specific data-source attribute value.
     *
     * @Then the reactions bar should be from :source
     * @param string $source The expected data-source value (cache or live).
     */
    public function the_reactions_bar_should_be_from(string $source): void {
        $selector = "[data-region=\"reactions-bar\"][data-source=\"{$source}\"]";
        $this->ensure_element_exists($selector, 'css_element');
    }
}
