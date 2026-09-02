import { useEffect, useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronDown, CircleDot, Search, X } from 'lucide-react';
import StoreSwitcher from '@/Components/StoreSwitcher';

// Display grouping only — every underlying section/item label stays exactly
// as NAV_SECTIONS defines it (see SaasLayout.jsx and the regression tests
// that assert those literal strings). A section falls into a domain via its
// own `domain` field; an individual item can override that with its own
// `domain` (used for "Integrations", which lives inside the Settings section
// but is its own domain in this drawer).
const DOMAIN_ORDER = ['Overview', 'Commerce', 'Orders', 'Fulfillment', 'Inventory', 'Finance', 'Integrations', 'Settings'];

// Session-only memory of which groups the user left open — cleared when the
// tab/session ends, never synced anywhere. Wrapped defensively: sessionStorage
// can throw (private browsing, storage disabled) and must never break the menu.
const EXPANDED_GROUPS_KEY = 'nav-drawer-expanded-groups';

function readStoredExpanded() {
    try {
        const raw = window.sessionStorage.getItem(EXPANDED_GROUPS_KEY);
        return raw ? new Set(JSON.parse(raw)) : null;
    } catch {
        return null;
    }
}

function writeStoredExpanded(expanded) {
    try {
        window.sessionStorage.setItem(EXPANDED_GROUPS_KEY, JSON.stringify([...expanded]));
    } catch {
        // Ignore — this is a convenience, never load-bearing.
    }
}

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

export default function FullNavigationDrawer({ sections, currentUrl, badges = {}, open, onOpenChange, agency = false, onMouseEnter, onMouseLeave }) {
    const [query, setQuery] = useState('');
    const groups = useMemo(() => groupByDomain(sections), [sections]);
    const needle = query.trim().toLowerCase();

    const activeDomain = useMemo(
        () => groups.find((group) => group.items.some((item) => isItemActive(currentUrl, item)))?.domain ?? null,
        [groups, currentUrl],
    );

    // Collapsed by default (keeps the drawer short) except the domain the
    // user is currently on, which always starts expanded — restored from
    // this tab's sessionStorage when present, so a re-open mid-session
    // remembers what the user had open. The active domain is unioned back
    // in every time regardless of what was stored, so navigating never
    // hides the group you're actually looking at.
    const [expanded, setExpanded] = useState(() => {
        const stored = readStoredExpanded();
        const base = stored ?? new Set();
        if (activeDomain) base.add(activeDomain);
        return base;
    });

    useEffect(() => {
        if (! activeDomain) return;
        setExpanded((prev) => (prev.has(activeDomain) ? prev : new Set(prev).add(activeDomain)));
    }, [activeDomain]);

    const toggleGroup = (domain) => {
        setExpanded((prev) => {
            const next = new Set(prev);
            if (next.has(domain)) next.delete(domain); else next.add(domain);
            writeStoredExpanded(next);
            return next;
        });
    };

    const visibleGroups = needle
        ? groups
            .map((group) => ({ ...group, items: group.items.filter((item) => item.label.toLowerCase().includes(needle)) }))
            .filter((group) => group.items.length > 0)
        : groups;

    useEffect(() => {
        if (! open) return undefined;
        const onKeyDown = (event) => {
            if (event.key === 'Escape') onOpenChange(false);
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, onOpenChange]);

    if (! open) return null;

    return (
        <div className="fixed inset-0 z-50 flex">
            <button type="button" onClick={() => onOpenChange(false)} aria-label="Close navigation" className="absolute inset-0 bg-text/25 backdrop-blur-[2px]" />
            <aside
                onMouseEnter={onMouseEnter}
                onMouseLeave={onMouseLeave}
                className="drawer-slide-in relative flex h-full w-[310px] max-w-[88vw] flex-col border-r border-border bg-surface-soft p-4 shadow-2xl"
            >
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

                <nav className="mt-4 flex-1 space-y-1 overflow-y-auto pr-1">
                    {visibleGroups.length === 0 && (
                        <p className="px-2 text-sm text-text-muted">No menu item matches “{query}”.</p>
                    )}
                    {visibleGroups.map((group) => {
                        // While searching, every matching group shows fully
                        // open — collapse state is a "browse" convenience,
                        // never something that can hide a search result.
                        const isOpen = needle !== '' || expanded.has(group.domain);
                        const isActiveGroup = group.domain === activeDomain;

                        return (
                            <div key={group.domain}>
                                <button
                                    type="button"
                                    onClick={() => toggleGroup(group.domain)}
                                    aria-expanded={isOpen}
                                    className={`flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-left text-[10px] font-bold uppercase tracking-[0.14em] transition hover:bg-card ${isActiveGroup ? 'text-primary' : 'text-text-muted'}`}
                                >
                                    <span className="flex items-center gap-1.5">
                                        {group.domain}
                                        {isActiveGroup && <span className="h-1.5 w-1.5 rounded-full bg-primary" aria-hidden="true" />}
                                    </span>
                                    <ChevronDown className={`h-3.5 w-3.5 flex-shrink-0 transition-transform duration-200 ${isOpen ? 'rotate-180' : ''}`} />
                                </button>

                                <div className={`grid transition-all duration-200 ease-out ${isOpen ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}>
                                    <div className="min-h-0 space-y-1 overflow-hidden pb-1 pt-0.5">
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
                            </div>
                        );
                    })}
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
