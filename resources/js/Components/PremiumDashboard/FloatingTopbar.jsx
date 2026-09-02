import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { CircleDot, Grid2X2, Menu, Plus, Zap } from 'lucide-react';
import NotificationBell from '@/Components/NotificationBell';
import StoreSwitcher from '@/Components/StoreSwitcher';
import UserDropdown from '@/Components/UserDropdown';
import ThemeToggle from '@/Components/ThemeToggle';
import ContextualModuleNav from '@/Components/PremiumDashboard/ContextualModuleNav';
import CommandSearchBar from '@/Components/PremiumDashboard/CommandSearchBar';

export default function FloatingTopbar({
    currentUrl,
    isDashboard,
    contextualTabs = [],
    onOpenNavigation,
    onOpenSearch,
    orderNotif,
    quickActions = [],
}) {
    const [actionsOpen, setActionsOpen] = useState(false);
    // Sticky header (see PremiumAppShell — its ancestor uses overflow-clip
    // specifically so this can stay pinned instead of scrolling away). A
    // slightly stronger shadow/border once the page has actually scrolled
    // gives a subtle "lifted above the content" cue without changing
    // anything while at the top of the page.
    const [scrolled, setScrolled] = useState(false);
    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 8);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    return (
        <header className="sticky top-0 z-30 px-3 pt-3 sm:px-5 sm:pt-5">
            <div className={`flex h-[74px] items-center gap-3 rounded-[26px] border border-border bg-glass px-3 backdrop-blur-xl transition-shadow duration-200 sm:px-4 ${scrolled ? 'shadow-[0_26px_60px_-32px_rgba(0,0,0,0.45)]' : 'shadow-premium'}`}>
                <button type="button" onClick={onOpenNavigation} className="flex h-10 w-10 items-center justify-center rounded-2xl bg-surface-soft text-text-muted lg:hidden" aria-label="Open navigation">
                    <Menu className="h-[18px] w-[18px]" />
                </button>

                <Link href="/dashboard" className="hidden items-center gap-2.5 lg:flex">
                    <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-primary text-white">
                        <CircleDot className="h-5 w-5" strokeWidth={2} />
                    </span>
                    <span className="hidden text-sm font-bold tracking-tight text-text 2xl:block">SaaS Commerce</span>
                </Link>

                <div className="hidden w-52 xl:block">
                    <StoreSwitcher tone="emerald" />
                </div>

                <div className="mx-auto flex min-w-0 flex-1 items-center justify-center gap-2">
                    {isDashboard ? (
                        <CommandSearchBar variant="inline" onOpen={onOpenSearch} />
                    ) : (
                        <>
                            <ContextualModuleNav tabs={contextualTabs} currentUrl={currentUrl} />
                            <CommandSearchBar variant="icon" onOpen={onOpenSearch} />
                        </>
                    )}
                </div>

                <div className="ml-auto flex items-center gap-1 sm:gap-2">
                    {quickActions.length > 0 && (
                        <div className="relative hidden md:block">
                            <button
                                type="button"
                                onClick={() => setActionsOpen((value) => !value)}
                                className="inline-flex items-center gap-2 rounded-full bg-primary px-3.5 py-2.5 text-xs font-semibold text-white transition hover:brightness-95"
                            >
                                <Plus className="h-3.5 w-3.5" /> Quick actions
                            </button>
                            {actionsOpen && (
                                <div className="page-enter absolute right-0 mt-2 w-52 rounded-2xl border border-border bg-card p-2 shadow-premium">
                                    <p className="flex items-center gap-1.5 px-2.5 py-1.5 text-[9px] font-bold uppercase tracking-[0.14em] text-text-muted"><Zap className="h-3 w-3 text-primary" /> Open or create</p>
                                    {quickActions.map((action) => {
                                        const Icon = action.icon;
                                        return (
                                            <Link key={action.href} href={action.href} onClick={() => setActionsOpen(false)} className="flex items-center gap-2.5 rounded-xl px-2.5 py-2.5 text-sm text-text-muted transition hover:bg-surface-soft hover:text-text">
                                                <Icon className="h-4 w-4 text-primary" strokeWidth={1.8} /> {action.label}
                                            </Link>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    )}

                    <ThemeToggle />
                    <NotificationBell
                        notifications={orderNotif.notifications}
                        onMarkOne={(orderId) => orderNotif.markSeen('order_detail', orderId)}
                        onMarkAll={() => orderNotif.markSeen('orders_index')}
                        tone="emerald"
                    />
                    <UserDropdown tone="emerald" />
                    <button type="button" onClick={onOpenNavigation} className="hidden h-10 w-10 items-center justify-center rounded-full bg-surface-soft text-text-muted 2xl:flex" aria-label="Open all modules">
                        <Grid2X2 className="h-4 w-4" />
                    </button>
                </div>
            </div>
        </header>
    );
}
