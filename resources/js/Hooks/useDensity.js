import { useCallback, useSyncExternalStore } from 'react';

/**
 * Personal UI density preference — 'comfortable' | 'compact'. Mirrors
 * useTheme.js's exact localStorage + useSyncExternalStore pattern (own key,
 * own listeners set) since it's a separate, independent preference. Applies
 * `data-density` on <html>, which app.css's --density-scale token reads
 * (table row height, card padding, filter-bar spacing).
 */
const KEY = 'density';
const listeners = new Set();

function storedPreference() {
    try {
        return window.localStorage.getItem(KEY) || 'comfortable';
    } catch {
        return 'comfortable';
    }
}

export function applyDensity(pref = storedPreference()) {
    document.documentElement.setAttribute('data-density', pref === 'compact' ? 'compact' : 'comfortable');
}

export function setDensity(pref) {
    try {
        window.localStorage.setItem(KEY, pref);
    } catch {
        /* ignore */
    }
    applyDensity(pref);
    listeners.forEach((cb) => cb());
}

function subscribe(cb) {
    listeners.add(cb);

    const onStorage = (e) => { if (e.key === KEY) { applyDensity(); cb(); } };
    window.addEventListener('storage', onStorage);

    return () => {
        listeners.delete(cb);
        window.removeEventListener('storage', onStorage);
    };
}

export default function useDensity() {
    const density = useSyncExternalStore(subscribe, storedPreference, () => 'comfortable');

    const toggle = useCallback(() => setDensity(density === 'compact' ? 'comfortable' : 'compact'), [density]);

    return { density, setDensity, toggle };
}
