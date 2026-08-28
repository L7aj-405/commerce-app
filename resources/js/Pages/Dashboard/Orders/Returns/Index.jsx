import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Undo2, Search, X, ClipboardList, ArrowRight, PackageX } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatusBadge from '@/Components/StatusBadge';
import DepartmentNav from '@/Components/Departments/DepartmentNav';

/* The inspection department's queue. Rows link to the per-line worksheet where
   returned goods are routed to active or damaged stock. */

const REASON_LABELS = {
    refused: 'Refused on delivery',
    damaged_in_transit: 'Damaged in transit',
    wrong_item: 'Wrong item sent',
    customer_remorse: 'Changed their mind',
    other: 'Other',
};

export default function ReturnsIndex({ store, returns = [], filters = {}, departments = [] }) {
    const [search, setSearch] = useState('');
    const showing = filters.status ?? 'open';

    const q = search.trim().toLowerCase();
    const rows = returns.filter((r) => ! q || [r.reference, r.order_reference, r.customer_name]
        .filter(Boolean).some((v) => String(v).toLowerCase().includes(q)));

    const setScope = (status) => router.get('/dashboard/orders/returns', { status },
        { preserveState: true, preserveScroll: true, replace: true });

    return (
        <SaasLayout pageHeader={{
            title: 'Returns Desk',
            subtitle: 'Check returned goods and route them back to stock or write them off',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Orders', href: '/dashboard/orders/manage' },
                { label: 'Returns Desk' },
            ],
        }}>
            <DepartmentNav departments={departments} current="returns" />

            <div className="flex flex-col sm:flex-row sm:items-center gap-3 mb-5">
                <div className="inline-flex items-center gap-1 p-1 rounded-xl bg-surface-2 border border-line">
                    {[{ value: 'open', label: 'Awaiting inspection' }, { value: 'all', label: 'All returns' }].map((t) => (
                        <button
                            key={t.value}
                            onClick={() => setScope(t.value)}
                            className={[
                                'px-3 py-1.5 text-sm font-medium rounded-lg transition',
                                showing === t.value
                                    ? 'bg-primary text-primary-contrast shadow-sm'
                                    : 'text-content-muted hover:text-content hover:bg-surface-3',
                            ].join(' ')}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>

                <div className="relative sm:ml-auto sm:w-72">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-content-muted pointer-events-none" />
                    <input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search return or order reference…"
                        className="w-full pl-9 pr-8 py-2 text-sm rounded-[var(--radius-button)] bg-surface-2 border border-line text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-primary/40 transition"
                    />
                    {search && (
                        <button onClick={() => setSearch('')} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-content-muted hover:text-content">
                            <X className="w-4 h-4" />
                        </button>
                    )}
                </div>
            </div>

            {rows.length === 0 ? (
                <div className="bg-surface-2 border border-line rounded-[var(--radius-card)] py-16 text-center">
                    <PackageX className="w-10 h-10 mx-auto text-content-muted" strokeWidth={1.5} />
                    <h3 className="mt-3 text-sm font-semibold text-content">Nothing to inspect</h3>
                    <p className="mt-1 text-sm text-content-muted">
                        Returns appear here when an order is flagged as returned from the board.
                    </p>
                </div>
            ) : (
                <div className="bg-surface-2 border border-line rounded-[var(--radius-card)] overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-surface-2/60 text-xs uppercase tracking-wider text-content-muted border-b border-line">
                                <tr>
                                    <th className="px-4 py-3 text-left">Return</th>
                                    <th className="px-4 py-3 text-left">Order</th>
                                    <th className="px-4 py-3 text-left">Customer</th>
                                    <th className="px-4 py-3 text-left">Reason</th>
                                    <th className="px-4 py-3 text-left">Progress</th>
                                    <th className="px-4 py-3 text-left">Status</th>
                                    <th className="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line/50">
                                {rows.map((r) => (
                                    <tr key={r.id} className="hover:bg-surface-3/40 transition">
                                        <td className="px-4 py-3 font-mono text-xs text-content">{r.reference}</td>
                                        <td className="px-4 py-3 font-mono text-xs text-content-muted">{r.order_reference ?? '—'}</td>
                                        <td className="px-4 py-3 text-content">
                                            {r.customer_name || <span className="text-content-muted">Walk-in</span>}
                                        </td>
                                        <td className="px-4 py-3 text-content-muted">{REASON_LABELS[r.reason] ?? r.reason}</td>
                                        <td className="px-4 py-3">
                                            <span className="inline-flex items-center gap-1.5 text-xs text-content-muted">
                                                <ClipboardList className="w-3.5 h-3.5" />
                                                {r.line_count - r.pending_count}/{r.line_count} inspected
                                            </span>
                                        </td>
                                        <td className="px-4 py-3"><StatusBadge type="return" status={r.status} /></td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={`/dashboard/orders/returns/${r.id}`}
                                                className="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong transition"
                                            >
                                                <Undo2 className="w-3.5 h-3.5" />
                                                {r.pending_count > 0 ? 'Inspect' : 'Review'}
                                                <ArrowRight className="w-3.5 h-3.5" />
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </SaasLayout>
    );
}
