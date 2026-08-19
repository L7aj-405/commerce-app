import { useEffect, useState } from 'react';
import { ChevronDown, Check, X, SlidersHorizontal } from 'lucide-react';
import PriceRangeSlider from './PriceRangeSlider';

/**
 * Modular filter sidebar. It renders one section per filter config and picks the
 * input by `type` from FILTER_INPUTS — so supporting a new filter type (today:
 * checkbox, swatch, range) is just registering a component here + a behavior in
 * useProductFilters. Long option lists collapse behind "Show more" and each
 * section is an accordion, so the panel stays clean no matter how many filters.
 *
 * Props come straight from useProductFilters plus drawer control:
 *   filters, values, toggle, setRange, clear, clearAll, countFor, activeCount
 *   open, onClose  (mobile slide-over)
 */
export default function FilterSidebar({
    filters,
    values,
    toggle,
    setRange,
    clear,
    clearAll,
    countFor,
    activeCount = 0,
    open = false,
    onClose,
    className = '',
}) {
    const panel = (
        <div className="flex h-full flex-col bg-surface-2 border border-line rounded-xl overflow-hidden">
            <header className="flex-shrink-0 flex items-center justify-between px-4 py-3 border-b border-line">
                <div className="flex items-center gap-2">
                    <SlidersHorizontal className="w-4 h-4 text-content-muted" />
                    <h2 className="text-sm font-semibold text-content">Filters</h2>
                    {activeCount > 0 && (
                        <span className="inline-flex items-center justify-center min-w-5 h-5 px-1.5 text-[11px] font-semibold rounded-full bg-indigo-500/15 text-indigo-700 dark:text-indigo-300">
                            {activeCount}
                        </span>
                    )}
                </div>
                <div className="flex items-center gap-1">
                    {activeCount > 0 && (
                        <button
                            type="button"
                            onClick={clearAll}
                            className="text-xs text-content-muted hover:text-content transition"
                        >
                            Clear all
                        </button>
                    )}
                    {onClose && (
                        <button
                            type="button"
                            onClick={onClose}
                            aria-label="Close filters"
                            className="lg:hidden p-1 text-content-muted hover:text-content"
                        >
                            <X className="w-4 h-4" />
                        </button>
                    )}
                </div>
            </header>

            <div className="flex-1 min-h-0 overflow-y-auto divide-y divide-line">
                {filters.map((f) => (
                    <FilterSection key={f.key} filter={f} count={countFor(f)} onClear={() => clear(f.key)}>
                        {renderInput(f, { values, toggle, setRange })}
                    </FilterSection>
                ))}
            </div>
        </div>
    );

    return (
        <>
            {/* Desktop */}
            <aside className={`hidden lg:block w-72 flex-shrink-0 ${className}`}>{panel}</aside>

            {/* Mobile slide-over */}
            {open && (
                <div className="lg:hidden fixed inset-0 z-50 flex">
                    <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
                    <div className="relative ml-auto w-80 max-w-[85%] h-full p-2">{panel}</div>
                </div>
            )}
        </>
    );
}

/* ---------- Section (accordion) ---------- */

function FilterSection({ filter, count, onClear, children }) {
    const [open, setOpen] = useState(true);
    return (
        <section>
            <h3>
                <button
                    type="button"
                    onClick={() => setOpen((v) => !v)}
                    aria-expanded={open}
                    className="w-full flex items-center justify-between px-4 py-3 text-left group"
                >
                    <span className="flex items-center gap-2 text-sm font-medium text-content">
                        {filter.label}
                        {count > 0 && (
                            <span className="inline-flex items-center justify-center min-w-5 h-5 px-1.5 text-[10px] font-semibold rounded-full bg-indigo-500/15 text-indigo-700 dark:text-indigo-300">
                                {count}
                            </span>
                        )}
                    </span>
                    <ChevronDown className={`w-4 h-4 text-content-muted transition-transform ${open ? 'rotate-180' : ''}`} />
                </button>
            </h3>
            {open && (
                <div className="px-4 pb-4">
                    {count > 0 && (
                        <button
                            type="button"
                            onClick={onClear}
                            className="mb-2 text-[11px] text-content-muted hover:text-content underline-offset-2 hover:underline"
                        >
                            Reset
                        </button>
                    )}
                    {children}
                </div>
            )}
        </section>
    );
}

