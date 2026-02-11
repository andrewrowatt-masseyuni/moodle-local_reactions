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
 * AMD module for emoji reactions on forum posts (GitHub-style picker).
 *
 * Renders cached reactions instantly from IndexedDB, then refreshes from the
 * web service and animates any differences.
 *
 * @module     local_reactions/reactions
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

/** @var {Object} Tracks last-rendered reaction data per post ID for diff computation during polling. */
let currentDataMap = {};

/** @var {boolean} Whether a toggle request is in-flight (prevents poll from racing with toggle). */
let toggleInProgress = false;

/** @var {boolean} Whether polling has been initialised. */
let pollingInitialised = false;

/**
 * Initialise the reactions module.
 *
 * @param {Object} cfg Configuration from PHP.
 */
export const init = (cfg) => {
    config = cfg;
    loadReactions();

    // Close any open picker when clicking outside.
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.local-reactions-picker-wrapper')) {
            closeAllPickers();
        }
    });

    // Re-load when new replies are dynamically added.
    const container = document.querySelector('[data-content="forum-discussion"]');
    if (container) {
        const observer = new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                for (const node of mutation.addedNodes) {
                    if (node.nodeType === Node.ELEMENT_NODE && node.querySelector('article[data-post-id]')) {
                        loadReactions();
                        return;
                    }
                }
            }
        });
        observer.observe(container, {childList: true, subtree: true});
    }
};

/**
 * Close all open emoji pickers.
 */
const closeAllPickers = () => {
    document.querySelectorAll('[data-region="reactions-picker"]:not([hidden])').forEach((picker) => {
        picker.hidden = true;
        const trigger = picker.closest('.local-reactions-picker-wrapper')
            ?.querySelector('[data-action="open-picker"]');
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        }
    });
};

/**
 * Insert an element before the post actions container, or append to post core.
 *
 * @param {HTMLElement} article The article element.
 * @param {HTMLElement} element The element to insert.
 */
const insertBeforeActions = (article, element) => {
    const actionsContainer = article.querySelector('[data-region="post-actions-container"]');
    if (actionsContainer) {
        actionsContainer.parentElement.insertBefore(element, actionsContainer);
    } else {
        const postCore = article.querySelector('[data-region-content="forum-post-core"]');
        if (postCore) {
            postCore.appendChild(element);
        }
    }
};

/**
 * Create a skeleton placeholder element for a reactions bar.
 *
 * @returns {HTMLElement} The skeleton element.
 */
const createSkeleton = () => {
    const skeleton = document.createElement('div');
    skeleton.className = 'local-reactions-bar local-reactions-skeleton d-flex flex-wrap align-items-center mt-2 mb-1';
    skeleton.setAttribute('data-region', 'reactions-skeleton');
    if (config.compactview) {
        const pill = document.createElement('span');
        pill.className = 'local-reactions-skeleton-pill local-reactions-skeleton-pill-compact';
        skeleton.appendChild(pill);
    } else {
        for (let i = 0; i < 3; i++) {
            const pill = document.createElement('span');
            pill.className = 'local-reactions-skeleton-pill';
            skeleton.appendChild(pill);
        }
    }
    return skeleton;
};

/**
 * Insert skeleton placeholders into articles that don't yet have a reactions bar.
 *
 * @param {number[]} postIds The post IDs to insert skeletons for.
 */
const insertSkeletons = (postIds) => {
    for (const postId of postIds) {
        const article = document.querySelector(`article[data-post-id="${postId}"]`);
        if (!article || article.querySelector('[data-region="reactions-skeleton"]')) {
            continue;
        }
        insertBeforeActions(article, createSkeleton());
    }
};

/**
 * Find all forum post articles on the page and load their reactions.
 *
 * Uses a cache-first strategy: renders cached counts instantly (read-only),
 * then fetches fresh data from the web service and animates any differences.
 */
