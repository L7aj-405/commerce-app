import { useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, MapPin } from 'lucide-react';

/**
 * Searchable city dropdown — never a free-text city input. `options` come
 * from the backend's city_options payload (provider-synced cities preferred,
 * falling back to the internal canonical city list — see
 * DeliveryProviderFinanceSettingController::cityOptionsFor()). Selecting an
 * option reports its `value` (a real id), never typed text.
 */
export default function CitySearchSelect({ options, value, onChange, placeholder = 'Search a city…', error }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const containerRef = useRef(null);

    const selected = options.find((o) => o.value === value) ?? null;

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (! q) return options;
        return options.filter((o) => [o.label, o.sublabel, o.code].filter(Boolean).some((v) => v.toLowerCase().includes(q)));
    }, [options, query]);

    const closeOnBlur = () => {
        // Deferred so a click on an option registers before the blur closes the list.
        setTimeout(() => setOpen(false), 120);
    };

    return (
        <div className="relative" ref={containerRef}>
            <div className={`flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-danger' : 'border-line'} focus-within:ring-2 focus-within:ring-primary`}>
                <MapPin className="w-4 h-4 flex-shrink-0 text-content-muted" />
                <input
                    type="text"
                    value={open ? query : (selected?.label ?? '')}
                    onFocus={() => { setOpen(true); setQuery(''); }}
                    onBlur={closeOnBlur}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={selected ? selected.label : placeholder}
                    className="w-full bg-transparent text-sm text-content placeholder:text-content-muted focus:outline-none"
                />
                <ChevronDown className="w-4 h-4 flex-shrink-0 text-content-muted" />
            </div>

            {open && (
                <div className="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto rounded-lg border border-line bg-surface-2 shadow-xl py-1">
                    {filtered.length === 0 ? (
                        <p className="px-3 py-2 text-xs text-content-muted">No matching city.</p>
                    ) : (
                        filtered.map((o) => (
                            <button
                                key={o.value}
                                type="button"
                                onClick={() => { onChange(o.value); setOpen(false); setQuery(''); }}
                                className="w-full flex items-center justify-between gap-2 px-3 py-2 text-sm text-left hover:bg-surface-3"
                            >
                                <span className="min-w-0">
                                    <span className="text-content">{o.label}</span>
                                    {o.sublabel && <span className="text-content-muted"> · {o.sublabel}</span>}
                                    {o.code && <span className="ml-1 text-xs text-content-muted">({o.code})</span>}
                                </span>
                                {o.value === value && <Check className="w-3.5 h-3.5 flex-shrink-0 text-primary" />}
                            </button>
                        ))
                    )}
                </div>
            )}

            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}
