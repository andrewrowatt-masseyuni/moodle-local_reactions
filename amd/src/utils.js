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
 * Shared utilities for emoji reactions modules.
 *
 * @module     local_reactions/utils
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import * as Cache from 'local_reactions/cache';

/** @var {number} Duration in ms to keep animation classes before removal. */
export const ANIMATION_TIMEOUT = 2100;

/**
 * Render a Mustache template and return the first element.
 *
 * @param {string} templateName The template name, e.g. 'local_reactions/reactions_bar'.
 * @param {Object} context The Mustache template context.
 * @returns {Promise<{element: HTMLElement, js: string}>}
 */
export const renderToElement = async(templateName, context) => {
    const {html, js} = await Templates.renderForPromise(templateName, context);
    const container = document.createElement('div');
    container.innerHTML = html;
    return {element: container.firstElementChild, js};
};

/**
 * Compare cached and fresh reaction data to find differences.
 *
 * @param {Object} cachedData Cached reaction data (counts only).
 * @param {Object} freshData Fresh reaction data from web service.
 * @returns {Object} Diffs object with hasChanges, changedEmojis, newEmojis, removedEmojis.
 */
export const computeDiffs = (cachedData, freshData) => {
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
 * Build Mustache template context from reaction data.
 *
 * @param {Object} data Reaction data with counts array.
 * @param {Object} emojis Map of shortcode to unicode from config.
 * @param {Object} [options={}] Options.
 * @param {boolean} [options.canreact=false] Whether the user can react.
 * @param {boolean} [options.compactview=false] Whether to use compact view.
 * @param {string[]} [options.userreactions=[]] Emoji shortcodes the current user has reacted with.
 * @returns {Object} Template context.
 */
export const buildTemplateContext = (data, emojis, {canreact = false, compactview = false, userreactions = []} = {}) => {
    const countsMap = {};
    (data?.counts || []).forEach((c) => {
        countsMap[c.emoji] = c.count;
    });

    const buttons = [];
    let totalCount = 0;
    const reactedEmojis = [];
    let hasAnySelected = false;

    for (const [shortcode, unicode] of Object.entries(emojis)) {
        const count = countsMap[shortcode] || 0;
        const isSelected = userreactions.includes(shortcode);
        buttons.push({
            shortcode: shortcode,
            unicode: unicode,
            count: count,
            hascount: count > 0,
            selected: isSelected,
            canreact: canreact,
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
        canreact: canreact,
        compactview: compactview,
        hasanycount: totalCount > 0,
        totalcount: totalCount,
        reactedEmojis: reactedEmojis,
        selected: hasAnySelected,
    };
};

/**
 * Create a poller that periodically calls a function and pauses when the tab is hidden.
 *
 * @param {number} intervalSeconds Polling interval in seconds. If <= 0, no polling is started.
 * @param {Function} pollFn The async function to call on each poll tick.
 */
export const createPoller = (intervalSeconds, pollFn) => {
    if (!intervalSeconds || intervalSeconds <= 0) {
        return;
    }

    let timer = setInterval(pollFn, intervalSeconds * 1000);

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        } else if (!timer) {
            pollFn();
            timer = setInterval(pollFn, intervalSeconds * 1000);
        }
    });
};

/**
 * Collect integer IDs from elements matching a selector.
 *
 * @param {string} selector CSS selector for the elements.
 * @param {string} attribute The attribute name containing the ID.
 * @returns {number[]} Array of parsed IDs.
 */
export const collectIds = (selector, attribute) => {
    const ids = [];
    document.querySelectorAll(selector).forEach((el) => {
        const id = parseInt(el.getAttribute(attribute));
        if (id) {
            ids.push(id);
        }
    });
    return ids;
};

/**
 * Apply animation classes to pills in a newly rendered bar based on diffs.
 *
 * @param {HTMLElement} newBar The new reactions bar element.
 * @param {Object} diffs The diff result from computeDiffs.
 * @param {boolean} compactview Whether compact view is enabled.
 */
export const applyDiffAnimations = (newBar, diffs, compactview) => {
    if (!diffs.hasChanges) {
        return;
    }

    if (!compactview) {
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
};

/**
 * Remove animation classes from a bar after the animation duration.
 *
 * @param {HTMLElement} bar The reactions bar element.
 */
export const clearAnimationClasses = (bar) => {
    setTimeout(() => {
        bar.querySelectorAll('.local-reactions-count-changed, .local-reactions-pill-new')
            .forEach((el) => {
                el.classList.remove('local-reactions-count-changed', 'local-reactions-pill-new');
            });
    }, ANIMATION_TIMEOUT);
};

/**
 * Update the IndexedDB cache for a batch of items.
 *
 * @param {number[]} ids The item/discussion IDs.
 * @param {Function} keyFn Function that takes an ID and returns a cache key.
 * @param {Object} dataMap Map of ID to reaction data (must have a counts property).
 * @returns {Promise<void>}
 */
export const updateCacheBatch = async(ids, keyFn, dataMap) => {
    const cacheAvailable = await Cache.isAvailable();
    if (!cacheAvailable) {
        return;
    }

    const entries = [];
    for (const id of ids) {
        const data = dataMap[id];
        if (data) {
            entries.push({
                key: keyFn(id),
                data: {counts: data.counts || []},
            });
        }
    }

    if (entries.length > 0) {
        await Cache.setMultiple(entries);
    }
};