const loadReactions = async() => {
    const articles = document.querySelectorAll('article[data-post-id]');
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

    // Phase 1: Try to render from cache (read-only, no interaction).
    const cachedPostIds = new Set();
    const cachedDataMap = {};
    const cacheAvailable = await Cache.isAvailable();

    if (cacheAvailable) {
        const cacheKeys = postIds.map((id) => Cache.itemKey(config.component, config.itemtype, id));
        const cached = await Cache.getMultiple(cacheKeys);

        const renderPromises = [];
        for (const postId of postIds) {
            const key = Cache.itemKey(config.component, config.itemtype, postId);
            const cachedData = cached.get(key);
            if (cachedData) {
                cachedDataMap[postId] = cachedData;
                cachedPostIds.add(postId);
                renderPromises.push(renderBar(postId, cachedData, true));
            }
        }
        await Promise.all(renderPromises);
    }

    // Phase 2: Insert skeletons only for uncached posts.
    const uncachedPostIds = postIds.filter((id) => !cachedPostIds.has(id));
    if (uncachedPostIds.length > 0) {
        insertSkeletons(uncachedPostIds);
    }

    // Phase 3: Fetch fresh data from web service (for ALL posts).
    try {
        const response = await Ajax.call([{
            methodname: 'local_reactions_get_reactions',
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

        // Phase 4: Update UI and cache.
        const cacheEntries = [];

        for (const postId of postIds) {
            const freshData = reactionsMap[postId] || {itemid: postId, userreactions: [], counts: []};

            if (cachedPostIds.has(postId)) {
                // This post was rendered from cache - compute diffs and re-render with animation.
                const diffs = computeDiffs(cachedDataMap[postId], freshData);
                await rerenderBarWithAnimation(postId, freshData, diffs);
            } else {
                // This post was not cached - render normally (replaces skeleton).
                await renderBar(postId, freshData, false);
            }

            currentDataMap[postId] = freshData;

            if (cacheAvailable) {
                cacheEntries.push({
                    key: Cache.itemKey(config.component, config.itemtype, postId),
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

    if (!pollingInitialised) {
        pollingInitialised = true;
        createPoller(config.pollinterval, pollReactions);
    }
};

/**
 * Build the template context and render the reactions bar into a post.
 *
 * @param {number} postId The forum post ID.
 * @param {Object} data Reaction data.
 * @param {boolean} fromCache Whether this render is from cached data (read-only).
 */
const renderBar = async(postId, data, fromCache) => {
    const article = document.querySelector(`article[data-post-id="${postId}"]`);
    if (!article || article.querySelector('[data-region="reactions-bar"]')) {
        return;
    }

    const context = buildTemplateContext(data, config.emojis, {
        canreact: config.canreact,
        compactview: config.compactview,
        userreactions: fromCache ? [] : (data.userreactions || []),
    });

    try {
        const {element: barElement, js} = await renderToElement('local_reactions/reactions_bar', context);
        barElement.setAttribute('data-source', fromCache ? 'cache' : 'live');

        // Replace skeleton if present, otherwise insert at the usual location.
        const skeleton = article.querySelector('[data-region="reactions-skeleton"]');
        if (skeleton) {
            skeleton.replaceWith(barElement);
        } else {
            insertBeforeActions(article, barElement);
        }
        Templates.runTemplateJS(js);
        if (fromCache) {
            // Disable all buttons so the picker and pills are visible but non-interactive.
            barElement.querySelectorAll('button').forEach((b) => b.setAttribute('disabled', 'disabled'));
        } else {
            bindHandlers(barElement, postId);
        }
    } catch (err) {
        Notification.exception(err);
    }
};

/**
 * Re-render a reactions bar with animation for changed counts.
 *
 * Always re-renders to enable interaction (cache renders are read-only).
 *
 * @param {number} postId The forum post ID.
 * @param {Object} freshData Fresh reaction data from the web service.
 * @param {Object} diffs The diff result from computeDiffs.
 */
const rerenderBarWithAnimation = async(postId, freshData, diffs) => {
    const article = document.querySelector(`article[data-post-id="${postId}"]`);
    if (!article) {
        return;
    }

    const existingBar = article.querySelector('[data-region="reactions-bar"]');
    if (!existingBar) {
        return;
    }

    const context = buildTemplateContext(freshData, config.emojis, {
        canreact: config.canreact,
        compactview: config.compactview,
        userreactions: freshData.userreactions || [],
    });

    try {
        const {element: newBar, js} = await renderToElement('local_reactions/reactions_bar', context);
        newBar.setAttribute('data-source', 'live');

        // Apply animation classes to changed pills before inserting into DOM.
        if (diffs.hasChanges) {
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
        }

        existingBar.replaceWith(newBar);
        Templates.runTemplateJS(js);
        bindHandlers(newBar, postId);

        // Remove animation classes after animation completes.
        if (diffs.hasChanges) {
            setTimeout(() => {
                newBar.querySelectorAll('.local-reactions-count-changed, .local-reactions-pill-new')
                    .forEach((el) => {
                        el.classList.remove('local-reactions-count-changed', 'local-reactions-pill-new');
                    });
            }, ANIMATION_TIMEOUT);
        }
    } catch (err) {
        Notification.exception(err);
    }
};

/**
 * Bind all event handlers for a reactions bar.
 *
 * @param {HTMLElement} barElement The reactions bar container.
 * @param {number} postId The forum post ID.
 */
const bindHandlers = (barElement, postId) => {
    // Picker trigger buttons (smiley trigger and compact pill both use data-action="open-picker").
    barElement.querySelectorAll('[data-action="open-picker"]').forEach((trigger) => {
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const picker = barElement.querySelector('[data-region="reactions-picker"]');
            if (!picker) {
                return;
            }
            const isOpen = !picker.hidden;
            closeAllPickers();
            if (!isOpen) {
                // Position the picker using fixed coordinates to escape overflow:hidden parents.
                const rect = trigger.getBoundingClientRect();
                picker.style.left = rect.left + 'px';
                picker.style.top = (rect.top - picker.offsetHeight - 6) + 'px';
                picker.hidden = false;
                // Re-calculate now that it's visible and has a real height.
                picker.style.top = (rect.top - picker.offsetHeight - 6) + 'px';
                trigger.setAttribute('aria-expanded', 'true');
            }
        });
    });

    // All toggle-reaction buttons (pills + picker buttons).
    if (config.canreact) {
        barElement.querySelectorAll('[data-action="toggle-reaction"]').forEach((btn) => {
            btn.addEventListener('click', async(e) => {
                e.preventDefault();
                e.stopPropagation();
                closeAllPickers();
                const emoji = btn.getAttribute('data-emoji');
                await toggleReaction(postId, emoji, barElement);
            });
        });
    }
};

/**
 * Toggle a reaction via the web service and rebuild the bar.
 *
 * @param {number} postId The forum post ID.
 * @param {string} emoji The emoji shortcode.
 * @param {HTMLElement} barElement The reactions bar to replace.
 */
const toggleReaction = async(postId, emoji, barElement) => {
    // Disable all interactive elements during the request.
    barElement.querySelectorAll('button').forEach((b) => b.setAttribute('disabled', 'disabled'));
    toggleInProgress = true;

    try {
        const response = await Ajax.call([{
            methodname: 'local_reactions_toggle_reaction',
            args: {
                component: config.component,
                itemtype: config.itemtype,
                itemid: postId,
                emoji: emoji,
            },
        }])[0];

        const freshData = {
            userreactions: response.userreactions,
            counts: response.counts,
        };

        // Rebuild the bar with fresh data to correctly show/hide pills.
        const context = buildTemplateContext(freshData, config.emojis, {
            canreact: config.canreact,
            compactview: config.compactview,
            userreactions: freshData.userreactions || [],
        });
        const {element: newBar, js} = await renderToElement('local_reactions/reactions_bar', context);
        newBar.setAttribute('data-source', 'live');

        barElement.replaceWith(newBar);
        Templates.runTemplateJS(js);
        bindHandlers(newBar, postId);

        currentDataMap[postId] = freshData;

        // Update the cache with counts only.
        const cacheAvailable = await Cache.isAvailable();
        if (cacheAvailable) {
            await Cache.set(
                Cache.itemKey(config.component, config.itemtype, postId),
                {counts: response.counts}
            );
        }
    } catch (err) {
        Notification.exception(err);
        // Re-enable buttons on error.
        barElement.querySelectorAll('button').forEach((b) => b.removeAttribute('disabled'));
    } finally {
        toggleInProgress = false;
    }
};

/**
 * Poll the server for updated reaction data and animate any changes.
 */
const pollReactions = async() => {
    if (toggleInProgress) {
        return;
    }

    const postIds = collectIds('article[data-post-id]', 'data-post-id');
    if (!postIds.length) {
        return;
    }

    try {
        const response = await Ajax.call([{
            methodname: 'local_reactions_get_reactions',
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

        const cacheAvailable = await Cache.isAvailable();
        const cacheEntries = [];

        for (const postId of postIds) {
            const freshData = reactionsMap[postId] || {itemid: postId, userreactions: [], counts: []};
            const previousData = currentDataMap[postId];

            if (previousData) {
                const diffs = computeDiffs(previousData, freshData);
                if (diffs.hasChanges) {
                    await rerenderBarWithAnimation(postId, freshData, diffs);
                }
            }

            currentDataMap[postId] = freshData;

            if (cacheAvailable) {
                cacheEntries.push({
                    key: Cache.itemKey(config.component, config.itemtype, postId),
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
