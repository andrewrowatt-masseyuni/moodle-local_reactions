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
import {
    computeDiffs, renderToElement, buildTemplateContext, createPoller, collectIds,
    applyDiffAnimations, clearAnimationClasses, updateCacheBatch,
} from 'local_reactions/utils';

/** @var {Object} Module-level config set during init. */
let config = {};

/** @var {Object} Tracks last-rendered reaction data per discussion ID for diff computation during polling. */
let currentDataMap = {};

/** @var {boolean} Whether polling has been initialised. */
let pollingInitialised = false;

/**
 * Initialise the discussion list reactions module.
 *
 * @param {Object} cfg Configuration from PHP.
 */
export const init = (cfg) => {
    config = cfg;
    loadDiscussionReactions();
    observeGradingPanel();
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
                    row?.querySelector('[data-region="reactions-bar"]')
                        ?.setAttribute('data-source', 'live');
                }
            } else {
                // This discussion was not cached - render normally (replaces skeleton).
                await renderBar(discussionId, freshData, false);
            }

            currentDataMap[discussionId] = freshData;
        }

        await updateCacheBatch(
            discussionIds,
            (id) => Cache.discussionKey(config.component, config.itemtype, id),
            currentDataMap,
        );
    } catch (err) {
        Notification.exception(err);
    }

    removeSkeletons();
    if (!pollingInitialised) {
        pollingInitialised = true;
        createPoller(config.pollinterval, pollDiscussionReactions);
    }
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

        applyDiffAnimations(newBar, diffs, config.compactview);

        existingBar.replaceWith(newBar);
        Templates.runTemplateJS(js);

        clearAnimationClasses(newBar);
    } catch (err) {
        Notification.exception(err);
    }
};

/**
 * Insert a read-only reactions bar into a forum post article within the grading panel.
 *
 * @param {HTMLElement} article The article[data-post-id] element.
 * @param {HTMLElement} element The reactions bar element to insert.
 */
const insertIntoGradingPost = (article, element) => {
    const actionsContainer = article.querySelector('[data-region="post-actions-container"]');
    if (actionsContainer) {
        actionsContainer.parentElement.insertBefore(element, actionsContainer);
        return;
    }
    const alignContainer = article.querySelector('.content-alignment-container');
    if (alignContainer) {
        alignContainer.appendChild(element);
        return;
    }
    const postCore = article.querySelector('[data-region-content="forum-post-core"]');
    if (postCore) {
        postCore.appendChild(element);
    }
};

/**
 * Load read-only reactions for posts displayed in the whole-forum grading panel.
 *
 * Collects post IDs from articles within the grading content region,
 * fetches per-post reactions, and renders compact read-only bars.
 *
 * @param {HTMLElement} container The grading module_content container.
 */
// Flag to suppress the grading MutationObserver while we insert reaction bars,
// preventing it from re-entering loadGradingReactions for our own DOM changes.
let gradingInserting = false;

const loadGradingReactions = async(container) => {
    const articles = container.querySelectorAll('.post-container article[data-post-id]');
    if (!articles.length) {
        return;
    }

    const postIds = [];
    articles.forEach((article) => {
        const postId = parseInt(article.getAttribute('data-post-id'));
        if (postId && !article.querySelector('[data-region="reactions-bar"]')) {
            postIds.push(postId);
        }
    });

    if (!postIds.length) {
        return;
    }

    const compactview = config.compactview_grading ?? config.compactview;

    try {
        const response = await Ajax.call([{
            methodname: 'local_reactions_get_reactions_for_grading',
            args: {
                component: config.component,
                itemtype: config.itemtype,
                itemids: postIds,
                contextid: config.contextid,
            },
        }])[0];

        const reactionsMap = {};
        response.items.forEach((item) => {
            reactionsMap[item.itemid] = item;
        });

        gradingInserting = true;
        try {
            for (const postId of postIds) {
                const article = container.querySelector(`.post-container article[data-post-id="${postId}"]`);
                if (!article || article.querySelector('[data-region="reactions-bar"]')) {
                    continue;
                }

                const data = reactionsMap[postId] || {itemid: postId, counts: [], userreactions: []};
                const context = buildTemplateContext(data, config.emojis, {
                    compactview: compactview,
                    userreactions: config.showselfreactionsgrading ? (data.userreactions || []) : [],
                });

                const {element: barElement, js} = await renderToElement(
                    'local_reactions/discussion_list_reactions', context
                );
                barElement.setAttribute('data-source', 'live');

                insertIntoGradingPost(article, barElement);
                Templates.runTemplateJS(js);
            }
        } finally {
            gradingInserting = false;
        }
    } catch (err) {
        Notification.exception(err);
    }
};

/**
 * Observe the DOM for the grading panel to appear and load reactions when posts are inserted.
 *
 * The whole-forum grading panel dynamically inserts posts into
 * [data-region="module_content"]. This observer detects those insertions
 * and triggers reaction loading for each batch of posts.
 */
const observeGradingPanel = () => {
    const observer = new MutationObserver((mutations) => {
        if (gradingInserting) {
            return;
        }
        for (const mutation of mutations) {
            // Handle content replaced inside an existing module_content region.
            const target = mutation.target.closest?.('[data-region="module_content"]') || mutation.target;
            if (target.matches?.('[data-region="module_content"]')
                && target.querySelector('.post-containerarticle[data-post-id]')) {
                loadGradingReactions(target);
                return;
            }
            // Handle new nodes added that contain posts.
            for (const node of mutation.addedNodes) {
                if (node.nodeType !== Node.ELEMENT_NODE) {
                    continue;
                }
                const container = node.closest?.('[data-region="module_content"]')
                    || node.querySelector?.('[data-region="module_content"]');
                if (container && container.querySelector('.post-container article[data-post-id]')) {
                    loadGradingReactions(container);
                    return;
                }
                // The added node itself may be inside the module_content region.
                if (node.matches?.('.post-container article[data-post-id]')
                    || node.querySelector?.('.post-container article[data-post-id]')) {
                    const moduleContent = node.closest?.('[data-region="module_content"]');
                    if (moduleContent) {
                        loadGradingReactions(moduleContent);
                        return;
                    }
                }
            }
        }
    });

    observer.observe(document.body, {childList: true, subtree: true});
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
        }

        await updateCacheBatch(
            discussionIds,
            (id) => Cache.discussionKey(config.component, config.itemtype, id),
            currentDataMap,
        );
    } catch {
        // Silently ignore poll errors to avoid disrupting the user.
    }
};
