import { Moon, Sun } from 'lucide-react';
import useTheme from '@/Hooks/useTheme';

/**
 * Clean icon toggle that flips between light and dark.
 * Drop it anywhere (headers, user menus): <ThemeToggle />
 */
export default function ThemeToggle({ className = '' }) {
    const { isDark, toggle } = useTheme();

    return (
        <button
            type="button"
            onClick={toggle}
            role="switch"
            aria-checked={isDark}
            aria-label={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
            title={isDark ? 'Light mode' : 'Dark mode'}
            className={`inline-flex items-center justify-center w-9 h-9 rounded-lg border border-line bg-surface-2 text-content-muted hover:text-content hover:bg-surface-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 transition ${className}`}
        >
            {isDark ? <Sun className="w-4 h-4" /> : <Moon className="w-4 h-4" />}
        </button>
    );
}
