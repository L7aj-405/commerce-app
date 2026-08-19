import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, LogOut, Store as StoreIcon } from 'lucide-react';
import ToastNotification from '@/Components/ToastNotification';
import ThemeToggle from '@/Components/ThemeToggle';

export default function PosLayout({
    children,
    store,
    sessionOpen = false,
}) {
    const { auth } = usePage().props;
    const user     = auth?.user;
    const access   = auth?.access ?? {};

    const canSeeDashboard = Boolean(access.canDashboard);

    return (
        <div className="h-dvh overflow-hidden bg-surface text-content font-sans flex flex-col">
            <header className="flex-shrink-0 z-30 h-14 px-4 sm:px-6 bg-surface-2 border-b border-line flex items-center justify-between gap-3">
                <div className="flex items-center gap-2.5 min-w-0">
                    <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center flex-shrink-0">
                        <StoreIcon className="w-4 h-4 text-white" />
                    </div>
                    <div className="min-w-0">
                        <div className="text-xs uppercase tracking-wider text-content-muted leading-none">Store</div>
                        <div className="text-sm font-semibold text-content truncate">{store?.name ?? '—'}</div>
                    </div>
                </div>

                <div className="hidden sm:flex items-center gap-2">
                    <span className="text-xs text-content-muted">
                        Cashier: <span className="text-content">{user?.name}</span>
                    </span>
                    {access.roleName && (
                        <span className="inline-flex px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full bg-green-500/20 text-green-700 dark:text-green-400">
                            {access.roleName}
                        </span>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    <span className={`inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-[11px] font-medium ${
                        sessionOpen
                            ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30'
                            : 'bg-red-500/15 text-red-700 dark:text-red-300 border border-red-500/30'
                    }`}>
                        <span className={`w-1.5 h-1.5 rounded-full ${sessionOpen ? 'bg-emerald-400 animate-pulse' : 'bg-red-400'}`} />
                        Session {sessionOpen ? 'open' : 'closed'}
                    </span>

                    <ThemeToggle />

                    {canSeeDashboard && (
                        <Link
                            href="/dashboard"
                            className="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-lg border border-line text-content-muted hover:bg-surface-3 hover:text-content transition"
                        >
                            <ArrowLeft className="w-3.5 h-3.5" />
                            Dashboard
                        </Link>
                    )}

                    <button
                        type="button"
                        onClick={() => router.post('/pos/logout')}
                        className="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-red-500/10 text-red-700 dark:text-red-300 border border-red-500/30 hover:bg-red-500/20 transition"
                        aria-label="Sign out cashier"
                    >
                        <LogOut className="w-3.5 h-3.5" />
                        <span className="hidden sm:inline">Logout</span>
                    </button>
                </div>
            </header>

            <main className="flex-1 min-h-0 flex flex-col">
                {children}
            </main>

            <ToastNotification />
        </div>
    );
}
