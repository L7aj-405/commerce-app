import { Link, router, usePage } from '@inertiajs/react';
import { Building2, LayoutDashboard, ShieldAlert, DollarSign, Settings, LogOut, CircleDot } from 'lucide-react';
import ToastNotification from '@/Components/ToastNotification';
import ThemeToggle from '@/Components/ThemeToggle';

const NAV = [
    { label: 'Platform overview', href: '/admin',          icon: LayoutDashboard },
    { label: 'Clients',           href: '/admin/clients',  icon: Building2 },
    { label: 'Revenue',           href: '/admin/revenue',  icon: DollarSign, disabled: true },
    { label: 'Settings',          href: '/admin/settings', icon: Settings,   disabled: true },
];

export default function SuperAdminLayout({ children, pageHeader }) {
    const { url, props } = usePage();
    const user = props.auth?.user;

    return (
        <div className="min-h-screen bg-surface text-content font-sans flex">
            <aside className="hidden lg:flex fixed inset-y-0 left-0 w-64 flex-col border-r border-line bg-surface">
                <header className="p-4 border-b border-line">
                    <div className="flex items-center gap-2">
                        <div className="w-7 h-7 rounded-lg bg-gradient-to-br from-red-500 to-red-700 flex items-center justify-center">
                            <ShieldAlert className="w-4 h-4 text-white" />
                        </div>
                        <div>
                            <div className="font-bold text-content text-sm leading-tight tracking-tight">SaaS Commerce</div>
                            <span className="inline-block mt-0.5 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider rounded bg-red-500/15 border border-red-500/30 text-red-700 dark:text-red-300">
                                Admin panel
                            </span>
                        </div>
                    </div>
                </header>

                <nav className="flex-1 px-3 py-4 space-y-1">
                    {NAV.map((item) => {
                        const Icon   = item.icon;
                        const active = isActive(url, item.href);
                        return (
                            <Link
                                key={item.href}
                                href={item.disabled ? '#' : item.href}
                                className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition ${
                                    item.disabled ? 'text-content-muted/60 cursor-not-allowed' :
                                    active        ? 'bg-red-500/15 text-red-700 dark:text-red-300 border border-red-500/30' :
                                                    'text-content-muted hover:bg-surface-2 hover:text-content border border-transparent'
                                }`}
                            >
                                <Icon className="w-4 h-4" />
                                {item.label}
                                {item.disabled && <span className="ml-auto text-[9px] uppercase tracking-wider text-content-muted/60">soon</span>}
                            </Link>
                        );
                    })}
                </nav>

                <footer className="p-3 border-t border-line">
                    <div className="text-xs text-content-muted truncate">{user?.name}</div>
                    <div className="text-[11px] text-content-muted truncate">{user?.email}</div>
                    <button
                        type="button"
                        onClick={() => router.post('/logout')}
                        className="mt-2 w-full inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-line text-content-muted hover:bg-surface-2 hover:text-content transition"
                    >
                        <LogOut className="w-3.5 h-3.5" /> Sign out
                    </button>
                </footer>
            </aside>

            <div className="lg:ml-64 flex-1 min-h-screen flex flex-col">
                <header className="sticky top-0 z-20 h-16 px-6 border-b border-line bg-surface/80 backdrop-blur flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <CircleDot className="w-4 h-4 text-red-600 dark:text-red-400" />
                        <span className="font-semibold text-content text-sm">Super Admin Panel</span>
                        <span className="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full bg-red-500/15 text-red-700 dark:text-red-300 border border-red-500/30">
                            Restricted
                        </span>
                    </div>
                    <ThemeToggle />
                </header>

                <main className="flex-1 px-4 sm:px-6 lg:px-8 py-6">
                    <div className="mx-auto max-w-7xl">
                        {pageHeader && (
                            <div className="mb-6 pb-5 border-b border-line">
                                <h1 className="text-2xl font-bold text-content tracking-tight">{pageHeader.title}</h1>
                                {pageHeader.subtitle && <p className="mt-1 text-sm text-content-muted">{pageHeader.subtitle}</p>}
                            </div>
                        )}
                        {children}
                    </div>
                </main>
            </div>

            <ToastNotification />
        </div>
    );
}

function isActive(currentUrl, href) {
    const u = (currentUrl ?? '').split('?')[0].replace(/\/+$/, '');
    const h = href.replace(/\/+$/, '');
    if (h === '/admin') return u === '/admin';
    return u === h || u.startsWith(h + '/');
}
