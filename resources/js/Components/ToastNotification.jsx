import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { CheckCircle2, XCircle, AlertTriangle, X } from 'lucide-react';

const TONES = {
    success: { ring: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200', icon: CheckCircle2,  iconClass: 'text-emerald-600 dark:text-emerald-400' },
    error:   { ring: 'border-red-500/30 bg-red-500/10 text-red-200',             icon: XCircle,       iconClass: 'text-red-600 dark:text-red-400'     },
    warning: { ring: 'border-amber-500/30 bg-amber-500/10 text-amber-200',       icon: AlertTriangle, iconClass: 'text-amber-600 dark:text-amber-400'   },
};

const DURATION_MS = 4000;

export default function ToastNotification() {
    const { flash } = usePage().props;
    const [toasts, setToasts] = useState([]);
    const seenRef             = useRef(new Set());

    useEffect(() => {
        const ingest = (kind, message) => {
            if (! message) return;
            const key = `${kind}:${message}`;
            if (seenRef.current.has(key)) return;
            seenRef.current.add(key);
            const id = Math.random().toString(36).slice(2, 9);
            setToasts((prev) => [...prev, { id, kind, message }]);
            setTimeout(() => dismiss(id), DURATION_MS);
        };

        ingest('success', flash?.success);
        ingest('error',   flash?.error);
        ingest('warning', flash?.warning);
    }, [flash]);

    const dismiss = (id) => setToasts((prev) => prev.filter((t) => t.id !== id));

    if (toasts.length === 0) return null;

    return (
        <div className="fixed bottom-4 right-4 z-50 flex flex-col gap-2 max-w-sm w-full pointer-events-none">
            {toasts.map((t) => {
                const tone = TONES[t.kind] ?? TONES.success;
                const Icon = tone.icon;
                return (
                    <div
                        key={t.id}
                        role="status"
                        className={`pointer-events-auto flex items-start gap-3 px-4 py-3 rounded-xl border backdrop-blur-sm shadow-xl ${tone.ring}`}
                    >
                        <Icon className={`w-5 h-5 flex-shrink-0 mt-0.5 ${tone.iconClass}`} />
                        <div className="flex-1 text-sm">{t.message}</div>
                        <button
                            type="button"
                            onClick={() => dismiss(t.id)}
                            aria-label="Dismiss"
                            className="text-content-muted hover:text-content p-0.5"
                        >
                            <X className="w-4 h-4" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
