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
 * Moodle App support for Reactions.
 *
 * The app has no delegate for decorating a forum post, so this watches the DOM instead and grafts
 * reaction bars onto posts as the app renders them. That mirrors what amd/src/reactions.js already
 * does on the web, and keeps the integration free of any dependency on app internals beyond the
 * post markup itself: no filter plugin, no component identifier, no route parsing.
 *
 * Posts are recognised structurally and identified by post ID; the server resolves everything else
 * from that ID, so nothing here needs to know the course module.
 *
 * The app's markup is not a public contract, so every step is guarded. If anything is not where we
 * expect it, posts are left exactly as the app rendered them.
 *
 * This file is evaluated by the app with "this" bound to the app's injected service container, so
 * app services are reached as context.<ServiceName>.
 *
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const context = this;

/** Root element of a single rendered forum post in the app. */
const POST_SELECTOR = '.addon-mod_forum-post';

/** The app puts the post ID on the card header, inside the post root. */
const POST_ID_SELECTOR = '[id^="addon-mod_forum-post-"]';
const POST_ID_PREFIX = 'addon-mod_forum-post-';

/** Preferred insertion point: the footer holding tags, ratings and the reply button. */
const POST_FOOTER_SELECTOR = '.addon-mod-forum-post-more-info';

/** Class marking a bar we inserted, so a redraw can clear the previous one. */
const BAR_CLASS = 'local-reactions-bar';

/** Marks a post we have already claimed, so overlapping scans do not duplicate work. */
const CLAIMED_ATTR = 'data-local-reactions';

/** What the reactions web services call a forum post. */
const COMPONENT = 'mod_forum';
const ITEMTYPE = 'post';

/**
 * How long to wait after the DOM settles before scanning. The app mutates its DOM constantly, so
 * the observer only ever schedules this; the scan itself is one querySelectorAll.
 */
const SCAN_DEBOUNCE_MS = 150;

/** Smiley used to open the emoji picker; matches the icon in templates/reactions_bar.mustache. */
const TRIGGER_SVG = '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="currentColor">'
    + '<path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Zm3.82 1.636a.75.75 0 0 1 '
    + '1.038.175l.007.009c.103.118.22.222.35.31.264.178.683.37 1.285.37.602 0 1.02-.192 1.285-.371.13-.088.247-.192.35-.31l'
    + '.007-.008a.75.75 0 0 1 1.222.87l-.022.03c-.2.247-.46.47-.786.656-.652.374-1.404.583-2.056.583-.652 0-1.404-.21-2.056'
    + '-.584a3.56 3.56 0 0 1-.786-.655l-.022-.031a.75.75 0 0 1 .184-1.043ZM6 6.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm6 0a1 1 0 1 '
    + '1-2 0 1 1 0 0 1 2 0Z"/></svg>';

/** Per-post cache of resolved settings, so a re-render does not re-ask the server. */
const settingsCache = new Map();

/** Pending debounced scan. */
let scanTimer = null;

/** The picker that is currently open, so opening another one closes it. */
let openPicker = null;

/**
 * Translate a plugin string, falling back to the supplied default if anything goes wrong.
 *
 * @param {string} key String identifier, as declared in the lang key of db/mobile.php.
 * @param {string} fallback Text to use if the string is unavailable.
 * @param {object} [params] Placeholder values, e.g. {$a: '👍'}.
 * @returns {string}
 */
const str = (key, fallback, params) => {
    try {
        const full = `plugin.local_reactions.${key}`;
        const translated = context.TranslateService.instant(full, params);

        // ngx-translate echoes the key back when the string is missing.
        return !translated || translated === full ? fallback : translated;
    } catch (error) {
        return fallback;
    }
};

/**
 * Log a problem without disturbing the user. Reactions are an enhancement; if they cannot be
 * drawn the post itself is still perfectly readable.
 *
 * @param {string} message
 * @param {Error} error
 */
const logError = (message, error) => {
    try {
        context.CoreLoggerProvider.getInstance('local_reactions').error(message, error);
    } catch (ignored) {
        // Nothing sensible to do if even logging fails.
    }
};

/**
 * Load settings for a batch of posts and put them in the cache.
 *
 * Resolves quietly on failure; callers read the cache, and a post with no cached settings is
 * simply left alone and retried on the next scan.
 *
 * @param {number[]} postids
 * @returns {Promise<void>}
 */
