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
 * Renders cached reactions instantly from IndexedDB, then refreshes from the
 * web service and animates any differences.
 *
 * @module     local_reactions/discussion_list_reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';
import * as Cache from 'local_reactions/cache';
import {computeDiffs, renderToElement, buildTemplateContext, createPoller, collectIds} from 'local_reactions/utils';

/** @var {Object} Module-level config set during init. */
let config = {};

/** @var {number} Duration in ms to keep animation classes before removal. */
const ANIMATION_TIMEOUT = 2100;

/** @var {Object} Tracks last-rendered reaction data per discussion ID for diff computation during polling. */
let currentDataMap = {};

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
 * Insert an element after the badges div inside a discussion row, or append to the wrapper.
 *
 * @param {HTMLElement} row The discussion list item element.
 * @param {HTMLElement} element The element to insert.
 * @returns {boolean} Whether insertion succeeded.
 */
const insertAfterBadges = (row, element) => {
    const topicTh = row.querySelector('th.topic');
    if (!topicTh) {
        return false;
    }
    const wrapperDiv = topicTh.querySelector('.p-3');
    if (!wrapperDiv) {
        return false;
    }
    const childDivs = wrapperDiv.querySelectorAll(':scope > div');
    const badgesDiv = childDivs[1];
    if (badgesDiv) {
        badgesDiv.after(element);
    } else {
        wrapperDiv.appendChild(element);
    }
    return true;
};

/**
 * Create a skeleton placeholder element for a discussion list reactions bar.
 *
 * @returns {HTMLElement} The skeleton element.
 */
const createSkeleton = () => {
    const skeleton = document.createElement('div');
    skeleton.className =
        'local-reactions-bar local-reactions-bar-compact local-reactions-skeleton d-flex flex-wrap align-items-center';
    skeleton.setAttribute('data-region', 'reactions-skeleton');
    if (config.compactview) {
        const pill = document.createElement('span');
        pill.className = 'local-reactions-skeleton-pill local-reactions-skeleton-pill-compact';
        skeleton.appendChild(pill);
    } else {
        for (let i = 0; i < 2; i++) {
            const pill = document.createElement('span');
            pill.className = 'local-reactions-skeleton-pill';
            skeleton.appendChild(pill);
        }
    }
    return skeleton;
};

/**
 * Insert skeleton placeholders into discussion rows.
 *
 * @param {HTMLElement[]} rows The discussion list item elements.
 */
const insertSkeletons = (rows) => {
    rows.forEach((row) => {
        if (row.querySelector('[data-region="reactions-skeleton"]')) {
            return;
        }
        insertAfterBadges(row, createSkeleton());
    });
};

/**
 * Remove all remaining skeleton placeholders from the page.
 */
const removeSkeletons = () => {
    document.querySelectorAll('[data-region="reactions-skeleton"]').forEach((el) => el.remove());
};

/**
 * Find all discussion rows on the page and load their aggregated reactions.
 *
 * Uses a cache-first strategy: renders cached counts instantly, then fetches
 * fresh data from the web service and animates any differences.
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

    // Phase 1: Pre-render cached bars off-DOM (all async work before any DOM mutations).
    const cachedDiscussionIds = new Set();
    const cachedDataMap = {};
    const cacheAvailable = await Cache.isAvailable();
    const preRenderedBars = [];

    if (cacheAvailable) {
        const cacheKeys = discussionIds.map((id) => Cache.discussionKey(config.component, config.itemtype, id));
        const cached = await Cache.getMultiple(cacheKeys);

        for (const discussionId of discussionIds) {
            const key = Cache.discussionKey(config.component, config.itemtype, discussionId);
            const cachedData = cached.get(key);
            if (cachedData) {
                cachedDataMap[discussionId] = cachedData;
                cachedDiscussionIds.add(discussionId);
                try {
                    const context = buildTemplateContext(cachedData, config.emojis, {
                        compactview: config.compactview,
                    });
                    const {element: barElement, js} = await renderToElement(
                        'local_reactions/discussion_list_reactions', context
                    );
                    barElement.setAttribute('data-source', 'cache');
                    preRenderedBars.push({discussionId, barElement, js});
                } catch (err) {
                    cachedDiscussionIds.delete(discussionId);
                    delete cachedDataMap[discussionId];
                }
            }
        }
    }

    // Phase 2: Synchronous DOM batch - remove reservation, insert cached bars and skeletons
    // in one go so the browser repaints only once (no gap, no double-height).
    document.getElementById('local-reactions-reserve')?.remove();

    for (const {discussionId, barElement, js} of preRenderedBars) {
        const row = document.querySelector(
            `[data-region="discussion-list-item"][data-discussionid="${discussionId}"]`
        );
        if (!row || row.querySelector('[data-region="reactions-bar"]')) {
            continue;
        }
        insertAfterBadges(row, barElement);
        Templates.runTemplateJS(js);
    }

    const uncachedRows = [...rows].filter((row) => {
        const id = parseInt(row.getAttribute('data-discussionid'));
        return !cachedDiscussionIds.has(id);
    });
    if (uncachedRows.length > 0) {
        insertSkeletons(uncachedRows);
    }

    // Phase 3: Fetch fresh data from web service (for ALL discussions).
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

        // Phase 4: Update UI and cache.
        const cacheEntries = [];

        for (const discussionId of discussionIds) {
            const freshData = reactionsMap[discussionId] || {discussionid: discussionId, counts: []};

            if (cachedDiscussionIds.has(discussionId)) {
                // This discussion was rendered from cache - compute diffs and re-render with animation.
                const diffs = computeDiffs(cachedDataMap[discussionId], freshData);
                if (diffs.hasChanges) {
                    await rerenderBarWithAnimation(discussionId, freshData, diffs);
                } else {
                    // No count changes - just update data-source to live.
                    const row = document.querySelector(
                        `[data-region="discussion-list-item"][data-discussionid="${discussionId}"]`
                    );
                    const bar = row?.querySelector('[data-region="reactions-bar"]');
                    if (bar) {
                        bar.setAttribute('data-source', 'live');
                    }
                }
            } else {
                // This discussion was not cached - render normally (replaces skeleton).
                await renderBar(discussionId, freshData, false);
            }

            currentDataMap[discussionId] = freshData;

            if (cacheAvailable) {
                cacheEntries.push({
                    key: Cache.discussionKey(config.component, config.itemtype, discussionId),
                    data: {counts: freshData.counts || []},
                });
            }
        }

        if (cacheEntries.length > 0) {
            await Cache.setMultiple(cacheEntries);
        }
    } catch (err) {
        Notification.exception(err);
    }

    removeSkeletons();
    createPoller(config.pollinterval, pollDiscussionReactions);
};

/**
 * Build the template context and render the read-only reactions bar into a discussion row.
 *
 * @param {number} discussionId The forum discussion ID.
 * @param {Object} data Reaction data from the web service.
 * @param {boolean} fromCache Whether this render is from cached data.
 */
