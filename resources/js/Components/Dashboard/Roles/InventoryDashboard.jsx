import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeftRight, Boxes, ClipboardList, Warehouse } from 'lucide-react';
import StatsCard from '@/Components/StatsCard';

export default function InventoryDashboard({
    low_stock_count = 0,
    waiting_stock_count = 0,
    pending_transfers_count = 0,
    adjustments_today = 0,
    transfers_received_today = 0,
}) {
    return (
        <div className="page-enter space-y-6">
            <header className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-content">Inventory overview</h1>
                    <p className="mt-1 text-sm text-content-muted">Stock health and today's movement, store-wide.</p>
                </div>
                <div className="flex gap-2">
                    <Link href="/dashboard/stock" className="btn-secondary"><Boxes className="h-4 w-4" /> Stock</Link>
                    <Link href="/dashboard/warehouses" className="btn-secondary"><Warehouse className="h-4 w-4" /> Warehouses</Link>
                </div>
            </header>

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatsCard label="Low stock items" value={low_stock_count} icon={AlertTriangle} color={low_stock_count > 0 ? 'red' : 'green'} />
                <StatsCard label="Waiting for stock" value={waiting_stock_count} icon={ClipboardList} color="primary" />
                <StatsCard label="Pending transfers" value={pending_transfers_count} icon={ArrowLeftRight} color="primary" />
                <StatsCard label="Adjustments today" value={adjustments_today} icon={Boxes} color="primary" sublabel={`${transfers_received_today} transfer(s) received today`} />
            </div>
        </div>
    );
}
