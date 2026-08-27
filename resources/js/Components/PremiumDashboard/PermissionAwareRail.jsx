import { Link } from '@inertiajs/react';
import { Grid2X2 } from 'lucide-react';

/**
 * Compact floating icon dock — quick access only, curated per role by
 * curateRailItems() (see Support/roleShortcuts.js). The complete
 * permission-aware navigation lives in FullNavigationDrawer, opened by the
 * top launcher icon here.
 *
 * Deliberately NOT a full-height sidebar: it's a small pill-shaped dock,
 * sized to its own content, vertically centered in the viewport (`fixed
 * top-1/2 -translate-y-1/2`) rather than stretched/pinned high.
 */
export default function PermissionAwareRail({ items, currentUrl, badges = {}, onOpenDrawer, utilityItem = null }) {
    return (
        <aside className="fixed left-5 top-1/2 z-20 hidden w-14 -translate-y-1/2 flex-col items-center gap-1.5 rounded-[22px] border border-border bg-card px-0 py-2.5 shadow-premium lg:flex">
            <button
                type="button"
                onClick={onOpenDrawer}
                className="group relative flex h-11 w-11 items-center justify-center rounded-2xl bg-primary text-white transition hover:-translate-y-0.5 hover:brightness-95"
                aria-label="Open all navigation"
            >
                <Grid2X2 className="h-[18px] w-[18px]" strokeWidth={2} />
                <Tooltip>All modules</Tooltip>
            </button>

            {items.length > 0 && (
                <nav className="mt-1.5 flex w-full flex-col items-center gap-1.5">
                    {items.map((item) => {
                        const Icon = item.icon;
                        const active = isItemActive(currentUrl, item);
                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`group relative flex h-10 w-10 items-center justify-center rounded-2xl transition-all duration-200 ${active ? 'bg-primary-soft text-primary' : 'text-text-muted hover:bg-surface-soft hover:text-text'}`}
                                aria-label={item.label}
                            >
                                <Icon className="h-[18px] w-[18px]" strokeWidth={1.8} />
                                {badges[item.href] > 0 && (
                                    <span className="absolute right-0.5 top-0.5 h-2 w-2 rounded-full bg-rose-500 ring-2 ring-card" />
                                )}
                                <Tooltip>{item.label}</Tooltip>
                            </Link>
                        );
                    })}
                </nav>
            )}

            {utilityItem && (
                <Link
                    href={utilityItem.href}
                    aria-label={utilityItem.label}
                    className="group relative mt-1 flex h-10 w-10 items-center justify-center rounded-2xl text-text-muted transition hover:bg-surface-soft hover:text-text"
                >
                    <utilityItem.icon className="h-4 w-4" />
                    <Tooltip>{utilityItem.label}</Tooltip>
                </Link>
            )}
        </aside>
    );
}

function Tooltip({ children }) {
    return (
        <span className="pointer-events-none absolute left-full z-40 ml-3 hidden whitespace-nowrap rounded-full bg-text px-2.5 py-1 text-[10px] font-semibold text-canvas opacity-0 shadow-lg transition group-hover:opacity-100 lg:block">
            {children}
        </span>
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
