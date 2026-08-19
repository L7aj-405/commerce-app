import { List, LayoutGrid, Grid3x3, Minus, Plus } from 'lucide-react';

export const VIEW_DEFAULT_COLUMNS = { small: 5, large: 3 };
export const MIN_COLUMNS = 2;
export const MAX_COLUMNS = 8;

const VIEWS = [
    { key: 'list',  label: 'List',        Icon: List },
    { key: 'large', label: 'Large cards', Icon: LayoutGrid },
    { key: 'small', label: 'Small cards', Icon: Grid3x3 },
];

/**
 * Toolbar control: switch product layout between List / Large / Small cards,
 * and fine-tune items-per-row for the card views.
 */
export default function ProductViewControls({ view, columns, onViewChange, onColumnsChange }) {
    const isCards = view !== 'list';

    const setColumns = (next) => {
        onColumnsChange(Math.min(MAX_COLUMNS, Math.max(MIN_COLUMNS, next)));
    };

    return (
        <div className="flex items-center gap-2">
            {/* View toggle (segmented) */}
            <div className="inline-flex items-center rounded-lg border border-line bg-surface-2 p-0.5">
                {VIEWS.map(({ key, label, Icon }) => {
                    const active = view === key;
                    return (
                        <button
                            key={key}
                            type="button"
                            onClick={() => onViewChange(key)}
                            title={label}
                            aria-label={label}
                            aria-pressed={active}
                            className={`inline-flex items-center justify-center w-8 h-8 rounded-md transition ${
                                active
                                    ? 'bg-indigo-600 text-white shadow-sm'
                                    : 'text-content-muted hover:text-content hover:bg-surface-3'
                            }`}
                        >
                            <Icon className="w-4 h-4" />
                        </button>
                    );
                })}
            </div>

            {/* Items-per-row stepper — card views only */}
            {isCards && (
                <div className="hidden md:inline-flex items-center rounded-lg border border-line bg-surface-2">
                    <button
                        type="button"
                        onClick={() => setColumns(columns - 1)}
                        disabled={columns <= MIN_COLUMNS}
                        aria-label="Fewer per row"
                        className="w-8 h-8 flex items-center justify-center text-content-muted hover:text-content disabled:opacity-30 disabled:cursor-not-allowed"
                    >
                        <Minus className="w-4 h-4" />
                    </button>
                    <span className="w-8 text-center text-sm font-medium text-content tabular-nums select-none">
                        {columns}
                    </span>
                    <button
                        type="button"
                        onClick={() => setColumns(columns + 1)}
                        disabled={columns >= MAX_COLUMNS}
                        aria-label="More per row"
                        className="w-8 h-8 flex items-center justify-center text-content-muted hover:text-content disabled:opacity-30 disabled:cursor-not-allowed"
                    >
                        <Plus className="w-4 h-4" />
                    </button>
                </div>
            )}
        </div>
    );
}
