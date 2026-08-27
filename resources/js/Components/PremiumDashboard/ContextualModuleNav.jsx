import { Link } from '@inertiajs/react';
import { isContextualTabActive } from '@/Support/contextualNav';

/**
 * Module-scoped tabs for the topbar center — replaces the old fixed
 * Dashboard/Orders/Products/Stock/Integrations row, which just duplicated
 * the icon rail. Tabs come from resolveContextualTabs() (Support/contextualNav.js)
 * and only ever reference real, permission-checked routes.
 */
export default function ContextualModuleNav({ tabs, currentUrl }) {
    if (tabs.length === 0) return null;

    return (
        <nav className="mx-auto hidden max-w-full items-center overflow-x-auto rounded-full bg-surface-soft p-1 lg:flex">
            {tabs.map((tab) => {
                const active = isContextualTabActive(currentUrl, tab.href);
                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        className={`whitespace-nowrap rounded-full px-4 py-2 text-xs font-semibold transition ${active ? 'bg-card text-text shadow-sm' : 'text-text-muted hover:text-text'}`}
                    >
                        {tab.label}
                    </Link>
                );
            })}
        </nav>
    );
}
