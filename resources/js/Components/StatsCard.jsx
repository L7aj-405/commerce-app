import { ArrowUp, ArrowDown } from 'lucide-react';

// Tinted icon chips — subtle in light, vivid in dark. Text darkens in light for contrast.
// 'primary' (the default) tracks the brand color; the rest stay fixed hues
// for when a card's meaning is genuinely tied to a specific color (e.g. a
// warning/error stat), not the brand.
const ICON_TONES = {
    primary: 'bg-primary-soft text-primary',
    green:  'bg-success-soft text-success',
    yellow: 'bg-warning-soft text-warning',
    amber:  'bg-warning-soft text-warning',
    red:    'bg-danger-soft text-danger',
    blue:   'bg-blue-500/15 text-blue-600 dark:text-blue-400',
    slate:  'bg-slate-500/15 text-slate-600 dark:text-slate-400',
};

export default function StatsCard({ label, value, icon: Icon, trend, color = 'primary', sublabel }) {
    return (
        <div className="bg-surface-2 border border-line rounded-[var(--radius-card)] p-6 shadow-sm dark:shadow-none transition hover:border-content-muted/40">
            <div className="flex items-start justify-between">
                <div className="min-w-0">
                    <p className="text-sm font-medium text-content-muted">{label}</p>
                    <p className="mt-2 text-3xl font-bold text-content tabular-nums tracking-tight">{value}</p>
                    {sublabel && <p className="mt-1 text-xs text-content-muted">{sublabel}</p>}
                    {trend && (
                        <div className={`mt-2 inline-flex items-center gap-1 text-xs font-medium ${
                            trend.direction === 'up' ? 'text-success' : 'text-danger'
                        }`}>
                            {trend.direction === 'up' ? <ArrowUp className="w-3 h-3" /> : <ArrowDown className="w-3 h-3" />}
                            {trend.value}
                        </div>
                    )}
                </div>
                {Icon && (
                    <div className={`flex items-center justify-center w-10 h-10 rounded-[var(--radius-button)] ${ICON_TONES[color] ?? ICON_TONES.primary}`}>
                        <Icon className="w-5 h-5" />
                    </div>
                )}
            </div>
        </div>
    );
}
