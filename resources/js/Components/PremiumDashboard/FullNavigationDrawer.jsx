import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { CircleDot, Search, X } from 'lucide-react';
import StoreSwitcher from '@/Components/StoreSwitcher';

// Display grouping only — every underlying section/item label stays exactly
// as NAV_SECTIONS defines it (see SaasLayout.jsx and the regression tests
// that assert those literal strings). A section falls into a domain via its
// own `domain` field; an individual item can override that with its own
// `domain` (used for "Integrations", which lives inside the Settings section
// but is its own domain in this drawer).
const DOMAIN_ORDER = ['Overview', 'Commerce', 'Orders', 'Fulfillment', 'Inventory', 'Integrations', 'Settings'];

function groupByDomain(sections) {
    const buckets = new Map(DOMAIN_ORDER.map((domain) => [domain, []]));

    sections.forEach((section) => {
        section.items.forEach((item) => {
            const domain = item.domain ?? section.domain ?? 'Settings';
            if (! buckets.has(domain)) buckets.set(domain, []);
            buckets.get(domain).push({ ...item, section: section.label });
        });
    });

    return DOMAIN_ORDER
        .map((domain) => ({ domain, items: buckets.get(domain) ?? [] }))
        .filter((group) => group.items.length > 0);
}

export default function FullNavigationDrawer({ sections, currentUrl, badges = {}, open, onOpenChange, agency = false }) {
    const [query, setQuery] = useState('');
    const groups = useMemo(() => groupByDomain(sections), [sections]);
    const needle = query.trim().toLowerCase();

    const visibleGroups = needle
        ? groups
            .map((group) => ({ ...group, items: group.items.filter((item) => item.label.toLowerCase().includes(needle)) }))
            .filter((group) => group.items.length > 0)
        : groups;

    if (! open) return null;

    return (
        <div className="fixed inset-0 z-50 flex">
            <button type="button" onClick={() => onOpenChange(false)} aria-label="Close navigation" className="absolute inset-0 bg-text/25 backdrop-blur-[2px]" />
            <aside className="page-enter relative flex h-full w-[310px] max-w-[88vw] flex-col border-r border-border bg-surface-soft p-4 shadow-2xl">
                <div className="flex items-center justify-between gap-3 px-1 pb-4">
                    <Link href="/dashboard" onClick={() => onOpenChange(false)} className="flex items-center gap-2.5">
                        <span className="flex h-9 w-9 items-center justify-center rounded-2xl bg-primary text-white">
                            <CircleDot className="h-[18px] w-[18px]" />
                        </span>
                        <span>
                            <span className="block text-sm font-bold tracking-tight text-text">SaaS Commerce</span>
                            <span className="block text-[10px] font-semibold uppercase tracking-[0.14em] text-text-muted">Operations</span>
                        </span>
                    </Link>
                    <button type="button" onClick={() => onOpenChange(false)} className="flex h-9 w-9 items-center justify-center rounded-full bg-card text-text-muted shadow-sm" aria-label="Close navigation">
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <StoreSwitcher tone="emerald" />
                {agency && (
                    <Link href="/agency/clients" onClick={() => onOpenChange(false)} className="mt-2 px-2 text-xs font-semibold text-primary">Agency workspace</Link>
                )}

                <div className="relative mt-4">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted" />
                    <input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search the menu…"
                        className="w-full rounded-xl border border-border bg-input py-2 pl-9 pr-3 text-sm text-text placeholder:text-text-muted focus:outline-none"
                    />
                </div>

                <nav className="mt-4 flex-1 space-y-5 overflow-y-auto pr-1">
                    {visibleGroups.length === 0 && (
                        <p className="px-2 text-sm text-text-muted">No menu item matches “{query}”.</p>
                    )}
                    {visibleGroups.map((group) => (
                        <div key={group.domain}>
                            <p className="px-3 text-[9px] font-bold uppercase tracking-[0.16em] text-text-muted">{group.domain}</p>
                            <div className="mt-1.5 space-y-1">
                                {group.items.map((item) => {
                                    const Icon = item.icon;
                                    const active = isItemActive(currentUrl, item);
                                    return (
                                        <Link
                                            key={item.href}
                                            href={item.href}
                                            onClick={() => onOpenChange(false)}
                                            className={`flex items-center gap-3 rounded-2xl px-3 py-2.5 text-[13px] font-medium transition ${active ? 'bg-primary-soft text-primary' : 'text-text-muted hover:bg-card hover:text-text'}`}
                                        >
                                            <Icon className="h-4 w-4" strokeWidth={1.8} />
                                            <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                            {badges[item.href] > 0 && (
                                                <span className="rounded-full bg-primary px-2 py-0.5 text-[9px] font-bold text-white">{badges[item.href] > 99 ? '99+' : badges[item.href]}</span>
                                            )}
                                        </Link>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </nav>
            </aside>
        </div>
    );
}

function isActive(currentUrl, href) {
    const current = String(currentUrl ?? '').split('?')[0].replace(/\/+$/, '');
    const target = String(href ?? '').split('?')[0].replace(/\/+$/, '');
    if (target === '/dashboard') return current === target;
    return current === target || current.startsWith(`${target}/`);
}

function isItemActive(currentUrl, item) {
    return isActive(currentUrl, item.href)
        || (item.activeOn ?? []).some((href) => isActive(currentUrl, href));
}
