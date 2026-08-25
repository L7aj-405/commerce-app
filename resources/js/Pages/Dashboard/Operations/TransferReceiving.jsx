import { useState, useMemo } from 'react';
import { router } from '@inertiajs/react';
import { ArrowLeftRight, Loader2, PackageCheck } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import OperationsNav from '@/Components/Departments/OperationsNav';
import { EmptyQueue } from '@/Components/Departments/QueueParts';

/** Inbound InventoryTransfer rows awaiting receipt at a warehouse this org runs. */
export default function TransferReceiving({ transfers = [] }) {
    const [warehouse, setWarehouse] = useState('');
    const [busyId, setBusyId]       = useState(null);

    const warehouses = useMemo(
        () => Array.from(new Set(transfers.map((t) => t.destination_warehouse).filter(Boolean))).sort(),
        [transfers],
    );

    const rows = useMemo(
        () => transfers.filter((t) => ! warehouse || t.destination_warehouse === warehouse),
        [transfers, warehouse],
    );

    const receive = (transfer) => {
        setBusyId(transfer.id);
        router.post(`/dashboard/operations/transfers/${transfer.id}/receive`, {}, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setBusyId(null),
        });
    };

    return (
        <SaasLayout pageHeader={{
            title: 'Transfer Receiving',
            subtitle: 'Receive internal transfers and release waiting orders.',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Supervisor Queues' }, { label: 'Transfer Receiving' }],
        }}>
            <OperationsNav current="transfers" />

            {warehouses.length > 1 && (
                <div className="flex items-center gap-2 mb-4">
                    <select
                        value={warehouse}
                        onChange={(e) => setWarehouse(e.target.value)}
                        className="px-3 py-2 text-sm rounded-lg bg-surface-2 border border-line text-content focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                    >
                        <option value="">All warehouses</option>
                        {warehouses.map((w) => <option key={w} value={w}>{w}</option>)}
                    </select>
                </div>
            )}

            {rows.length === 0 ? (
                <EmptyQueue
                    title="Nothing in transit"
                    hint="Transfers appear here once they've shipped from the source warehouse."
                />
            ) : (
                <div className="overflow-x-auto rounded-xl border border-line bg-surface">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left border-b border-line bg-surface-2 text-content-muted">
                                <th className="px-3 py-2.5 font-medium whitespace-nowrap">Reference</th>
                                <th className="px-3 py-2.5 font-medium whitespace-nowrap">From</th>
                                <th className="px-3 py-2.5 font-medium whitespace-nowrap">To</th>
                                <th className="px-3 py-2.5 font-medium whitespace-nowrap">Items</th>
                                <th className="px-3 py-2.5 font-medium whitespace-nowrap">Reason</th>
                                <th className="px-3 py-2.5 font-medium whitespace-nowrap text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {rows.map((t) => (
                                <tr key={t.id} className="align-top hover:bg-surface-2/50 transition">
                                    <td className="px-3 py-2.5 font-mono text-xs text-content-muted">
                                        <div className="flex items-center gap-1.5">
                                            <ArrowLeftRight className="w-3.5 h-3.5 text-content-muted" />
                                            {t.reference}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2.5 text-content">{t.source_warehouse || '—'}</td>
                                    <td className="px-3 py-2.5 text-content">{t.destination_warehouse || '—'}</td>
                                    <td className="px-3 py-2.5 text-content-muted">
                                        {t.items.map((i) => `${i.quantity}× ${i.name ?? i.sku ?? 'item'}`).join(', ') || '—'}
                                    </td>
                                    <td className="px-3 py-2.5 text-content-muted capitalize">{(t.reason ?? '').replaceAll('_', ' ') || '—'}</td>
                                    <td className="px-3 py-2.5 text-right">
                                        <button
                                            disabled={busyId === t.id}
                                            onClick={() => receive(t)}
                                            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-40 transition"
                                        >
                                            {busyId === t.id ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <PackageCheck className="w-3.5 h-3.5" />}
                                            Receive
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </SaasLayout>
    );
}
