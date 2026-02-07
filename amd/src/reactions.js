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
 * AMD module for emoji reactions on forum posts.
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
 * @param {number} cfg.contextid The module context ID.
 * @param {string} cfg.component Component name e.g. mod_forum.
 * @param {string} cfg.itemtype Item type e.g. post.
 * @param {boolean} cfg.canreact Whether the user can react.
 * @param {Object} cfg.emojis Emoji set as shortcode:unicode map.
 */
export const init = (cfg) => {
    config = cfg;
    loadReactions();

    // Also re-load when new replies are dynamically added.
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

    const container = document.querySelector('[data-content="forum-discussion"]');
    if (container) {
        observer.observe(container, {childList: true, subtree: true});
    }
};

/**
 * Find all forum post articles on the page and load their reactions.
 */
const loadReactions = async() => {
    const articles = document.querySelectorAll('article[data-post-id]');
    if (!articles.length) {
        return;
    }

    // Collect post IDs that don't already have a reactions bar.
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

        // Build a lookup map.
        const reactionsMap = {};
        response.items.forEach((item) => {
            reactionsMap[item.itemid] = item;
        });

        // Render a bar for each post.
        for (const postId of postIds) {
            const data = reactionsMap[postId] || {itemid: postId, userreaction: '', counts: []};
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
    if (!article) {
        return;
    }

    // Don't double-render.
    if (article.querySelector('[data-region="reactions-bar"]')) {
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
            // Insert before the actions within the shared flex-wrap parent.
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

        // Bind click handlers if the user can react.
        if (config.canreact) {
            bindClickHandlers(barElement, postId);
        }
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
    // Build a counts lookup.
    const countsMap = {};
    data.counts.forEach((c) => {
        countsMap[c.emoji] = c.count;
    });

    // Build emoji buttons from the configured set.
    const buttons = [];
    for (const [shortcode, unicode] of Object.entries(config.emojis)) {
        const count = countsMap[shortcode] || 0;
        const isSelected = data.userreaction === shortcode;
        buttons.push({
            shortcode: shortcode,
            unicode: unicode,
            count: count,
            hascount: count > 0,
            selected: isSelected,
            canreact: config.canreact,
        });
    }

    return {
        buttons: buttons,
        canreact: config.canreact,
    };
};

/**
 * Bind click handlers to emoji buttons within a reactions bar.
 *
 * @param {HTMLElement} barElement The reactions bar container.
 * @param {number} postId The forum post ID.
 */
const bindClickHandlers = (barElement, postId) => {
    barElement.querySelectorAll('[data-action="toggle-reaction"]').forEach((btn) => {
        btn.addEventListener('click', async(e) => {
            e.preventDefault();
            const emoji = btn.getAttribute('data-emoji');
            await toggleReaction(postId, emoji, barElement);
        });
    });
};

/**
 * Toggle a reaction via the web service and update the UI.
 *
 * @param {number} postId The forum post ID.
 * @param {string} emoji The emoji shortcode.
 * @param {HTMLElement} barElement The reactions bar to update.
 */
const toggleReaction = async(postId, emoji, barElement) => {
    // Disable buttons while request is in flight.
    const buttons = barElement.querySelectorAll('[data-action="toggle-reaction"]');
    buttons.forEach((b) => b.setAttribute('disabled', 'disabled'));

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

        // Update the bar in-place.
        updateBar(barElement, response);
    } catch (err) {
        Notification.exception(err);
    } finally {
        buttons.forEach((b) => b.removeAttribute('disabled'));
    }
};

/**
 * Update the reactions bar UI after a toggle response.
 *
 * @param {HTMLElement} barElement The reactions bar container.
 * @param {Object} response Response from toggle_reaction WS.
 */
const updateBar = (barElement, response) => {
    // Build counts lookup from response.
    const countsMap = {};
    response.counts.forEach((c) => {
        countsMap[c.emoji] = c.count;
    });

    barElement.querySelectorAll('[data-action="toggle-reaction"]').forEach((btn) => {
        const shortcode = btn.getAttribute('data-emoji');
        const count = countsMap[shortcode] || 0;
        const isSelected = response.userreaction === shortcode;

        // Update count display.
        const countEl = btn.querySelector('[data-region="reaction-count"]');
        if (countEl) {
            countEl.textContent = count > 0 ? count : '';
        }

        // Update selected state.
        if (isSelected) {
            btn.classList.add('local-reactions-selected');
            btn.setAttribute('aria-pressed', 'true');
        } else {
            btn.classList.remove('local-reactions-selected');
            btn.setAttribute('aria-pressed', 'false');
        }

        // Show/hide based on whether there's a count or user can react.
        if (count > 0 || config.canreact) {
            btn.classList.remove('local-reactions-empty');
        } else {
            btn.classList.add('local-reactions-empty');
        }
    });
};
