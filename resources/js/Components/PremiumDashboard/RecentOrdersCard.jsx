import { Link } from '@inertiajs/react';
import { ArrowUpRight, Globe2, Monitor, ShoppingBag } from 'lucide-react';
import SoftCard from './SoftCard';
import StatusPill from './StatusPill';
import EmptyMetricState from './EmptyMetricState';

export default function RecentOrdersCard({ orders = [], currency = 'MAD' }) {
    return (
        <SoftCard className="overflow-hidden">
            <header className="flex items-center justify-between gap-4 px-6 py-5">
                <div>
                    <h2 className="text-sm font-semibold text-[#252925]">Recent orders</h2>
                    <p className="mt-0.5 text-xs text-[#92978f]">Latest activity across POS and connected stores</p>
                </div>
                <Link href="/dashboard/orders" className="flex h-9 w-9 items-center justify-center rounded-full bg-[#f3f5f1] text-[#70766f] transition hover:bg-[#e8f5ed] hover:text-[#118858]" aria-label="View all orders">
                    <ArrowUpRight className="h-4 w-4" />
                </Link>
            </header>

            {orders.length === 0 ? (
                <EmptyMetricState icon={ShoppingBag} title="No orders yet" description="POS and online orders will appear here when sales begin." />
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[720px] text-left">
                        <thead>
                            <tr className="border-y border-[#eef0eb] text-[10px] font-semibold uppercase tracking-[0.11em] text-[#9aa098]">
                                <th className="px-6 py-3">Source</th>
                                <th className="px-4 py-3">Customer</th>
                                <th className="px-4 py-3">Date / time</th>
                                <th className="px-4 py-3">Status</th>
                                <th className="px-4 py-3 text-right">Amount</th>
                                <th className="px-6 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.map((order) => (
                                <tr key={`${order.origin}:${order.id}`} className="border-b border-[#f0f1ed] last:border-0 transition-colors hover:bg-[#fafbf9]">
                                    <td className="px-6 py-4">
                                        <div className="flex items-center gap-2.5">
                                            <span className="flex h-8 w-8 items-center justify-center rounded-full bg-[#eff3ef] text-[#5f665f]">
                                                {order.origin === 'online' ? <Globe2 className="h-4 w-4" /> : <Monitor className="h-4 w-4" />}
                                            </span>
                                            <div>
                                                <p className="text-xs font-semibold text-[#303530]">{order.origin_label ?? order.origin}</p>
                                                <p className="mt-0.5 font-mono text-[10px] text-[#9aa098]">{order.reference}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-4 py-4 text-sm text-[#4f554f]">{order.customer_name || 'Walk-in customer'}</td>
                                    <td className="px-4 py-4 text-xs text-[#858b84]">{formatDate(order.created_at)}</td>
                                    <td className="px-4 py-4"><StatusPill status={order.status} label={order.status_label} /></td>
                                    <td className="px-4 py-4 text-right text-sm font-semibold tabular-nums text-[#2d322d]">{formatMoney(order.total, currency)}</td>
                                    <td className="px-6 py-4 text-right">
                                        <Link href={order.view_url} className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-[#f3f5f1] text-[#747a73] transition hover:bg-[#118858] hover:text-white" aria-label={`Open ${order.reference}`}>
                                            <ArrowUpRight className="h-3.5 w-3.5" />
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </SoftCard>
    );
}

function formatMoney(value, currency) {
    return `${currency} ${(Number(value) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(value) {
    if (! value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