const loadSettings = async(postids) => {
    try {
        const site = context.CoreSitesProvider.getCurrentSite();
        if (!site) {
            return;
        }

        // No wsAvailable() pre-flight on purpose. CoreSite.request() already refuses methods
        // missing from the site's cached function list, and its error reaches the catch below and
        // gets logged. Checking first would only turn that into a silent no-op, which is very hard
        // to diagnose on a site whose cached function list predates this plugin's upgrade.
        const response = await site.read('local_reactions_get_item_settings', {
            component: COMPONENT,
            itemtype: ITEMTYPE,
            itemids: postids,
        });

        // Map preserves insertion order, so pills and the picker follow the site's emoji setting.
        const emojis = new Map();
        (response.emojis || []).forEach((entry) => emojis.set(entry.shortcode, entry.emoji));

        (response.settings || []).forEach((settings) => {
            settingsCache.set(settings.itemid, Object.assign({}, settings, {emojis}));
        });
    } catch (error) {
        logError('Could not load reactions settings', error);
    }
};

/**
 * Fetch reaction counts for a set of posts sharing one context.
 *
 * @param {object} settings Settings for any post in the group; supplies the context.
 * @param {number[]} postids
 * @returns {Promise<Map<number, object>|null>} Counts by post ID, or null if the request failed.
 */
const fetchCounts = async(settings, postids) => {
    try {
        const site = context.CoreSitesProvider.getCurrentSite();

        // Counts change constantly, so never serve them from the app's WS cache.
        const response = await site.read('local_reactions_get_reactions', {
            component: COMPONENT,
            itemtype: ITEMTYPE,
            itemids: postids,
            contextid: settings.contextid,
        }, {getFromCache: false, saveToCache: false});

        const items = new Map();
        (response.items || []).forEach((item) => items.set(item.itemid, item));

        return items;
    } catch (error) {
        // Offline, or the site is unreachable.
        logError('Could not load reactions', error);

        return null;
    }
};

/**
 * Find the post ID for a rendered post, or null if the app's markup has changed.
 *
 * @param {HTMLElement} post
 * @returns {number|null}
 */
const getPostId = (post) => {
    const header = post.querySelector(POST_ID_SELECTOR);
    if (!header) {
        return null;
    }

    const id = parseInt(header.id.substring(POST_ID_PREFIX.length), 10);

    return isNaN(id) || id <= 0 ? null : id;
};

/**
 * Close the emoji picker that is currently open, if any.
 */
const closePicker = () => {
    if (!openPicker) {
        return;
    }

    openPicker.element.hidden = true;
    openPicker.trigger.setAttribute('aria-expanded', 'false');
    document.removeEventListener('click', openPicker.dismiss, true);
    openPicker = null;
};

/**
 * Turn the web service's counts array into a shortcode => count map.
 *
 * @param {Array} rawcounts
 * @returns {Map<string, number>}
 */
const toCountsMap = (rawcounts) => {
    const counts = new Map();
    (rawcounts || []).forEach((entry) => {
        if (entry.count > 0) {
            counts.set(entry.emoji, entry.count);
        }
    });

    return counts;
};

/**
 * Build a pill element, matching templates/pill.mustache and templates/compact_pill.mustache.
 *
 * @param {string} text Emoji character, or the concatenated emojis for the compact pill.
 * @param {number} count Number shown after the emoji.
 * @param {object} opts Rendering options: compact, selected, interactive, label.
 * @returns {HTMLElement}
 */
const buildPill = (text, count, opts) => {
    const pill = document.createElement(opts.interactive ? 'button' : 'span');
    pill.className = 'local-reactions-pill'
        + (opts.compact ? ' local-reactions-pill-compact' : '')
        + (opts.selected ? ' local-reactions-selected' : '');

    if (opts.interactive) {
        pill.type = 'button';
    }
    if (opts.label) {
        pill.setAttribute('aria-label', opts.label);
    }

    const emoji = document.createElement('span');
    emoji.className = opts.compact ? 'local-reactions-compact-emojis' : 'local-reactions-emoji';
    emoji.setAttribute('aria-hidden', 'true');
    emoji.textContent = text;

    const countel = document.createElement('span');
    countel.className = 'local-reactions-count';
    countel.setAttribute('data-region', 'reaction-count');
    countel.textContent = String(count);

    pill.appendChild(emoji);
    pill.appendChild(countel);

    return pill;
};

