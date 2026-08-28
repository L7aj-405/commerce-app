import { router } from '@inertiajs/react';
import { ClipboardCheck, Clock, Hand, XCircle } from 'lucide-react';
import StatsCard from '@/Components/StatsCard';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import PointsPreviewCard from '@/Components/Dashboard/Roles/PointsPreviewCard';
import { formatDuration } from '@/Support/formatDuration';

export default function ConfirmationAgentDashboard({
    waiting_count = 0,
    claimed_by_me_count = 0,
    today,
    week,
    month,
    points_preview,
}) {
    const claimNext = () => router.post('/dashboard/departments/take-next/confirmation');

    return (
        <div className="page-enter space-y-6">
            <header className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-content">Your confirmation desk</h1>
                    <p className="mt-1 text-sm text-content-muted">Claim orders, confirm or cancel them, and track your own pace.</p>
                </div>
                <button
                    type="button"
                    onClick={claimNext}
                    disabled={waiting_count === 0}
                    className="btn-primary disabled:opacity-50"
                >
                    <Hand className="h-4 w-4" /> Claim next order
                </button>
            </header>

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatsCard label="Orders waiting" value={waiting_count} icon={ClipboardCheck} color="primary" />
                <StatsCard label="Claimed by me" value={claimed_by_me_count} icon={Hand} color="primary" />
                <StatsCard label="Confirmed today" value={today.confirmed_count} icon={ClipboardCheck} color="green" />
                <StatsCard label="Cancelled today" value={today.cancelled_count} icon={XCircle} color="red" />
            </div>

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <Card title="Average handling time" subtitle="Claim to confirm, today">
                    {today.average_confirmation_time_seconds === null ? (
                        <EmptyState icon={Clock} title="No data yet" description="Handling time appears once you've claimed and confirmed at least one order today." />
                    ) : (
                        <div className="flex items-center gap-2 text-3xl font-bold tabular-nums text-content">
                            <Clock className="h-6 w-6 text-content-muted" />
                            {formatDuration(today.average_confirmation_time_seconds)}
                        </div>
                    )}
                </Card>

                <Card title="This week's performance" subtitle="Monday to today">
                    <dl className="space-y-2 text-sm">
                        <Row label="Confirmed" value={week.confirmed_count} />
                        <Row label="Cancelled" value={week.cancelled_count} />
                        <Row label="Unreachable" value={week.unreachable_count} />
                        <Row label="Confirmation rate" value={week.confirmation_rate === null ? '—' : `${week.confirmation_rate}%`} />
                    </dl>
                </Card>

                <Card title="This month" subtitle="Calendar month to date">
                    <dl className="space-y-2 text-sm">
                        <Row label="Confirmed" value={month.confirmed_count} />
                        <Row label="Cancelled" value={month.cancelled_count} />
                        <Row label="Confirmation rate" value={month.confirmation_rate === null ? '—' : `${month.confirmation_rate}%`} />
                    </dl>
                </Card>
            </div>

            <PointsPreviewCard preview={points_preview} />
        </div>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex items-center justify-between">
            <dt className="text-content-muted">{label}</dt>
            <dd className="font-semibold tabular-nums text-content">{value}</dd>
        </div>
    );
}
