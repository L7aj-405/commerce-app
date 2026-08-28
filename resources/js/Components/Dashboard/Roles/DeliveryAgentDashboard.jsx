import { Link } from '@inertiajs/react';
import { Truck, CheckCircle2, XCircle, Banknote, Undo2 } from 'lucide-react';
import StatsCard from '@/Components/StatsCard';
import Card from '@/Components/Card';
import PointsPreviewCard from '@/Components/Dashboard/Roles/PointsPreviewCard';

export default function DeliveryAgentDashboard({
    returns_to_inspect_count = 0,
    today,
    week,
    points_preview,
}) {
    return (
        <div className="page-enter space-y-6">
            <header className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-content">Your deliveries</h1>
                    <p className="mt-1 text-sm text-content-muted">Today's dispatch activity and outcomes.</p>
                </div>
                <Link href="/dashboard/departments/dispatch" className="btn-secondary">
                    <Truck className="h-4 w-4" /> Open Delivery Board
                </Link>
            </header>

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatsCard label="Assigned today" value={today.assigned_count} icon={Truck} color="primary" />
                <StatsCard label="Delivered" value={today.delivered_count} icon={CheckCircle2} color="green" />
                <StatsCard label="Failed / unreachable" value={today.failed_count + today.unreachable_count} icon={XCircle} color="red" />
                <StatsCard label="COD collected" value={today.cod_collected} icon={Banknote} color="primary" />
            </div>

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <Card title="Delivery success rate" subtitle="Delivered vs. failed/unreachable, today">
                    <div className="text-3xl font-bold tabular-nums text-content">
                        {today.delivery_success_rate === null ? '—' : `${today.delivery_success_rate}%`}
                    </div>
                    {today.delivery_success_rate === null && (
                        <p className="mt-1 text-sm text-content-muted">No delivery outcomes recorded yet today.</p>
                    )}
                </Card>

                <Card title="This week" subtitle="Monday to today">
                    <dl className="space-y-2 text-sm">
                        <Row label="Assigned" value={week.assigned_count} />
                        <Row label="Delivered" value={week.delivered_count} />
                        <Row label="Failed" value={week.failed_count} />
                        <Row label="Unreachable" value={week.unreachable_count} />
                        <Row label="COD collected" value={week.cod_collected} />
                    </dl>
                </Card>
            </div>

            {returns_to_inspect_count > 0 && (
                <Card title="Returns to pick up" subtitle="Flagged returns awaiting inspection, store-wide">
                    <div className="flex items-center gap-2 text-content">
                        <Undo2 className="h-5 w-5 text-content-muted" />
                        <span className="text-2xl font-bold tabular-nums">{returns_to_inspect_count}</span>
                    </div>
                </Card>
            )}

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
