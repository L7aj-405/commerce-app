import { X } from 'lucide-react';

/**
 * Removable tags for the currently-active filters. Feed it `chips` and
 * `onClearAll` from useProductFilters. Renders nothing when no filters are set.
 */
export default function ActiveFilterChips({ chips = [], onClearAll, className = '' }) {
    if (chips.length === 0) return null;

    return (
        <div className={`flex flex-wrap items-center gap-2 ${className}`}>
            <span className="text-xs text-content-muted">Filters:</span>

            {chips.map((chip) => (
                <button
                    key={chip.id}
                    type="button"
                    onClick={chip.onRemove}
                    className="group inline-flex items-center gap-1.5 pl-2 pr-1.5 py-1 rounded-full text-xs bg-surface-2 border border-line text-content hover:border-content-muted/40 transition"
                >
                    {chip.swatch && (
                        <span className="w-3 h-3 rounded-full border border-line" style={{ backgroundColor: chip.swatch }} />
                    )}
                    <span className="text-content-muted">{chip.filter}:</span>
                    <span className="font-medium">{chip.label}</span>
                    <span className="flex items-center justify-center w-4 h-4 rounded-full text-content-muted group-hover:bg-content/10 group-hover:text-content transition">
                        <X className="w-3 h-3" />
                    </span>
                </button>
            ))}

            {chips.length > 1 && (
                <button
                    type="button"
                    onClick={onClearAll}
                    className="text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                >
                    Clear all
                </button>
            )}
        </div>
    );
}
