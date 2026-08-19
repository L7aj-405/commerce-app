import { useCallback, useMemo, useState } from 'react';

/**
 * Config-driven product filtering.
 *
 * A filter config is a plain object — add new ones freely, the UI + logic adapt:
 *   { key, label, type, accessor, options?, min?, max?, step?, format?, variant? }
 *
 * type drives BOTH the UI (via FilterSidebar's registry) and the matching
 * behavior here. Two behaviors cover everything today:
 *   - 'multi' (checkbox, swatch): value is an array; product matches if its
 *      accessor value is one of the selected values (OR within a filter, AND across filters).
 *   - 'range' (price): value is [lo, hi]; product matches if accessor ∈ [lo, hi].
 *
 * To add a brand-new behavior (e.g. a date range), extend BEHAVIOR below and add
 * a matching input component in the sidebar registry — nothing else changes.
 */

const uniq = (arr) => [...new Set(arr.filter((v) => v !== undefined && v !== null && v !== ''))];

const behaviorOf = (type) => (type === 'range' ? 'range' : 'multi');

const BEHAVIOR = {
    multi: {
        initial: () => [],
        isActive: (v) => Array.isArray(v) && v.length > 0,
        matches: (v, f, p) => v.length === 0 || v.includes(f.accessor(p)),
        count: (v) => v.length,
    },
    range: {
        initial: (f) => [f.min, f.max],
        isActive: (v, f) => v[0] > f.min || v[1] < f.max,
        matches: (v, f, p) => {
            const x = Number(f.accessor(p));
            return Number.isNaN(x) ? true : x >= v[0] && x <= v[1];
        },
        count: (v, f) => (v[0] > f.min || v[1] < f.max ? 1 : 0),
    },
};

export default function useProductFilters(filters, products = []) {
    // Resolve configs: auto-derive checkbox/swatch options and range bounds from
    // the data when they aren't provided, so a new filter needs only key+accessor.
    const resolved = useMemo(() => filters.map((f) => {
        if (behaviorOf(f.type) === 'range') {
            const nums = products.map((p) => Number(f.accessor(p))).filter((n) => !Number.isNaN(n));
            const min = f.min ?? (nums.length ? Math.floor(Math.min(...nums)) : 0);
            const max = f.max ?? (nums.length ? Math.ceil(Math.max(...nums)) : 100);
            return { ...f, min, max, step: f.step ?? 1, format: f.format ?? ((v) => v) };
        }
        const options = f.options
            ?? uniq(products.map((p) => f.accessor(p))).sort().map((v) => ({ value: v, label: String(v) }));
        return { ...f, options };
    }), [filters, products]);

    const initial = useMemo(() => {
        const s = {};
        resolved.forEach((f) => { s[f.key] = BEHAVIOR[behaviorOf(f.type)].initial(f); });
        return s;
    }, [resolved]);

    // NOTE: initial is captured once. If `products` load in async and change the
    // derived bounds, call clearAll() (or key the parent on a data version) to reseed.
    const [values, setValues] = useState(initial);

    const toggle = useCallback((key, val) => {
        setValues((prev) => {
            const cur = prev[key] ?? [];
            return { ...prev, [key]: cur.includes(val) ? cur.filter((x) => x !== val) : [...cur, val] };
        });
    }, []);

    const setRange = useCallback((key, range) => {
        setValues((prev) => ({ ...prev, [key]: range }));
    }, []);

    const clear = useCallback((key) => {
        const f = resolved.find((x) => x.key === key);
        setValues((prev) => ({ ...prev, [key]: BEHAVIOR[behaviorOf(f.type)].initial(f) }));
    }, [resolved]);

    const clearAll = useCallback(() => setValues(initial), [initial]);

    const countFor = useCallback((f) => BEHAVIOR[behaviorOf(f.type)].count(values[f.key], f), [values]);

    const filtered = useMemo(
        () => products.filter((p) => resolved.every((f) => BEHAVIOR[behaviorOf(f.type)].matches(values[f.key], f, p))),
        [products, resolved, values],
    );

    const chips = useMemo(() => {
        const out = [];
        resolved.forEach((f) => {
            const b = BEHAVIOR[behaviorOf(f.type)];
            if (!b.isActive(values[f.key], f)) return;

            if (behaviorOf(f.type) === 'range') {
                const [lo, hi] = values[f.key];
                out.push({ id: f.key, filter: f.label, label: `${f.format(lo)} – ${f.format(hi)}`, onRemove: () => clear(f.key) });
            } else {
                values[f.key].forEach((val) => {
                    const opt = f.options.find((o) => o.value === val);
                    out.push({
                        id: `${f.key}:${val}`,
                        filter: f.label,
                        label: opt?.label ?? String(val),
                        swatch: opt?.hex,
                        onRemove: () => toggle(f.key, val),
                    });
                });
            }
        });
        return out;
    }, [resolved, values, clear, toggle]);

    return {
        filters: resolved,
        values,
        filtered,
        chips,
        activeCount: chips.length,
        toggle,
        setRange,
        clear,
        clearAll,
        countFor,
    };
}
