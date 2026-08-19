import { router } from '@inertiajs/react';
import { Truck, LogOut, Package } from 'lucide-react';
import ThemeToggle from '@/Components/ThemeToggle';
import ToastNotification from '@/Components/ToastNotification';

/**
 * Standalone shell for the delivery agent — deliberately NOT the manager
 * SaasLayout. No sidebar, no metrics, no breadcrumbs: a driver on a phone gets
 * a slim sticky header (who/where they are, theme, sign out) and a single
 * column of content. Keeps only what a manager layout also gives that a driver
 * still needs — the theme toggle and flash toasts.
 */
export default function DeliveryAgentLayout({ store, agent, pending = null, children }) {
    return (
        <div className="min-h-screen bg-surface-2 text-content font-sans transition-colors">
            {/* Sticky driver header */}
            <header className="sticky top-0 z-30 bg-surface/90 backdrop-blur border-b border-line">
                <div className="mx-auto max-w-xl px-4 h-14 flex items-center gap-3">
                    <span className="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-600 text-white shrink-0">
                        <Truck className="w-5 h-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="text-sm font-semibold text-content leading-tight truncate">
                            {agent?.name ?? 'Delivery agent'}
                        </div>
                        <div className="text-[11px] text-content-muted truncate">{store?.name ?? ''}</div>
                    </div>

                    {/* Stats pill — e.g. "3 left" */}
                    {pending !== null && (
                        <span className={[
                            'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold tabular-nums shrink-0',
                            pending > 0
                                ? 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300'
                                : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
                        ].join(' ')}>
                            <Package className="w-3.5 h-3.5" />
                            {pending > 0 ? `${pending} left` : 'Done'}
                        </span>
                    )}

                    <ThemeToggle />

                    <button
                        onClick={() => router.post('/logout')}
                        aria-label="Sign out"
                        title="Sign out"
                        className="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-line bg-surface-2 text-content-muted hover:text-content hover:bg-surface-3 transition"
                    >
                        <LogOut className="w-4 h-4" />
                    </button>
                </div>
            </header>

            <main className="mx-auto max-w-xl px-4 py-4 pb-16">
                {children}
            </main>

            <ToastNotification />
        </div>
    );
}
