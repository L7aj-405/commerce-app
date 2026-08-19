import { useEffect, useRef, useState } from 'react';
import { Bell, CheckCircle2, AlertTriangle, Info } from 'lucide-react';

const ICONS = {
    success: CheckCircle2,
    warning: AlertTriangle,
    info:    Info,
};

export default function NotificationBell({ notifications: initial = [] }) {
    const [open, setOpen]                   = useState(false);
    const [notifications, setNotifications] = useState(initial);
    const ref                               = useRef(null);

    useEffect(() => {
        const onClick = (e) => { if (ref.current && ! ref.current.contains(e.target)) setOpen(false); };
        document.addEventListener('mousedown', onClick);
        return () => document.removeEventListener('mousedown', onClick);
    }, []);

    const unread = notifications.filter((n) => ! n.read).length;

    const markAllRead = () => setNotifications((n) => n.map((x) => ({ ...x, read: true })));
    const markOneRead = (id) => setNotifications((n) => n.map((x) => x.id === id ? { ...x, read: true } : x));

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-label="Notifications"
                className="relative p-2 rounded-lg text-content-muted hover:bg-surface-3 hover:text-content transition"
            >
                <Bell className="w-5 h-5" />
                {unread > 0 && (
                    <span className="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">
                        {unread > 9 ? '9+' : unread}
                    </span>
                )}
            </button>

            {open && (
                <div className="absolute right-0 mt-2 w-80 bg-surface-2 border border-line rounded-xl shadow-2xl z-40 overflow-hidden">
                    <header className="flex items-center justify-between px-4 py-3 border-b border-line">
                        <div className="text-sm font-semibold text-content">Notifications</div>
                        {unread > 0 && (
                            <button
                                type="button"
                                onClick={markAllRead}
                                className="text-[11px] font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:text-indigo-300"
                            >
                                Mark all read
                            </button>
                        )}
                    </header>

                    <div className="max-h-80 overflow-y-auto">
                        {notifications.length === 0 ? (
                            <div className="px-4 py-10 text-center text-sm text-content-muted">
                                You're all caught up.
                            </div>
                        ) : notifications.map((n) => {
                            const Icon = ICONS[n.kind] ?? Info;
                            return (
                                <button
                                    key={n.id}
                                    type="button"
                                    onClick={() => markOneRead(n.id)}
                                    className={`w-full text-left flex items-start gap-3 px-4 py-3 border-b border-line/50 last:border-0 transition hover:bg-surface-3 ${
                                        ! n.read ? 'bg-indigo-500/5' : ''
                                    }`}
                                >
                                    <Icon className={`w-4 h-4 flex-shrink-0 mt-0.5 ${
                                        n.kind === 'success' ? 'text-emerald-600 dark:text-emerald-400' :
                                        n.kind === 'warning' ? 'text-amber-600 dark:text-amber-400' : 'text-blue-400'
                                    }`} />
                                    <div className="min-w-0 flex-1">
                                        <div className="text-sm text-content truncate">{n.title}</div>
                                        {n.body && <div className="text-xs text-content-muted mt-0.5 line-clamp-2">{n.body}</div>}
                                        <div className="text-[10px] text-content-muted/60 mt-1">{n.time}</div>
                                    </div>
                                    {! n.read && <span className="mt-1 w-2 h-2 rounded-full bg-indigo-500 flex-shrink-0" />}
                                </button>
                            );
                        })}
                    </div>

                    <footer className="px-4 py-2 border-t border-line text-center">
                        <button type="button" className="text-xs font-medium text-content-muted hover:text-content">
                            View all
                        </button>
                    </footer>
                </div>
            )}
        </div>
    );
}
