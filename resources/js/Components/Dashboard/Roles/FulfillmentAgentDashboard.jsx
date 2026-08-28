import { PackageCheck, PackageSearch, Truck, AlertTriangle } from 'lucide-react';
import StatsCard from '@/Components/StatsCard';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import PointsPreviewCard from '@/Components/Dashboard/Roles/PointsPreviewCard';
import { formatDuration } from '@/Support/formatDuration';

export default function FulfillmentAgentDashboard({
    assigned_to_me_count = 0,
    waiting_stock_count = 0,
    ready_for_dispatch_count = 0,
    today,
    week,
    points_preview,
}) {
    return (
        <div className="page-enter space-y-6">
            <header>
                <h1 className="text-2xl font-bold tracking-tight text-content">Your pick queue</h1>
                <p className="mt-1 text-sm text-content-muted">Orders assigned to you, and what you've moved through today.</p>
            </header>

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatsCard label="Assigned to me" value={assigned_to_me_count} icon={PackageSearch} color="primary" />
                <StatsCard label="Units picked today" value={today.picked_units_count} icon={PackageSearch} color="primary" />
                <StatsCard label="Orders packed today" value={today.packed_orders_count} icon={PackageCheck} color="green" />
                <StatsCard label="Ready for dispatch" value={ready_for_dispatch_count} icon={Truck} color="primary" sublabel="Store-wide" />
            </div>

            {waiting_stock_count > 0 && (
                <Card title="Waiting stock blockers" subtitle="Orders confirmed but blocked on stock, store-wide">
                    <div className="flex items-center gap-2 text-warning">
                        <AlertTriangle className="h-5 w-5" />
                        <span className="text-2xl font-bold tabular-nums">{waiting_stock_count}</span>
                        <span className="text-sm text-content-muted">order{waiting_stock_count === 1 ? '' : 's'} waiting on stock</span>
                    </div>
                </Card>
            )}

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <Card title="Average pack time" subtitle="Picked to packed, today">
                    {today.average_pack_time_seconds === null ? (
                        <EmptyState icon={PackageCheck} title="No data yet" description="Pack time appears once an order has been both picked and packed today." />
                    ) : (
                        <div className="text-3xl font-bold tabular-nums text-content">{formatDuration(today.average_pack_time_seconds)}</div>
                    )}
                </Card>

                <Card title="This week" subtitle="Monday to today">
                    <dl className="space-y-2 text-sm">
                        <Row label="Orders picked" value={week.picked_orders_count} />
                        <Row label="Units picked" value={week.picked_units_count} />
                        <Row label="Orders packed" value={week.packed_orders_count} />
                        <Row label="Units packed" value={week.packed_units_count} />
                        {week.error_count > 0 && <Row label="Errors reported" value={week.error_count} tone="danger" />}
                    </dl>
                </Card>
            </div>

            <PointsPreviewCard preview={points_preview} />
        </div>
    );
}

function Row({ label, value, tone }) {
    return (
        <div className="flex items-center justify-between">
            <dt className="text-content-muted">{label}</dt>
            <dd className={`font-semibold tabular-nums ${tone === 'danger' ? 'text-danger' : 'text-content'}`}>{value}</dd>
        </div>
    );
}
