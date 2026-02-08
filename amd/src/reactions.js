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
 * @module     local_reactions/reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';

/** @var {Object} Module-level config set during init. */
let config = {};

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
 * Find all forum post articles on the page and load their reactions.
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

        for (const postId of postIds) {
            const data = reactionsMap[postId] || {itemid: postId, userreactions: [], counts: []};
            await renderBar(postId, data);
        }
    } catch (err) {
        Notification.exception(err);
    }
};

/**
 * Build the template context and render the reactions bar into a post.
 *
 * @param {number} postId The forum post ID.
 * @param {Object} data Reaction data from the web service.
 */
const renderBar = async(postId, data) => {
    const article = document.querySelector(`article[data-post-id="${postId}"]`);
    if (!article || article.querySelector('[data-region="reactions-bar"]')) {
        return;
    }

    const context = buildTemplateContext(data);

    try {
        const {html, js} = await Templates.renderForPromise('local_reactions/reactions_bar', context);
        const container = document.createElement('div');
        container.innerHTML = html;
        const barElement = container.firstElementChild;

        // Try to insert alongside the post actions (reply posts).
        const actionsContainer = article.querySelector('[data-region="post-actions-container"]');
        if (actionsContainer) {
            actionsContainer.parentElement.insertBefore(barElement, actionsContainer);
        } else {
            // Fallback for first post: append to the post core column.
            const postCore = article.querySelector('[data-region-content="forum-post-core"]');
            if (!postCore) {
                return;
            }
            postCore.appendChild(barElement);
        }
        Templates.runTemplateJS(js);
        bindHandlers(barElement, postId);
    } catch (err) {
        Notification.exception(err);
    }
};

/**
 * Build Mustache template context from reaction data.
 *
 * @param {Object} data Reaction data.
 * @returns {Object} Template context.
 */
const buildTemplateContext = (data) => {
    const countsMap = {};
    data.counts.forEach((c) => {
        countsMap[c.emoji] = c.count;
    });

    const userReactions = data.userreactions || [];
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

        // Rebuild the bar with fresh data to correctly show/hide pills.
        const context = buildTemplateContext({
            userreactions: response.userreactions,
            counts: response.counts,
        });
        const {html, js} = await Templates.renderForPromise('local_reactions/reactions_bar', context);
        const container = document.createElement('div');
        container.innerHTML = html;
        const newBar = container.firstElementChild;

        barElement.replaceWith(newBar);
        Templates.runTemplateJS(js);
        bindHandlers(newBar, postId);
    } catch (err) {
        Notification.exception(err);
        // Re-enable buttons on error.
        barElement.querySelectorAll('button').forEach((b) => b.removeAttribute('disabled'));
    }
};