const renderBar = async(discussionId, data, fromCache) => {
    const row = document.querySelector(
        `[data-region="discussion-list-item"][data-discussionid="${discussionId}"]`
    );
    if (!row || row.querySelector('[data-region="reactions-bar"]')) {
        return;
    }

    const context = buildTemplateContext(data, config.emojis, {
        compactview: config.compactview,
    });

    try {
        const {element: barElement, js} = await renderToElement('local_reactions/discussion_list_reactions', context);
        barElement.setAttribute('data-source', fromCache ? 'cache' : 'live');

        // Replace skeleton if present, otherwise insert at the usual location.
        const skeleton = row.querySelector('[data-region="reactions-skeleton"]');
        if (skeleton) {
            skeleton.replaceWith(barElement);
        } else {
            insertAfterBadges(row, barElement);
        }
        Templates.runTemplateJS(js);
    } catch (err) {
        Notification.exception(err);
    }
};

/**
 * Re-render a discussion reactions bar with animation for changed counts.
 *
 * @param {number} discussionId The forum discussion ID.
 * @param {Object} freshData Fresh reaction data from the web service.
 * @param {Object} diffs The diff result from computeDiffs.
 */
const rerenderBarWithAnimation = async(discussionId, freshData, diffs) => {
    const row = document.querySelector(
        `[data-region="discussion-list-item"][data-discussionid="${discussionId}"]`
    );
    if (!row) {
        return;
    }

    const existingBar = row.querySelector('[data-region="reactions-bar"]');
    if (!existingBar) {
        return;
    }

    const context = buildTemplateContext(freshData, config.emojis, {
        compactview: config.compactview,
    });

    try {
        const {element: newBar, js} = await renderToElement('local_reactions/discussion_list_reactions', context);
        newBar.setAttribute('data-source', 'live');

        // Apply animation classes to changed pills.
        if (!config.compactview) {
            newBar.querySelectorAll('[data-emoji]').forEach((pill) => {
                const emoji = pill.getAttribute('data-emoji');
                if (diffs.changedEmojis.has(emoji)) {
                    pill.classList.add('local-reactions-count-changed');
                }
                if (diffs.newEmojis.has(emoji)) {
                    pill.classList.add('local-reactions-pill-new');
                }
            });
        } else {
            const compactPill = newBar.querySelector('.local-reactions-pill-compact');
            if (compactPill) {
                compactPill.classList.add('local-reactions-count-changed');
            }
        }

        existingBar.replaceWith(newBar);
        Templates.runTemplateJS(js);

        // Remove animation classes after animation completes.
        setTimeout(() => {
            newBar.querySelectorAll('.local-reactions-count-changed, .local-reactions-pill-new')
                .forEach((el) => {
                    el.classList.remove('local-reactions-count-changed', 'local-reactions-pill-new');
                });
        }, ANIMATION_TIMEOUT);
    } catch (err) {
        Notification.exception(err);
    }
};

/**
 * Poll the server for updated discussion reaction data and animate any changes.
 */
const pollDiscussionReactions = async() => {
    const discussionIds = collectIds('[data-region="discussion-list-item"]', 'data-discussionid');
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

        const cacheAvailable = await Cache.isAvailable();
        const cacheEntries = [];

        for (const discussionId of discussionIds) {
            const freshData = reactionsMap[discussionId] || {discussionid: discussionId, counts: []};
            const previousData = currentDataMap[discussionId];

            if (previousData) {
                const diffs = computeDiffs(previousData, freshData);
                if (diffs.hasChanges) {
                    await rerenderBarWithAnimation(discussionId, freshData, diffs);
                }
            }

            currentDataMap[discussionId] = freshData;

            if (cacheAvailable) {
                cacheEntries.push({
                    key: Cache.discussionKey(config.component, config.itemtype, discussionId),
                    data: {counts: freshData.counts || []},
                });
            }
        }

        if (cacheEntries.length > 0) {
            await Cache.setMultiple(cacheEntries);
        }
    } catch {
        // Silently ignore poll errors to avoid disrupting the user.
    }
};
