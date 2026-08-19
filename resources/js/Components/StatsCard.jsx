import { ArrowUp, ArrowDown } from 'lucide-react';

// Tinted icon chips — subtle in light, vivid in dark. Text darkens in light for contrast.
const ICON_TONES = {
    indigo: 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400',
    green:  'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    yellow: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    red:    'bg-red-500/15 text-red-600 dark:text-red-400',
    blue:   'bg-blue-500/15 text-blue-600 dark:text-blue-400',
    slate:  'bg-slate-500/15 text-slate-600 dark:text-slate-400',
};

export default function StatsCard({ label, value, icon: Icon, trend, color = 'indigo', sublabel }) {
    return (
        <div className="bg-surface-2 border border-line rounded-xl p-6 shadow-sm dark:shadow-none transition hover:border-content-muted/40">
            <div className="flex items-start justify-between">
                <div className="min-w-0">
                    <p className="text-sm font-medium text-content-muted">{label}</p>
                    <p className="mt-2 text-3xl font-bold text-content tabular-nums tracking-tight">{value}</p>
                    {sublabel && <p className="mt-1 text-xs text-content-muted">{sublabel}</p>}
                    {trend && (
                        <div className={`mt-2 inline-flex items-center gap-1 text-xs font-medium ${
                            trend.direction === 'up' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'
                        }`}>
                            {trend.direction === 'up' ? <ArrowUp className="w-3 h-3" /> : <ArrowDown className="w-3 h-3" />}
                            {trend.value}
                        </div>
                    )}
                </div>
                {Icon && (
                    <div className={`flex items-center justify-center w-10 h-10 rounded-lg ${ICON_TONES[color] ?? ICON_TONES.indigo}`}>
                        <Icon className="w-5 h-5" />
                    </div>
                )}
            </div>
        </div>
    );
}
