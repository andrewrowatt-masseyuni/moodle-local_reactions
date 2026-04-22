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
 * AMD module for emoji reactions (GitHub-style picker).
 *
 * Generic across content providers (forum posts, blog entries, etc.) — all DOM
 * discovery is driven by the `selectors` config block supplied by the provider:
 *   - `item`: CSS selector that matches each reactable item's root element.
 *   - `itemIdAttr` OR `itemIdPrefix`: how to extract an integer ID from an item
 *     element (attribute value, or strip prefix from element id).
 *   - `insertBeforeSelector`: preferred anchor — bar is inserted before it.
 *   - `appendFallbackSelectors`: ordered list of fallbacks — bar is appended.
 *   - `mutationRoot` (optional): observe this for dynamically added items.
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
import {
    computeDiffs, renderToElement, buildTemplateContext, createPoller,
    applyDiffAnimations, clearAnimationClasses, updateCacheBatch,
} from 'local_reactions/utils';

/** @var {Object} Module-level config set during init. */
let config = {};

/** @var {Object} Tracks last-rendered reaction data per item ID for diff computation during polling. */
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

    // Re-load when new items (e.g. replies) are dynamically added. Only applies to providers
    // that expose a mutation root (forum); blog entries aren't dynamically injected.
    const mutationRootSelector = config.selectors && config.selectors.mutationRoot;
    if (mutationRootSelector) {
        const container = document.querySelector(mutationRootSelector);
        if (container) {
            const itemSelector = config.selectors.item;
            const observer = new MutationObserver((mutations) => {
                for (const mutation of mutations) {
                    for (const node of mutation.addedNodes) {
                        if (node.nodeType === Node.ELEMENT_NODE && node.querySelector(itemSelector)) {
                            loadReactions();
                            return;
                        }
                    }
                }
            });
            observer.observe(container, {childList: true, subtree: true});
        }
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
 * Extract the integer item ID from an item element using the configured strategy.
 *
 * Supports two strategies declared in config.selectors:
 *   - `itemIdAttr`: read the value of the named attribute (e.g. `data-post-id`).
 *   - `itemIdPrefix`: strip a fixed prefix from the element's `id` (e.g. `b123` → `123`).
 *
 * @param {HTMLElement} el The item element.
 * @returns {number} Parsed integer ID, or NaN if it could not be determined.
 */
const getItemId = (el) => {
    const selectors = config.selectors || {};
    if (selectors.itemIdAttr) {
        return parseInt(el.getAttribute(selectors.itemIdAttr));
    }
    if (selectors.itemIdPrefix && el.id && el.id.startsWith(selectors.itemIdPrefix)) {
        return parseInt(el.id.slice(selectors.itemIdPrefix.length));
    }
    return NaN;
};

/**
 * Look up the item element for a given item ID using the configured strategy.
 *
 * @param {number} itemId
 * @returns {HTMLElement|null}
 */
const getItemElement = (itemId) => {
    const selectors = config.selectors || {};
    if (selectors.itemIdAttr) {
        return document.querySelector(`[${selectors.itemIdAttr}="${itemId}"]`);
    }
    if (selectors.itemIdPrefix) {
        return document.getElementById(`${selectors.itemIdPrefix}${itemId}`);
    }
    return null;
};

/**
 * Collect integer IDs for every item currently on the page.
 *
 * @returns {number[]}
 */
const collectItemIds = () => {
    const ids = [];
    const itemSelector = (config.selectors && config.selectors.item) || '';
    if (!itemSelector) {
        return ids;
    }
    document.querySelectorAll(itemSelector).forEach((el) => {
        const id = getItemId(el);
        if (id) {
            ids.push(id);
        }
    });
    return ids;
};

/**
 * Insert an element at the provider's preferred position within an item.
 *
 * Tries `insertBeforeSelector` first (inserts element before the matched anchor's position,
 * using the anchor's parent), then falls back to appending into the first matching
 * `appendFallbackSelectors` entry.
 *
 * @param {HTMLElement} itemEl The item root element.
 * @param {HTMLElement} element The element to insert.
 */
const insertBar = (itemEl, element) => {
    const selectors = config.selectors || {};
    if (selectors.insertBeforeSelector) {
        const anchor = itemEl.querySelector(selectors.insertBeforeSelector);
        if (anchor && anchor.parentElement) {
            anchor.parentElement.insertBefore(element, anchor);
            return;
        }
    }
    const fallbacks = selectors.appendFallbackSelectors || [];
    for (const fallbackSelector of fallbacks) {
        const target = itemEl.querySelector(fallbackSelector);
        if (target) {
            target.appendChild(element);
            return;
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
 * Insert skeleton placeholders into items that don't yet have a reactions bar.
 *
 * @param {number[]} itemIds The item IDs to insert skeletons for.
 */
const insertSkeletons = (itemIds) => {
    for (const itemId of itemIds) {
        const itemEl = getItemElement(itemId);
        if (!itemEl || itemEl.querySelector('[data-region="reactions-skeleton"]')) {
            continue;
        }
        insertBar(itemEl, createSkeleton());
    }
};

/**
 * Find all reactable items on the page and load their reactions.
 *
 * Uses a cache-first strategy: renders cached counts instantly (read-only),
 * then fetches fresh data from the web service and animates any differences.
 */
const loadReactions = async() => {
    const itemSelector = (config.selectors && config.selectors.item) || '';
    if (!itemSelector) {
        return;
    }
    const items = document.querySelectorAll(itemSelector);
    if (!items.length) {
        return;
    }

    const itemIds = [];
    items.forEach((itemEl) => {
        const itemId = getItemId(itemEl);
        if (itemId && !itemEl.querySelector('[data-region="reactions-bar"]')) {
            itemIds.push(itemId);
        }
    });

    if (!itemIds.length) {
        return;
    }

    // Phase 1: Try to render from cache (read-only, no interaction).
    const cachedItemIds = new Set();
    const cachedDataMap = {};
    const cacheAvailable = await Cache.isAvailable();

    if (cacheAvailable) {
        const cacheKeys = itemIds.map((id) => Cache.itemKey(config.component, config.itemtype, id));
        const cached = await Cache.getMultiple(cacheKeys);

        const renderPromises = [];
        for (const itemId of itemIds) {
            const key = Cache.itemKey(config.component, config.itemtype, itemId);
            const cachedData = cached.get(key);
            if (cachedData) {
                cachedDataMap[itemId] = cachedData;
                cachedItemIds.add(itemId);
                renderPromises.push(renderBar(itemId, cachedData, true));
            }
        }
        await Promise.all(renderPromises);
    }

    // Phase 2: Remove CSS reserve skeleton and insert JS skeletons for uncached items.
    document.getElementById('local-reactions-reserve')?.remove();
    const uncachedItemIds = itemIds.filter((id) => !cachedItemIds.has(id));
    if (uncachedItemIds.length > 0) {
        insertSkeletons(uncachedItemIds);
    }

    // Phase 3: Fetch fresh data from web service (for ALL items).
    try {
        const response = await Ajax.call([{
            methodname: 'local_reactions_get_reactions',
            args: {
                component: config.component,
                itemtype: config.itemtype,
                itemids: itemIds,
                contextid: config.contextid,
            },
        }])[0];

        const reactionsMap = {};
        response.items.forEach((item) => {
            reactionsMap[item.itemid] = item;
        });

        // Phase 4: Update UI and cache.
        for (const itemId of itemIds) {
            const freshData = reactionsMap[itemId] || {itemid: itemId, userreactions: [], counts: []};

            if (cachedItemIds.has(itemId)) {
                // This item was rendered from cache - compute diffs and re-render with animation.
                const diffs = computeDiffs(cachedDataMap[itemId], freshData);
                await rerenderBarWithAnimation(itemId, freshData, diffs);
            } else {
                // This item was not cached - render normally (replaces skeleton).
                await renderBar(itemId, freshData, false);
            }

            currentDataMap[itemId] = freshData;
        }

        await updateCacheBatch(
            itemIds,
            (id) => Cache.itemKey(config.component, config.itemtype, id),
            currentDataMap,
        );
    } catch (err) {
        Notification.exception(err);
    }

    if (!pollingInitialised) {
        pollingInitialised = true;
        createPoller(config.pollinterval, pollReactions);
    }
};

/**
 * Build the template context and render the reactions bar into an item.
 *
 * @param {number} itemId The item ID.
 * @param {Object} data Reaction data.
 * @param {boolean} fromCache Whether this render is from cached data (read-only).
 */
const renderBar = async(itemId, data, fromCache) => {
    const itemEl = getItemElement(itemId);
    if (!itemEl || itemEl.querySelector('[data-region="reactions-bar"]')) {
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
        const skeleton = itemEl.querySelector('[data-region="reactions-skeleton"]');
        if (skeleton) {
            skeleton.replaceWith(barElement);
        } else {
            insertBar(itemEl, barElement);
        }
        Templates.runTemplateJS(js);
        if (fromCache) {
            // Disable all buttons so the picker and pills are visible but non-interactive.
            barElement.querySelectorAll('button').forEach((b) => b.setAttribute('disabled', 'disabled'));
        } else {
            bindHandlers(barElement, itemId);
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
 * @param {number} itemId The item ID.
 * @param {Object} freshData Fresh reaction data from the web service.
 * @param {Object} diffs The diff result from computeDiffs.
 */
const rerenderBarWithAnimation = async(itemId, freshData, diffs) => {
    const itemEl = getItemElement(itemId);
    if (!itemEl) {
        return;
    }

    const existingBar = itemEl.querySelector('[data-region="reactions-bar"]');
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

        applyDiffAnimations(newBar, diffs, config.compactview);

        existingBar.replaceWith(newBar);
        Templates.runTemplateJS(js);
        bindHandlers(newBar, itemId);

        if (diffs.hasChanges) {
            clearAnimationClasses(newBar);
        }
    } catch (err) {
        Notification.exception(err);
    }
};

/**
 * Bind all event handlers for a reactions bar.
 *
 * @param {HTMLElement} barElement The reactions bar container.
 * @param {number} itemId The item ID.
 */
const bindHandlers = (barElement, itemId) => {
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
                picker.hidden = false;
                // Calculate top now that it's visible and has a real height.
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
                await toggleReaction(itemId, emoji, barElement);
            });
        });
    }
};

/**
 * Toggle a reaction via the web service and rebuild the bar.
 *
 * @param {number} itemId The item ID.
 * @param {string} emoji The emoji shortcode.
 * @param {HTMLElement} barElement The reactions bar to replace.
 */
const toggleReaction = async(itemId, emoji, barElement) => {
    // Disable all interactive elements during the request.
    barElement.querySelectorAll('button').forEach((b) => b.setAttribute('disabled', 'disabled'));
    toggleInProgress = true;

    try {
        const response = await Ajax.call([{
            methodname: 'local_reactions_toggle_reaction',
            args: {
                component: config.component,
                itemtype: config.itemtype,
                itemid: itemId,
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
        bindHandlers(newBar, itemId);

        currentDataMap[itemId] = freshData;

        await updateCacheBatch(
            [itemId],
            (id) => Cache.itemKey(config.component, config.itemtype, id),
            currentDataMap,
        );
    } catch (err) {
        Notification.exception(err);
        // Re-enable buttons on error — if barElement was already replaced, target the live bar.
        const itemEl = getItemElement(itemId);
        const activeBar = itemEl ? itemEl.querySelector('[data-region="reactions-bar"]') : null;
        (activeBar || barElement).querySelectorAll('button').forEach((b) => b.removeAttribute('disabled'));
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

    const itemIds = collectItemIds();
    if (!itemIds.length) {
        return;
    }

    try {
        const response = await Ajax.call([{
            methodname: 'local_reactions_get_reactions',
            args: {
                component: config.component,
                itemtype: config.itemtype,
                itemids: itemIds,
                contextid: config.contextid,
            },
        }])[0];

        const reactionsMap = {};
        response.items.forEach((item) => {
            reactionsMap[item.itemid] = item;
        });

        for (const itemId of itemIds) {
            const freshData = reactionsMap[itemId] || {itemid: itemId, userreactions: [], counts: []};
            const previousData = currentDataMap[itemId];

            if (previousData) {
                const diffs = computeDiffs(previousData, freshData);
                if (diffs.hasChanges) {
                    await rerenderBarWithAnimation(itemId, freshData, diffs);
                }
            }

            currentDataMap[itemId] = freshData;
        }

        await updateCacheBatch(
            itemIds,
            (id) => Cache.itemKey(config.component, config.itemtype, id),
            currentDataMap,
        );
    } catch {
        // Silently ignore poll errors to avoid disrupting the user.
    }
};
