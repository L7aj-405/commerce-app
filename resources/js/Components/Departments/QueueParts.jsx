import { useState, useEffect } from 'react';
import { Monitor, Globe, Truck, Search, X, AlertTriangle, UserPlus, Inbox } from 'lucide-react';

/**
 * Small pieces shared by every department dashboard, so the four pages differ
 * only where the work actually differs.
 */

/* ------------------------------------------------------------------ */
/* Stat tiles                                                          */
/* ------------------------------------------------------------------ */

const TONES = {
    amber:   'text-warning',
    blue:    'text-blue-600 dark:text-blue-400',
    emerald: 'text-success',
    red:     'text-danger',
    indigo:  'text-indigo-600 dark:text-indigo-400',
    slate:   'text-content',
};

export function StatTiles({ tiles }) {
    return (
        <section className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
            {tiles.map((t) => {
                const Icon = t.icon;
                return (
                    <div key={t.label} className="bg-surface-2 border border-line rounded-[var(--radius-card)] px-4 py-3.5">
                        <div className="flex items-center gap-1.5 text-xs text-content-muted">
                            {Icon && <Icon className="w-3.5 h-3.5" />}
                            {t.label}
                        </div>
                        <div className={`mt-1 text-2xl font-bold tabular-nums ${TONES[t.tone] ?? TONES.slate}`}>
                            {t.value}
                        </div>
                        {t.hint && <div className="mt-0.5 text-[11px] text-content-muted">{t.hint}</div>}
                    </div>
                );
            })}
        </section>
    );
}

/* ------------------------------------------------------------------ */
/* Source badge — POS vs Online at a glance                            */
/* ------------------------------------------------------------------ */

