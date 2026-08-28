import { Sparkles } from 'lucide-react';
import Card from '@/Components/Card';

/**
 * "Performance points preview" — foundation only, per the brief. Never
 * labeled as a bonus/payout amount, and only rendered when the backend
 * actually sent a preview payload (never fabricated client-side).
 */
export default function PointsPreviewCard({ preview }) {
    if (! preview) return null;

    return (
        <Card
            title="Performance points preview"
            subtitle="Preview only — not connected to payroll or bonuses"
            badges={<span className="inline-flex items-center gap-1 rounded-full bg-primary-soft px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-primary"><Sparkles className="h-3 w-3" /> Preview</span>}
        >
            <div className="flex items-baseline gap-2">
                <span className="text-3xl font-bold tabular-nums text-content">{preview.total_points}</span>
                <span className="text-sm text-content-muted">points today</span>
            </div>

            {preview.breakdown.length === 0 ? (
                <p className="mt-3 text-sm text-content-muted">No scored activity yet today.</p>
            ) : (
                <ul className="mt-3 space-y-1.5">
                    {preview.breakdown.map((row) => (
                        <li key={row.event_type} className="flex items-center justify-between text-sm">
                            <span className="text-content-muted">{row.label} <span className="text-xs">×{row.count}</span></span>
                            <span className={`font-semibold tabular-nums ${row.points >= 0 ? 'text-success' : 'text-danger'}`}>
                                {row.points >= 0 ? '+' : ''}{row.points}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
