import { Clock } from 'lucide-react';
import { SourceBadge, fmtMoney, timeAgo, ageTone } from '@/Components/Departments/QueueParts';
import StatusBadge from '@/Components/StatusBadge';

/**
 * Shared table body for the four order-based operations queues
 * (waiting-stock, picking, packing, ready-for-delivery). Only the row actions
 * and the empty-state copy differ between pages, so those come in as props
 * instead of four near-identical table implementations.
 */
export default function OperationsTable({ rows, currency, showClientColumn, renderActions }) {
    if (rows.length === 0) return null;

    return (
        <div className="overflow-x-auto rounded-[var(--radius-card)] border border-line bg-surface">
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-left border-b border-line bg-surface-2 text-content-muted">
                        <th className="px-3 py-2.5 font-medium whitespace-nowrap">Order</th>
                        <th className="px-3 py-2.5 font-medium whitespace-nowrap">Customer</th>
                        <th className="px-3 py-2.5 font-medium whitespace-nowrap">Phone</th>
                        <th className="px-3 py-2.5 font-medium whitespace-nowrap">City</th>
                        <th className="px-3 py-2.5 font-medium whitespace-nowrap">Warehouse</th>
                        {showClientColumn && <th className="px-3 py-2.5 font-medium whitespace-nowrap">Client</th>}
                        <th className="px-3 py-2.5 font-medium whitespace-nowrap">Status</th>
                        <th className="px-3 py-2.5 font-medium whitespace-nowrap">Assigned</th>
                        <th className="px-3 py-2.5 font-medium whitespace-nowrap text-right">Actions</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">
                    {rows.map((o) => (
                        <tr key={`${o.type}:${o.id}`} className="align-top hover:bg-surface-2/50 transition">
                            <td className="px-3 py-2.5">
                                <div className="flex items-center gap-1.5">
                                    <SourceBadge source={o.source} label={o.source_label} />
                                    <span className="font-mono text-xs text-content-muted">{o.reference}</span>
                                </div>
                                <span className={`mt-1 inline-flex items-center gap-1 text-[11px] ${ageTone(o.created_at)}`}>
                                    <Clock className="w-3 h-3" /> {timeAgo(o.created_at)}
                                </span>
                                {o.source === 'online' && o.connection_label && (
                                    <div className="mt-0.5 text-[11px] text-content-muted truncate max-w-[160px]">
                                        {o.connection_label}
                                        {o.external_order_number ? ` · #${o.external_order_number}` : ''}
                                    </div>
                                )}
                            </td>
                            <td className="px-3 py-2.5">
                                <div className="font-medium text-content">{o.customer_name || 'Walk-in customer'}</div>
                                <div className="text-xs text-content-muted">{fmtMoney(o.total, currency)}</div>
                            </td>
                            <td className="px-3 py-2.5 text-content-muted">{o.customer_phone || '—'}</td>
                            <td className="px-3 py-2.5 text-content-muted">{o.shipping_city_name || '—'}</td>
                            <td className="px-3 py-2.5 text-content-muted">{o.allocation?.warehouse_name || '—'}</td>
                            {showClientColumn && (
                                <td className="px-3 py-2.5 text-content-muted">{o.client_organization_name || '—'}</td>
                            )}
                            <td className="px-3 py-2.5">
                                <StatusBadge status={o.status} type="fulfillment" label={o.status_label} />
                                {o.allocation?.shortage_quantity > 0 && (
                                    <div className="mt-1 text-[11px] text-warning">
                                        waiting {o.allocation.shortage_quantity} unit(s)
                                    </div>
                                )}
                            </td>
                            <td className="px-3 py-2.5 text-content-muted">{o.assignee_name || '—'}</td>
                            <td className="px-3 py-2.5 text-right whitespace-nowrap">{renderActions(o)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
