import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown } from 'lucide-react';

/**
 * Design-system replacement for a native select element — a fully custom,
 * keyboard-accessible listbox so the opened options menu actually matches
 * the app's design (Card/Input/Button tokens) instead of the browser's own
 * unstyleable native dropdown. Controlled, same shape as a typical
 * `value`/`onChange(value)` form field so it drops into existing
 * `setData('field', value)` call sites with no other changes.
 *
 * - `options`: `[{ value, label, disabled? }]` (a plain string is also
 *   accepted and normalized to `{ value: s, label: s }`).
 * - `searchable`: shows a filter input inside the open menu.
 * - `error`: truthy → red border (the error MESSAGE itself stays the
 *   caller's responsibility, matching every existing form's convention).
 * - `icon`: optional leading icon (e.g. for a compact pill trigger like the
 *   dashboard's date-range selector — pair with `buttonClassName` to fully
 *   restyle the trigger while keeping this component's listbox/keyboard
 *   behavior).
 */
export default function Select({
    value,
    onChange,
    options,
    placeholder = 'Select…',
    disabled = false,
    error = false,
    searchable = false,
    icon: Icon = null,
    className = '',
    buttonClassName = '',
    menuClassName = '',
    ariaLabel,
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [highlighted, setHighlighted] = useState(0);
    const rootRef = useRef(null);
    const listRef = useRef(null);
    const searchRef = useRef(null);
    const buttonId = useId();
    const listId = useId();

    const normalizedOptions = useMemo(
        () => (options ?? []).map((o) => (typeof o === 'string' || typeof o === 'number' ? { value: o, label: String(o) } : o)),
        [options],
    );
    const filteredOptions = useMemo(() => {
        if (! searchable || ! query.trim()) return normalizedOptions;
        const needle = query.trim().toLowerCase();
        return normalizedOptions.filter((o) => o.label.toLowerCase().includes(needle));
    }, [normalizedOptions, searchable, query]);

    const selected = normalizedOptions.find((o) => String(o.value) === String(value));

    // Close on outside click.
    useEffect(() => {
        if (! open) return undefined;
        const onDocMouseDown = (event) => {
            if (rootRef.current && ! rootRef.current.contains(event.target)) setOpen(false);
        };
        document.addEventListener('mousedown', onDocMouseDown);
        return () => document.removeEventListener('mousedown', onDocMouseDown);
    }, [open]);

    // On open: reset the search filter, highlight the current value (or the
    // first option), and focus the search input if searchable.
    useEffect(() => {
        if (! open) return;
        setQuery('');
        const idx = normalizedOptions.findIndex((o) => String(o.value) === String(value));
        setHighlighted(idx >= 0 ? idx : 0);
        if (searchable) {
            const raf = requestAnimationFrame(() => searchRef.current?.focus());
            return () => cancelAnimationFrame(raf);
        }
        return undefined;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => {
        if (! open || ! listRef.current) return;
        listRef.current.querySelector(`[data-index="${highlighted}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [highlighted, open]);

    const commit = (option) => {
        if (! option || option.disabled) return;
        onChange?.(option.value);
        setOpen(false);
    };

    const onKeyDown = (event) => {
        if (disabled) return;

        if (! open) {
            if (['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                setOpen(true);
            }
            return;
        }

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                setHighlighted((i) => Math.min(i + 1, filteredOptions.length - 1));
                break;
            case 'ArrowUp':
                event.preventDefault();
                setHighlighted((i) => Math.max(i - 1, 0));
                break;
            case 'Home':
                event.preventDefault();
                setHighlighted(0);
                break;
            case 'End':
                event.preventDefault();
                setHighlighted(filteredOptions.length - 1);
                break;
            case 'Enter':
                event.preventDefault();
                commit(filteredOptions[highlighted]);
                break;
            case 'Tab':
                setOpen(false);
                break;
            case 'Escape':
                event.preventDefault();
                setOpen(false);
                break;
            default:
                break;
        }
    };

    const defaultButtonClass = `flex w-full items-center justify-between gap-2 rounded-lg border ${error ? 'border-danger' : 'border-line'} bg-surface-3 px-3 py-2 text-left text-sm text-content transition focus:outline-none focus:ring-2 focus:ring-primary disabled:cursor-not-allowed disabled:opacity-60`;

    return (
        <div ref={rootRef} className={`relative ${className}`}>
            <button
                type="button"
                id={buttonId}
                disabled={disabled}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={listId}
                aria-label={ariaLabel}
                onClick={() => ! disabled && setOpen((o) => ! o)}
                onKeyDown={onKeyDown}
                className={buttonClassName || defaultButtonClass}
            >
                {Icon && <Icon className="h-4 w-4 flex-shrink-0 text-content-muted" strokeWidth={1.8} />}
                <span className={`min-w-0 flex-1 truncate ${selected ? '' : 'text-content-muted'}`}>{selected ? selected.label : placeholder}</span>
                <ChevronDown className={`h-3.5 w-3.5 flex-shrink-0 text-content-muted transition-transform duration-150 ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className={`absolute z-30 mt-1.5 min-w-full overflow-hidden rounded-xl border border-line bg-card shadow-lg ${menuClassName}`}>
                    {searchable && (
                        <div className="border-b border-line p-1.5">
                            <input
                                ref={searchRef}
                                value={query}
                                onChange={(event) => { setQuery(event.target.value); setHighlighted(0); }}
                                onKeyDown={onKeyDown}
                                placeholder="Search…"
                                className="w-full rounded-md bg-surface-3 px-2 py-1.5 text-sm text-content outline-none"
                            />
                        </div>
                    )}
                    <ul ref={listRef} id={listId} role="listbox" aria-labelledby={buttonId} className="max-h-60 overflow-y-auto py-1">
                        {filteredOptions.length === 0 && <li className="px-3 py-2 text-sm text-content-muted">No options</li>}
                        {filteredOptions.map((option, index) => {
                            const isSelected = String(option.value) === String(value);
                            const isHighlighted = index === highlighted;
                            return (
                                <li
                                    key={option.value}
                                    data-index={index}
                                    role="option"
                                    aria-selected={isSelected}
                                    onMouseEnter={() => setHighlighted(index)}
                                    onClick={() => commit(option)}
                                    className={`flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm transition ${
                                        option.disabled ? 'cursor-not-allowed opacity-50' : ''
                                    } ${isHighlighted && ! option.disabled ? 'bg-surface-3' : ''} ${isSelected ? 'font-medium text-primary' : 'text-content'}`}
                                >
                                    <span className="min-w-0 flex-1 truncate">{option.label}</span>
                                    {isSelected && <Check className="h-3.5 w-3.5 flex-shrink-0" strokeWidth={2.2} />}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}
        </div>
    );
}