/* ---------- Input registry (the scalability seam) ---------- */

const FILTER_INPUTS = {
    checkbox: CheckboxList,
    swatch: SwatchGroup,
    range: RangeInput,
};

function renderInput(filter, ctx) {
    const Input = FILTER_INPUTS[filter.type] ?? CheckboxList;
    return <Input filter={filter} {...ctx} />;
}

function RangeInput({ filter, values, setRange }) {
    return (
        <PriceRangeSlider
            min={filter.min}
            max={filter.max}
            step={filter.step}
            value={values[filter.key]}
            onChange={(v) => setRange(filter.key, v)}
            format={filter.format}
        />
    );
}

function CheckboxList({ filter, values, toggle }) {
    const [showAll, setShowAll] = useState(false);
    const selected = values[filter.key] ?? [];
    const visible = showAll ? filter.options : filter.options.slice(0, 6);

    return (
        <div className="space-y-1">
            {visible.map((opt) => {
                const checked = selected.includes(opt.value);
                return (
                    <label
                        key={String(opt.value)}
                        className="flex items-center gap-2.5 py-1 cursor-pointer group/opt select-none"
                    >
                        <span
                            className={`flex items-center justify-center w-4 h-4 rounded border transition ${
                                checked ? 'bg-indigo-600 border-indigo-600' : 'border-line group-hover/opt:border-content-muted'
                            }`}
                        >
                            {checked && <Check className="w-3 h-3 text-white" />}
                        </span>
                        <input
                            type="checkbox"
                            className="sr-only"
                            checked={checked}
                            onChange={() => toggle(filter.key, opt.value)}
                        />
                        <span className="flex-1 text-sm text-content-muted group-hover/opt:text-content truncate">
                            {opt.label}
                        </span>
                        {opt.count != null && <span className="text-[11px] text-content-muted/70 tabular-nums">{opt.count}</span>}
                    </label>
                );
            })}

            {filter.options.length > 6 && (
                <button
                    type="button"
                    onClick={() => setShowAll((v) => !v)}
                    className="mt-1 text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                >
                    {showAll ? 'Show less' : `Show ${filter.options.length - 6} more`}
                </button>
            )}
        </div>
    );
}

function SwatchGroup({ filter, values, toggle }) {
    const selected = values[filter.key] ?? [];
    const isColor = filter.variant === 'color';

    return (
        <div className="flex flex-wrap gap-2">
            {filter.options.map((opt) => {
                const active = selected.includes(opt.value);
                if (isColor) {
                    return (
                        <button
                            key={String(opt.value)}
                            type="button"
                            onClick={() => toggle(filter.key, opt.value)}
                            aria-pressed={active}
                            title={opt.label}
                            className={`relative w-7 h-7 rounded-full border transition ${
                                active ? 'ring-2 ring-indigo-500 ring-offset-2 ring-offset-surface-2 border-transparent' : 'border-line'
                            }`}
                            style={{ backgroundColor: opt.hex }}
                        >
                            {active && <Check className="w-3.5 h-3.5 text-white absolute inset-0 m-auto drop-shadow" />}
                            <span className="sr-only">{opt.label}</span>
                        </button>
                    );
                }
                return (
                    <button
                        key={String(opt.value)}
                        type="button"
                        onClick={() => toggle(filter.key, opt.value)}
                        aria-pressed={active}
                        className={`min-w-9 px-2.5 h-9 rounded-lg border text-sm font-medium transition ${
                            active
                                ? 'bg-indigo-600 border-indigo-600 text-white'
                                : 'bg-surface border-line text-content-muted hover:text-content hover:border-content-muted/40'
                        }`}
                    >
                        {opt.label}
                    </button>
                );
            })}
        </div>
    );
}