/**
 * Build the emoji picker, plus the trigger button that opens it.
 *
 * @param {object} state Settings, counts and the user's own reactions.
 * @param {Function} onToggle Called with an emoji shortcode when the user picks one.
 * @returns {object} The wrapper element and its trigger button.
 */
const buildPicker = (state, onToggle) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'local-reactions-picker-wrapper';

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'local-reactions-trigger';
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-label', str('reacttothispost', 'React to this post'));
    trigger.innerHTML = TRIGGER_SVG;

    const picker = document.createElement('div');
    picker.className = 'local-reactions-picker';
    picker.setAttribute('role', 'menu');
    picker.hidden = true;

    state.settings.emojis.forEach((unicode, shortcode) => {
        const selected = state.userreactions.includes(shortcode);
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'local-reactions-picker-btn' + (selected ? ' local-reactions-selected' : '');
        option.setAttribute('role', 'menuitem');
        option.setAttribute('aria-label', selected
            ? str('removereaction', `Remove your ${unicode} reaction`, {$a: unicode})
            : str('addreaction', `React with ${unicode}`, {$a: unicode}));
        option.textContent = unicode;
        option.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            closePicker();
            onToggle(shortcode);
        });
        picker.appendChild(option);
    });

    trigger.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        // Tapping the trigger of the open picker closes it.
        const wasOpen = openPicker && openPicker.element === picker;
        closePicker();
        if (wasOpen) {
            return;
        }

        picker.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        openPicker = {
            element: picker,
            trigger,
            dismiss: (dismissEvent) => {
                if (!picker.contains(dismissEvent.target)) {
                    closePicker();
                }
            },
        };
        document.addEventListener('click', openPicker.dismiss, true);
    });

    wrapper.appendChild(trigger);
    wrapper.appendChild(picker);

    return {wrapper, trigger};
};

/**
 * Build the reactions bar for a post.
 *
 * Mirrors the markup and class names of the web templates (reactions_bar, pill, compact_pill) so
 * mobileapp/styles.css can stay a trimmed copy of the web stylesheet.
 *
 * @param {object} state Settings, emoji map, counts map and the user's reaction shortcodes.
 * @param {Function} onToggle Called with an emoji shortcode when the user picks one.
 * @returns {HTMLElement}
 */
const buildBar = (state, onToggle) => {
    const {settings, counts, userreactions} = state;
    const emojis = settings.emojis;

    const bar = document.createElement('div');
    bar.className = BAR_CLASS;
    bar.setAttribute('data-region', 'reactions-bar');

    let trigger = null;
    if (settings.canreact) {
        const picker = buildPicker(state, onToggle);
        trigger = picker.trigger;
        bar.appendChild(picker.wrapper);
    }

    // Walk the configured emoji set rather than the response, so the order on screen matches the
    // site's emoji setting exactly as it does on the web.
    const reacted = [];
    let total = 0;
    emojis.forEach((unicode, shortcode) => {
        const count = counts.get(shortcode) || 0;
        if (count > 0) {
            reacted.push({shortcode, unicode, count});
            total += count;
        }
    });

    if (settings.compactview) {
        if (total > 0) {
            const pill = buildPill(reacted.map((entry) => entry.unicode).join(''), total, {
                compact: true,
                selected: userreactions.length > 0,
                interactive: !!trigger,
                label: `${total} ${str('totalreactions', 'Total reactions')}`,
            });
            if (trigger) {
                pill.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    trigger.click();
                });
            }
            bar.appendChild(pill);
        }

        return bar;
    }

    reacted.forEach((entry) => {
        const selected = userreactions.includes(entry.shortcode);
        const pill = buildPill(entry.unicode, entry.count, {
            compact: false,
            selected,
            interactive: settings.canreact,
            label: `${entry.unicode} ${entry.count}`,
        });
        pill.setAttribute('aria-pressed', selected ? 'true' : 'false');

        if (settings.canreact) {
            pill.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                closePicker();
                onToggle(entry.shortcode);
            });
        }

        bar.appendChild(pill);
    });

    return bar;
};

/**
 * Draw, and keep redrawing, the reactions bar for one post.
 *
 * @param {HTMLElement} post Root element of the rendered post.
 * @param {number} postid
 * @param {object} settings Settings for the post's course module, including the emoji map.
 * @param {object} initial The item entry returned by local_reactions_get_reactions.
 */
