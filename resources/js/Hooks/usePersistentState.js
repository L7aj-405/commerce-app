import { useEffect, useState } from 'react';

/**
 * useState that persists to localStorage under `key`.
 * Falls back gracefully when storage is unavailable (private mode, SSR).
 */
export default function usePersistentState(key, defaultValue) {
    const [value, setValue] = useState(() => {
        try {
            const stored = window.localStorage.getItem(key);
            return stored !== null ? JSON.parse(stored) : defaultValue;
        } catch {
            return defaultValue;
        }
    });

    useEffect(() => {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch {
            /* ignore write failures */
        }
    }, [key, value]);

    return [value, setValue];
}
