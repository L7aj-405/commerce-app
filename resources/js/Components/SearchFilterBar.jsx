import { useEffect, useState } from 'react';
import { Search, X } from 'lucide-react';

const isMac = typeof navigator !== 'undefined' && /Mac/.test(navigator.platform);

export default function SearchFilterBar({
    placeholder = 'Search…',
    value = '',
    onSearch,
    filters = [],
    activeFilters = {},
    onFilterChange,
}) {
    const [query, setQuery] = useState(value);

    useEffect(() => { setQuery(value); }, [value]);

    const submit = (e) => {
        e.preventDefault();
        onSearch?.(query);
    };

    const clearChip = (key) => onFilterChange?.(key, '');

    const activeChips = Object.entries(activeFilters).filter(([, v]) => v !== '' && v != null);

    return (
        <div className="bg-surface-2 border border-line rounded-xl p-3 shadow-sm dark:shadow-none">
            <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
                <div className="relative flex-1 min-w-[240px]">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-content-muted pointer-events-none" />
                    <input
                        type="text"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={placeholder}
                        className="w-full pl-9 pr-14 py-2 text-sm rounded-lg bg-surface border border-line text-content placeholder:text-content-muted focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                    />
                    <kbd className="absolute right-2 top-1/2 -translate-y-1/2 text-[10px] font-medium text-content-muted bg-surface-2 border border-line rounded px-1.5 py-0.5">
                        {isMac ? '⌘K' : 'Ctrl K'}
                    </kbd>
                </div>

                {filters.map((f) => (
                    <select
                        key={f.key}
                        value={activeFilters[f.key] ?? ''}
                        onChange={(e) => onFilterChange?.(f.key, e.target.value)}
                        aria-label={f.label}
                        className="px-3 py-2 text-sm rounded-lg bg-surface border border-line text-content-muted focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                    >
                        <option value="">{f.label}</option>
                        {f.options.map((o) => (
                            <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                    </select>
                ))}
            </form>

            {activeChips.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {activeChips.map(([key, val]) => {
                        const filter = filters.find((f) => f.key === key);
                        const label  = filter?.options.find((o) => String(o.value) === String(val))?.label ?? val;
                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => clearChip(key)}
                                className="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded-full bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-500/25 transition"
                            >
                                <span className="text-indigo-600/70 dark:text-indigo-400/70">{filter?.label}:</span>
                                {label}
                                <X className="w-3 h-3" />
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
