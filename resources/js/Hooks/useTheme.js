import { useCallback, useSyncExternalStore } from 'react';

/**
 * Global theme state. Preference is 'light' | 'dark' | 'system' and persists to
 * localStorage under `theme`. The resolved mode toggles `.dark` on <html>, which
 * is what Tailwind's `darkMode: 'class'` (and the app.css tokens) key off of.
 *
 * The same read/apply logic runs as an inline <head> script in app.blade.php to
 * avoid a flash of the wrong theme before this bundle loads.
 */
const KEY = 'theme';
const listeners = new Set();
const media = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null;

function storedPreference() {
    try {
        return window.localStorage.getItem(KEY) || 'system';
    } catch {
        return 'system';
    }
}

export function resolveDark(pref = storedPreference()) {
    return pref === 'dark' || (pref === 'system' && !!media?.matches);
}

export function applyTheme(pref = storedPreference()) {
    document.documentElement.classList.toggle('dark', resolveDark(pref));
}

export function setTheme(pref) {
    try {
        window.localStorage.setItem(KEY, pref);
    } catch {
        /* ignore */
    }
    applyTheme(pref);
    listeners.forEach((cb) => cb());
}

function subscribe(cb) {
    listeners.add(cb);

    // React to OS changes while in 'system', and to other tabs.
    const onSystem = () => { if (storedPreference() === 'system') { applyTheme(); cb(); } };
    const onStorage = (e) => { if (e.key === KEY) { applyTheme(); cb(); } };
    media?.addEventListener?.('change', onSystem);
    window.addEventListener('storage', onStorage);

    return () => {
        listeners.delete(cb);
        media?.removeEventListener?.('change', onSystem);
        window.removeEventListener('storage', onStorage);
    };
}

export default function useTheme() {
    const theme = useSyncExternalStore(subscribe, storedPreference, () => 'system');
    const isDark = resolveDark(theme);

    const toggle = useCallback(() => setTheme(resolveDark() ? 'light' : 'dark'), []);

    return { theme, isDark, setTheme, toggle };
}
