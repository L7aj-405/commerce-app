import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Search, X } from 'lucide-react';

/**
 * Cmd/Ctrl+K search over every nav item the current user can access
 * (SaasLayout's permission-filtered `allItems`). Extracted out of
 * SaasLayout.jsx so it can be reused by CommandSearchBar's dashboard-root
 * inline pill and its collapsed icon-button form on every other page.
 */
export default function CommandPalette({ open, onClose, items }) {
    const [query, setQuery] = useState('');
    const filtered = items.filter((item) => `${item.label} ${item.section}`.toLowerCase().includes(query.trim().toLowerCase()));

    useEffect(() => {
        if (open) setQuery('');
    }, [open]);

    useEffect(() => {
        if (! open) return undefined;
        const onKeyDown = (event) => event.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, onClose]);

    if (! open) return null;

    const visit = (href) => {
        onClose();
        router.visit(href);
    };

    return (
        <div className="fixed inset-0 z-[70] flex items-start justify-center px-4 pt-[12vh]">
            <button type="button" aria-label="Close search" onClick={onClose} className="absolute inset-0 bg-text/25 backdrop-blur-[3px]" />
            <div role="dialog" aria-modal="true" aria-label="Search navigation" className="page-enter relative w-full max-w-xl overflow-hidden rounded-[26px] border border-border bg-card shadow-premium">
                <div className="flex items-center gap-3 border-b border-border px-5">
                    <Search className="h-5 w-5 text-primary" strokeWidth={1.8} />
                    <input autoFocus value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search orders, products, stock, integrations…" className="h-16 flex-1 bg-transparent text-sm text-text placeholder:text-text-muted focus:outline-none" />
                    <button type="button" onClick={onClose} className="flex h-9 w-9 items-center justify-center rounded-full bg-surface-soft text-text-muted" aria-label="Close search"><X className="h-4 w-4" /></button>
                </div>
                <div className="max-h-[24rem] overflow-y-auto p-2">
                    {filtered.length === 0 ? (
                        <div className="px-4 py-10 text-center text-sm text-text-muted">No accessible page matches “{query}”.</div>
                    ) : filtered.map((item) => {
                        const Icon = item.icon;
                        return (
                            <button key={`${item.section}-${item.href}`} type="button" onClick={() => visit(item.href)} className="flex w-full items-center gap-3 rounded-2xl px-3 py-3 text-left transition hover:bg-surface-soft">
                                <span className="flex h-9 w-9 items-center justify-center rounded-2xl bg-primary-soft text-primary"><Icon className="h-4 w-4" strokeWidth={1.8} /></span>
                                <span className="min-w-0 flex-1"><span className="block truncate text-sm font-semibold text-text">{item.label}</span><span className="block text-[11px] text-text-muted">{item.section}</span></span>
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