const SOURCE_STYLE = {
    pos:      { icon: Monitor, label: 'POS',      cls: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300' },
    online:   { icon: Globe,   label: 'Online',   cls: 'bg-blue-500/15 text-blue-700 dark:text-blue-300' },
    delivery: { icon: Truck,   label: 'Delivery', cls: 'bg-amber-500/15 text-amber-700 dark:text-amber-300' },
};

export function SourceBadge({ source, label }) {
    const s = SOURCE_STYLE[source] ?? SOURCE_STYLE.pos;
    const Icon = s.icon;

    return (
        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold ${s.cls}`}>
            <Icon className="w-3 h-3" />
            {label ?? s.label}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/* Queue toolbar — scope pills + search                                */
/* ------------------------------------------------------------------ */

export function QueueToolbar({ scope, onScope, counts, search, onSearch, placeholder, extra }) {
    const pills = [
        { value: 'all',        label: 'All',        count: counts.all },
        { value: 'unassigned', label: 'Unassigned', count: counts.unassigned },
        { value: 'mine',       label: 'Mine',       count: counts.mine },
    ];

    return (
        <div className="flex flex-col sm:flex-row sm:items-center gap-3 mb-4">
            <div className="flex items-center gap-1 p-1 rounded-[var(--radius-card)] bg-surface-2 border border-line">
                {pills.map((p) => (
                    <button
                        key={p.value}
                        onClick={() => onScope(p.value)}
                        className={[
                            'inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-[var(--radius-button)] transition',
                            scope === p.value
                                ? 'bg-primary text-primary-contrast shadow-sm'
                                : 'text-content-muted hover:text-content hover:bg-surface-3',
                        ].join(' ')}
                    >
                        {p.label}
                        <span className={[
                            'min-w-5 px-1.5 rounded-full text-[11px] tabular-nums',
                            scope === p.value ? 'bg-white/20' : 'bg-surface border border-line',
                        ].join(' ')}>
                            {p.count}
                        </span>
                    </button>
                ))}
            </div>

            {extra}

            <div className="relative sm:ml-auto sm:w-64">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-content-muted pointer-events-none" />
                <input
                    value={search}
                    onChange={(e) => onSearch(e.target.value)}
                    placeholder={placeholder ?? 'Search reference, customer…'}
                    className="w-full pl-9 pr-8 py-2 text-sm rounded-[var(--radius-button)] bg-surface-2 border border-line text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-primary/40 focus:border-primary/50 transition"
                />
                {search && (
                    <button
                        onClick={() => onSearch('')}
                        aria-label="Clear search"
                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-content-muted hover:text-content"
                    >
                        <X className="w-4 h-4" />
                    </button>
                )}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Agent workload — active agents + queue distribution                 */
/* ------------------------------------------------------------------ */

export function AgentWorkload({ agents = [], onTakeNext, taking, available, title = 'Team' }) {
    const busiest = Math.max(1, ...agents.map((a) => a.assigned));

    return (
        <aside className="bg-surface-2 border border-line rounded-[var(--radius-card)] p-4">
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-sm font-semibold text-content">{title}</h2>
                <span className="text-[11px] text-content-muted">{agents.length} active</span>
            </div>

            {agents.length === 0 ? (
                <p className="mt-3 text-xs text-content-muted">Nobody is assigned to this department yet.</p>
            ) : (
                <ul className="mt-3 space-y-2.5">
                    {agents.map((a) => (
                        <li key={a.id} className="flex items-center gap-2.5">
                            <span
                                className={[
                                    'shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-full text-[11px] font-bold',
                                    a.is_you
                                        ? 'bg-primary text-primary-contrast'
                                        : 'bg-surface border border-line text-content-muted',
                                ].join(' ')}
                                title={a.name}
                            >
                                {a.initials}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-xs font-medium text-content truncate">
                                        {a.name}{a.is_you && <span className="text-content-muted"> · you</span>}
                                    </span>
                                    <span className="text-xs tabular-nums text-content-muted">{a.assigned}</span>
                                </div>
                                {/* Relative load, so distribution reads at a glance. */}
                                <div className="mt-1 h-1.5 rounded-full bg-surface overflow-hidden">
                                    <div
                                        className={`h-full rounded-full ${a.is_you ? 'bg-primary' : 'bg-content-muted/40'}`}
                                        style={{ width: `${Math.round((a.assigned / busiest) * 100)}%` }}
                                    />
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {onTakeNext && (
                <button
                    onClick={onTakeNext}
                    disabled={taking || available === 0}
                    className="mt-4 w-full inline-flex items-center justify-center gap-1.5 px-3 py-2.5 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-40 disabled:cursor-not-allowed transition"
                >
                    <UserPlus className="w-4 h-4" />
                    {available === 0 ? 'Queue is empty' : 'Take next order'}
                </button>
            )}
        </aside>
    );
}

/* ------------------------------------------------------------------ */
/* Empty queue                                                         */
/* ------------------------------------------------------------------ */

export function EmptyQueue({ title, hint }) {
    return (
        <div className="bg-surface-2 border border-line rounded-[var(--radius-card)] py-16 text-center">
            <Inbox className="w-10 h-10 mx-auto text-content-muted" strokeWidth={1.5} />
            <h3 className="mt-3 text-sm font-semibold text-content">{title}</h3>
            {hint && <p className="mt-1 text-sm text-content-muted">{hint}</p>}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Reason dialog — for actions that destroy or divert value            */
/* ------------------------------------------------------------------ */

export function ReasonDialog({ open, title, description, confirmLabel, presets, onCancel, onConfirm }) {
    const [preset, setPreset] = useState(presets?.[0]?.value ?? '');
    const [text, setText]     = useState('');

    useEffect(() => {
        if (open) { setPreset(presets?.[0]?.value ?? ''); setText(''); }
    }, [open, presets]);

    useEffect(() => {
        if (! open) return;
        const onKey = (e) => e.key === 'Escape' && onCancel();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onCancel]);

    if (! open) return null;

    const usingPreset = presets && preset !== 'other';
    const value = usingPreset ? preset : text.trim();

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div onClick={onCancel} className="absolute inset-0 bg-black/50 backdrop-blur-sm" />
            <div role="dialog" aria-modal="true" className="relative w-full max-w-md bg-surface border border-line rounded-[var(--radius-card)] shadow-2xl p-5">
                <div className="flex items-start gap-3">
                    <span className="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-[var(--radius-button)] bg-danger-soft text-danger">
                        <AlertTriangle className="w-5 h-5" />
                    </span>
                    <div className="min-w-0">
                        <h3 className="text-base font-semibold text-content">{title}</h3>
                        {description && <p className="mt-0.5 text-sm text-content-muted">{description}</p>}
                    </div>
                </div>

                <div className="mt-4 space-y-3">
                    {presets && (
                        <select
                            value={preset}
                            onChange={(e) => setPreset(e.target.value)}
                            className="w-full px-3 py-2 text-sm rounded-[var(--radius-button)] bg-surface-2 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary/40"
                        >
                            {presets.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
                        </select>
                    )}

                    {(! presets || preset === 'other') && (
                        <textarea
                            autoFocus
                            rows={3}
                            value={text}
                            onChange={(e) => setText(e.target.value)}
                            placeholder="Recorded on the order's audit trail…"
                            className="w-full px-3 py-2 text-sm rounded-[var(--radius-button)] bg-surface-2 border border-line text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-primary/40"
                        />
                    )}
                </div>

                <div className="mt-5 flex justify-end gap-2">
                    <button onClick={onCancel} className="px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted hover:text-content transition">
                        Never mind
                    </button>
                    <button
                        disabled={! value}
                        onClick={() => onConfirm(value)}
                        className="px-3 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-danger text-white hover:brightness-90 disabled:opacity-40 disabled:cursor-not-allowed transition"
                    >
                        {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Formatting                                                          */
/* ------------------------------------------------------------------ */

export function fmtMoney(value, currency) {
    const n = Number(value) || 0;
    return `${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

export function timeAgo(iso) {
    if (! iso) return '—';
    const mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.floor(hrs / 24)}d ago`;
}

/** Waiting-time tone: green under an hour, amber under four, red beyond. */
export function ageTone(iso) {
    if (! iso) return 'text-content-muted';
    const hrs = (Date.now() - new Date(iso).getTime()) / 3600000;
    if (hrs < 1) return 'text-emerald-600 dark:text-emerald-400';
    if (hrs < 4) return 'text-amber-600 dark:text-amber-400';
    return 'text-red-600 dark:text-red-400';
}