const attachBar = (post, postid, settings, initial) => {
    const state = {
        settings,
        counts: toCountsMap(initial && initial.counts),
        userreactions: (initial && initial.userreactions) || [],
    };

    const onToggle = async(shortcode) => {
        try {
            const site = context.CoreSitesProvider.getCurrentSite();
            const response = await site.write('local_reactions_toggle_reaction', {
                component: COMPONENT,
                itemtype: ITEMTYPE,
                itemid: postid,
                emoji: shortcode,
            });

            // The response carries the post's full new state, so redraw straight from it.
            state.counts = toCountsMap(response.counts);
            state.userreactions = response.userreactions || [];
            render();
        } catch (error) {
            context.CoreDomUtilsProvider.showErrorModalDefault(error, 'Error saving reaction.');
        }
    };

    const render = () => {
        // A post can be re-rendered by the app, and reacting replaces the bar wholesale, so
        // always clear whatever is already there.
        Array.from(post.querySelectorAll('.' + BAR_CLASS)).forEach((existing) => existing.remove());

        const bar = buildBar(state, onToggle);
        const footer = post.querySelector(POST_FOOTER_SELECTOR);
        if (footer) {
            footer.insertBefore(bar, footer.firstChild);
        } else {
            const content = post.querySelector('ion-card-content');
            (content || post).appendChild(bar);
        }
    };

    render();
};

/**
 * Find every post the app has rendered that we have not handled yet, and draw their bars.
 *
 * Claimed posts are marked so overlapping scans cannot double-handle them. The mark is removed
 * again if the work does not complete, so a later scan retries rather than leaving the post bare
 * forever.
 *
 * @returns {Promise<void>}
 */
const scanPosts = async() => {
    const claimed = [];

    document.querySelectorAll(POST_SELECTOR).forEach((post) => {
        if (post.getAttribute(CLAIMED_ATTR)) {
            return;
        }

        const postid = getPostId(post);
        if (!postid) {
            return;
        }

        post.setAttribute(CLAIMED_ATTR, String(postid));
        claimed.push({post, postid});
    });

    if (!claimed.length) {
        return;
    }

    const release = (entries) => entries.forEach(({post}) => post.removeAttribute(CLAIMED_ATTR));

    const unknown = claimed.filter((entry) => !settingsCache.has(entry.postid)).map((entry) => entry.postid);
    if (unknown.length) {
        await loadSettings(unknown);
    }

    // Group by context so each forum on screen costs one counts request.
    const groups = new Map();
    const unresolved = [];

    claimed.forEach((entry) => {
        const settings = settingsCache.get(entry.postid);
        if (!settings) {
            // The settings request failed. Let a later scan try again.
            unresolved.push(entry);

            return;
        }
        if (!settings.enabled) {
            return;
        }

        const group = groups.get(settings.contextid) || {settings, entries: []};
        group.entries.push(entry);
        groups.set(settings.contextid, group);
    });

    release(unresolved);

    for (const {settings, entries} of groups.values()) {
        const items = await fetchCounts(settings, entries.map((entry) => entry.postid));

        if (!items) {
            release(entries);
            continue;
        }

        entries.forEach(({post, postid}) => {
            attachBar(post, postid, settings, items.get(postid) || {itemid: postid, counts: [], userreactions: []});
        });

    }
};

/**
 * Schedule a scan. The app mutates its DOM constantly, so this coalesces bursts into one pass.
 */
const scheduleScan = () => {
    if (scanTimer) {
        clearTimeout(scanTimer);
    }

    scanTimer = setTimeout(() => {
        scanTimer = null;
        scanPosts().catch((error) => logError('Reactions scan failed', error));
    }, SCAN_DEBOUNCE_MS);
};

// Watch for posts appearing. The observer callback stays deliberately trivial; all the work
// happens in the debounced scan.
new MutationObserver(scheduleScan).observe(document.body, {childList: true, subtree: true});

// The observer only reports changes, so pick up anything already on screen.
scheduleScan();

// Drop cached state when the user logs out, so the next login starts clean.
context.CoreEventsProvider.on(context.CoreEventsProvider.LOGOUT, () => {
    settingsCache.clear();
    closePicker();
});
