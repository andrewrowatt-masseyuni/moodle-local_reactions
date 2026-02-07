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
 * AMD module for read-only aggregated reactions on the forum discussion list.
 *
 * @module     local_reactions/discussion_list_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';

/** @var {Object} Module-level config set during init. */
let config = {};

/**
 * Initialise the discussion list reactions module.
 *
 * @param {Object} cfg Configuration from PHP.
 */
export const init = (cfg) => {
    config = cfg;
    loadDiscussionReactions();
};

/**
 * Find all discussion rows on the page and load their aggregated reactions.
 */
const loadDiscussionReactions = async() => {
    const rows = document.querySelectorAll('[data-region="discussion-list-item"]');
    if (!rows.length) {
        return;
    }

    const discussionIds = [];
    rows.forEach((row) => {
        const discussionId = parseInt(row.getAttribute('data-discussionid'));
        if (discussionId) {
            discussionIds.push(discussionId);
        }
    });

    if (!discussionIds.length) {
        return;
    }

    try {
        const response = await Ajax.call([{
            methodname: 'local_reactions_get_discussion_reactions',
            args: {
                component: config.component,
                itemtype: config.itemtype,
                discussionids: discussionIds,
                contextid: config.contextid,
            },
        }])[0];

        const reactionsMap = {};
        response.items.forEach((item) => {
            reactionsMap[item.discussionid] = item;
        });

        for (const discussionId of discussionIds) {
            const data = reactionsMap[discussionId] || {discussionid: discussionId, counts: []};
            await renderBar(discussionId, data);
        }
    } catch (err) {
        Notification.exception(err);
    }
};

/**
 * Build the template context and render the read-only reactions bar into a discussion row.
 *
 * @param {number} discussionId The forum discussion ID.
 * @param {Object} data Reaction data from the web service.
 */
const renderBar = async(discussionId, data) => {
    // Only render if there are actual reactions to show.
    const hasAnyCount = data.counts.some((c) => c.count > 0);
    if (!hasAnyCount) {
        return;
    }

    const row = document.querySelector(
        `[data-region="discussion-list-item"][data-discussionid="${discussionId}"]`
    );
    if (!row || row.querySelector('[data-region="reactions-bar"]')) {
        return;
    }

    const context = buildTemplateContext(data);

    try {
        const {html, js} = await Templates.renderForPromise('local_reactions/reactions_bar', context);
        const container = document.createElement('div');
        container.innerHTML = html;
        const barElement = container.firstElementChild;

        // Compact display for the discussion list: no vertical spacing, scaled down.
        barElement.classList.remove('mt-2', 'mb-1');
        barElement.style.padding = '0';
        barElement.style.transform = 'scale(0.8)';
        barElement.style.transformOrigin = 'left center';

        // Insert below the discussion title, after the locked/timed badges div.
        const topicTh = row.querySelector('th.topic');
        if (!topicTh) {
            return;
        }
        const wrapperDiv = topicTh.querySelector('.p-3');
        if (!wrapperDiv) {
            return;
        }
        const childDivs = wrapperDiv.querySelectorAll(':scope > div');
        const badgesDiv = childDivs[1];
        if (badgesDiv) {
            badgesDiv.after(barElement);
        } else {
            wrapperDiv.appendChild(barElement);
        }
        Templates.runTemplateJS(js);
    } catch (err) {
        Notification.exception(err);
    }
};

/**
 * Build Mustache template context from aggregated reaction data.
 *
 * All pills are read-only: canreact is false, selected is false for all.
 *
 * @param {Object} data Reaction data.
 * @returns {Object} Template context.
 */
const buildTemplateContext = (data) => {
    const countsMap = {};
    data.counts.forEach((c) => {
        countsMap[c.emoji] = c.count;
    });

    const buttons = [];
    for (const [shortcode, unicode] of Object.entries(config.emojis)) {
        const count = countsMap[shortcode] || 0;
        buttons.push({
            shortcode: shortcode,
            unicode: unicode,
            count: count,
            hascount: count > 0,
            selected: false,
            canreact: false,
        });
    }

    return {
        buttons: buttons,
        canreact: false,
    };
};
