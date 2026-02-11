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
 * IndexedDB cache for emoji reactions data.
 *
 * Stores reaction counts locally so they can be rendered instantly on page load
 * before the web service response arrives. Only counts are cached (no user-specific
 * state). If IndexedDB is unavailable, all methods silently return null/void.
 *
 * @module     local_reactions/cache
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {string} Database name. */
const DB_NAME = 'local_reactions_cache';

/** @var {number} Database schema version. */
const DB_VERSION = 1;

/** @var {string} Object store name. */
const STORE_NAME = 'reactions';

/** @var {number} Cache TTL in milliseconds (1 week). */
const CACHE_TTL = 604800000;

/** @var {IDBDatabase|null} Cached database connection. */
let db = null;

/** @var {boolean} Whether we have already attempted to open the database. */
let dbAttempted = false;

/**
 * Open (or return the cached) IndexedDB database connection.
 *
 * @returns {Promise<IDBDatabase|null>} The database, or null if unavailable.
 */
const getDb = () => {
    if (db) {
        return Promise.resolve(db);
    }
    if (dbAttempted) {
        return Promise.resolve(null);
    }
    dbAttempted = true;

    if (typeof indexedDB === 'undefined') {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        try {
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = (event) => {
                const database = event.target.result;
                if (!database.objectStoreNames.contains(STORE_NAME)) {
                    database.createObjectStore(STORE_NAME, {keyPath: 'cacheKey'});
                }
            };

            request.onsuccess = (event) => {
                db = event.target.result;
                resolve(db);
            };

            request.onerror = () => {
                resolve(null);
            };

            request.onblocked = () => {
                resolve(null);
            };
        } catch (e) {
            resolve(null);
        }
    });
};

/**
 * Check whether IndexedDB caching is available.
 *
 * @returns {Promise<boolean>}
 */
export const isAvailable = async() => {
    const database = await getDb();
    return database !== null;
};

/**
 * Build a cache key for a post/item reaction.
 *
 * @param {string} component e.g. 'mod_forum'
 * @param {string} itemtype e.g. 'post'
 * @param {number} itemid The post ID.
 * @returns {string} Cache key.
 */
export const itemKey = (component, itemtype, itemid) => {
    return `${component}:${itemtype}:item:${itemid}`;
};

/**
 * Build a cache key for a discussion-level reaction.
 *
 * @param {string} component e.g. 'mod_forum'
 * @param {string} itemtype e.g. 'post'
 * @param {number} discussionid The discussion ID.
 * @returns {string} Cache key.
 */
export const discussionKey = (component, itemtype, discussionid) => {
    return `${component}:${itemtype}:discussion:${discussionid}`;
};

/**
 * Get a cached entry by key. Returns null if not found or expired.
 *
 * @param {string} key The cache key.
 * @returns {Promise<Object|null>} The cached data object, or null.
 */
export const get = async(key) => {
    const database = await getDb();
    if (!database) {
        return null;
    }

    try {
        return await new Promise((resolve) => {
            const tx = database.transaction(STORE_NAME, 'readonly');
            const store = tx.objectStore(STORE_NAME);
            const request = store.get(key);

            request.onsuccess = () => {
                const record = request.result;
                if (!record) {
                    resolve(null);
                    return;
                }
                // Check TTL.
                if (Date.now() - record.timestamp > CACHE_TTL) {
                    // Stale entry - delete it asynchronously and return null.
                    try {
                        const deleteTx = database.transaction(STORE_NAME, 'readwrite');
                        deleteTx.objectStore(STORE_NAME).delete(key);
                    } catch (e) {
                        // Ignore deletion errors.
                    }
                    resolve(null);
                    return;
                }
                resolve(record.data);
            };

            request.onerror = () => {
                resolve(null);
            };
        });
    } catch (e) {
        return null;
    }
};

/**
 * Get multiple cached entries by keys.
 *
 * @param {string[]} keys Array of cache keys.
 * @returns {Promise<Map<string, Object|null>>} Map of key to data (null if missing/expired).
 */
export const getMultiple = async(keys) => {
    const results = new Map();
    const database = await getDb();

    if (!database || !keys.length) {
        keys.forEach((key) => results.set(key, null));
        return results;
    }

    try {
        return await new Promise((resolve) => {
            const tx = database.transaction(STORE_NAME, 'readonly');
            const store = tx.objectStore(STORE_NAME);
            const now = Date.now();
            let pending = keys.length;

            keys.forEach((key) => {
                const request = store.get(key);

                request.onsuccess = () => {
                    const record = request.result;
                    if (!record || (now - record.timestamp > CACHE_TTL)) {
                        results.set(key, null);
                    } else {
                        results.set(key, record.data);
                    }
                    pending--;
                    if (pending === 0) {
                        resolve(results);
                    }
                };

                request.onerror = () => {
                    results.set(key, null);
                    pending--;
                    if (pending === 0) {
                        resolve(results);
                    }
                };
            });
        });
    } catch (e) {
        keys.forEach((key) => {
            if (!results.has(key)) {
                results.set(key, null);
            }
        });
        return results;
    }
};

/**
 * Set a cache entry.
 *
 * @param {string} key The cache key.
 * @param {Object} data The reaction data to cache (counts only).
 * @returns {Promise<void>}
 */
export const set = async(key, data) => {
    const database = await getDb();
    if (!database) {
        return;
    }

    try {
        const tx = database.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        store.put({
            cacheKey: key,
            data: data,
            timestamp: Date.now(),
        });
    } catch (e) {
        // Silently fail.
    }
};

/**
 * Set multiple cache entries in a single transaction.
 *
 * @param {Array<{key: string, data: Object}>} entries Array of entries to cache.
 * @returns {Promise<void>}
 */
export const setMultiple = async(entries) => {
    const database = await getDb();
    if (!database || !entries.length) {
        return;
    }

    try {
        const tx = database.transaction(STORE_NAME, 'readwrite');
        const store = tx.objectStore(STORE_NAME);
        const now = Date.now();

        entries.forEach((entry) => {
            store.put({
                cacheKey: entry.key,
                data: entry.data,
                timestamp: now,
            });
        });
    } catch (e) {
        // Silently fail.
    }
};
