import { useState, useMemo, useCallback } from 'react';
import { router } from '@inertiajs/react';

/**
 * Shared state for a department work queue.
 *
 * The pattern, applied identically by every department dashboard:
 *
 *   - Server props are the only source of truth for queue contents. Nothing is
 *     copied into state, so a row can never show stale data after an action.
 *   - The selection is stored as a KEY, never as the row object — after Inertia
 *     refreshes props, the object identity changes but the key still resolves to
 *     the fresh row. (Storing the object is how drawers go stale.)
 *   - Filtering and counting are derived with useMemo, never stored, so they
 *     cannot drift out of sync with the data.
 *   - `submit` tracks which row is in flight so only that row's buttons spin,
 *     and always uses preserveState/preserveScroll so filters and open panels
 *     survive the round trip.
 *
 * @param {Array<object>} orders  rows straight from the controller
 * @param {{ searchKeys?: string[], userId?: string }} options
 */
export default function useQueue(orders = [], { searchKeys = ['reference', 'customer_name', 'customer_phone'], userId = null } = {}) {
    const [search, setSearch]     = useState('');
    const [scope, setScope]       = useState('all');     // 'all' | 'unassigned' | 'mine'
    const [source, setSource]     = useState('all');     // 'all' | 'pos' | 'online'
    const [selectedKey, setKey]   = useState(null);
    const [busyKey, setBusyKey]   = useState(null);

    const keyOf = useCallback((o) => `${o.type}:${o.id}`, []);

    const rows = useMemo(() => {
        const q = search.trim().toLowerCase();

        return orders.filter((o) => {
            if (scope === 'unassigned' && o.assigned_to) return false;
            if (scope === 'mine' && o.assigned_to !== userId) return false;
            if (source !== 'all' && o.source !== source) return false;
            if (!q) return true;

            return searchKeys
                .map((k) => o[k])
                .filter(Boolean)
                .some((v) => String(v).toLowerCase().includes(q));
        });
    }, [orders, search, scope, source, userId, searchKeys]);

    const counts = useMemo(() => ({
        all:        orders.length,
        unassigned: orders.filter((o) => ! o.assigned_to).length,
        mine:       orders.filter((o) => o.assigned_to === userId).length,
    }), [orders, userId]);

    // Re-looked-up every render from fresh props rather than held in state.
    const selected = useMemo(
        () => orders.find((o) => keyOf(o) === selectedKey) ?? null,
        [orders, selectedKey, keyOf],
    );

    const select = useCallback((order) => setKey(order ? keyOf(order) : null), [keyOf]);

    /** POST an action for one row, spinning only that row. */
    const submit = useCallback((url, data = {}, options = {}) => {
        const key = options.key ?? null;
        setBusyKey(key);

        router.post(url, data, {
            preserveScroll: true,
            preserveState: true,
            ...options,
            onFinish: () => {
                setBusyKey(null);
                options.onFinish?.();
            },
        });
    }, []);

    const isBusy = useCallback((order) => busyKey !== null && busyKey === keyOf(order), [busyKey, keyOf]);

    return {
        rows, counts,
        search, setSearch,
        scope, setScope,
        source, setSource,
        selected, select, selectedKey,
        submit, busyKey, isBusy, setBusyKey,
        keyOf,
    };
}
