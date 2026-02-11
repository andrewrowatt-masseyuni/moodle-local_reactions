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

/** @var {Object} Module-level config set during init. */
let config = {};

/** @var {number} Duration in ms to keep animation classes before removal. */
const ANIMATION_TIMEOUT = 2100;

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
        const skeleton = createSkeleton();
        const actionsContainer = article.querySelector('[data-region="post-actions-container"]');
        if (actionsContainer) {
            actionsContainer.parentElement.insertBefore(skeleton, actionsContainer);
        } else {
            const postCore = article.querySelector('[data-region-content="forum-post-core"]');
            if (postCore) {
                postCore.appendChild(skeleton);
            }
        }
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

        for (const postId of postIds) {
            const key = Cache.itemKey(config.component, config.itemtype, postId);
            const cachedData = cached.get(key);
            if (cachedData) {
                cachedDataMap[postId] = cachedData;
                await renderBar(postId, cachedData, true);
                cachedPostIds.add(postId);
            }
        }
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

    const context = buildTemplateContext(data, fromCache);

    try {
        const {html, js} = await Templates.renderForPromise('local_reactions/reactions_bar', context);
        const container = document.createElement('div');
        container.innerHTML = html;
        const barElement = container.firstElementChild;
        barElement.setAttribute('data-source', fromCache ? 'cache' : 'live');

        // Replace skeleton if present, otherwise insert at the usual location.
        const skeleton = article.querySelector('[data-region="reactions-skeleton"]');
        if (skeleton) {
            skeleton.replaceWith(barElement);
        } else {
            const actionsContainer = article.querySelector('[data-region="post-actions-container"]');
            if (actionsContainer) {
                actionsContainer.parentElement.insertBefore(barElement, actionsContainer);
            } else {
                const postCore = article.querySelector('[data-region-content="forum-post-core"]');
                if (!postCore) {
                    return;
                }
                postCore.appendChild(barElement);
            }
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
 * Compare cached and fresh reaction data to find differences.
 *
 * @param {Object} cachedData Cached reaction data (counts only).
 * @param {Object} freshData Fresh reaction data from web service.
 * @returns {Object} Diffs object.
 */
const computeDiffs = (cachedData, freshData) => {
    const cachedCounts = {};
    (cachedData?.counts || []).forEach((c) => {
        cachedCounts[c.emoji] = c.count;
    });

    const freshCounts = {};
    (freshData?.counts || []).forEach((c) => {
        freshCounts[c.emoji] = c.count;
    });

    const changedEmojis = new Set();
    const newEmojis = new Set();
    const removedEmojis = new Set();

    for (const emoji of Object.keys(freshCounts)) {
        if (!(emoji in cachedCounts)) {
            if (freshCounts[emoji] > 0) {
                newEmojis.add(emoji);
            }
        } else if (freshCounts[emoji] !== cachedCounts[emoji]) {
            changedEmojis.add(emoji);
        }
    }

    for (const emoji of Object.keys(cachedCounts)) {
        if (cachedCounts[emoji] > 0 && (!(emoji in freshCounts) || freshCounts[emoji] === 0)) {
            removedEmojis.add(emoji);
        }
    }

    const hasChanges = changedEmojis.size > 0 || newEmojis.size > 0 || removedEmojis.size > 0;

    return {hasChanges, changedEmojis, newEmojis, removedEmojis};
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

    const context = buildTemplateContext(freshData, false);

    try {
        const {html, js} = await Templates.renderForPromise('local_reactions/reactions_bar', context);
        const container = document.createElement('div');
        container.innerHTML = html;
        const newBar = container.firstElementChild;
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
 * Build Mustache template context from reaction data.
 *
 * @param {Object} data Reaction data.
 * @param {boolean} fromCache Whether rendering from cache (forces selected=false for all pills).
 * @returns {Object} Template context.
 */
const buildTemplateContext = (data, fromCache) => {
    const countsMap = {};
    data.counts.forEach((c) => {
        countsMap[c.emoji] = c.count;
    });

    const userReactions = fromCache ? [] : (data.userreactions || []);
    const buttons = [];
    let totalCount = 0;
    const reactedEmojis = [];
    let hasAnySelected = false;

    for (const [shortcode, unicode] of Object.entries(config.emojis)) {
        const count = countsMap[shortcode] || 0;
        const isSelected = userReactions.includes(shortcode);
        buttons.push({
            shortcode: shortcode,
            unicode: unicode,
            count: count,
            hascount: count > 0,
            selected: isSelected,
            canreact: config.canreact,
        });
        if (count > 0) {
            totalCount += count;
            reactedEmojis.push({unicode: unicode});
            if (isSelected) {
                hasAnySelected = true;
            }
        }
    }

    return {
        buttons: buttons,
        canreact: config.canreact,
        compactview: config.compactview,
        hasanycount: totalCount > 0,
        totalcount: totalCount,
        reactedEmojis: reactedEmojis,
        selected: hasAnySelected,
    };
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
        const context = buildTemplateContext(freshData, false);
        const {html, js} = await Templates.renderForPromise('local_reactions/reactions_bar', context);
        const container = document.createElement('div');
        container.innerHTML = html;
        const newBar = container.firstElementChild;
        newBar.setAttribute('data-source', 'live');

        barElement.replaceWith(newBar);
        Templates.runTemplateJS(js);
        bindHandlers(newBar, postId);

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
    }
};
