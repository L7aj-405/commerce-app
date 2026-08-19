import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Printer, CreditCard, User, Calendar, Hash, FileText, FilePlus, Loader2 } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatusBadge from '@/Components/StatusBadge';

export default function Show({ order, store, invoice = null, canInvoice = false }) {
    const currency = store?.currency ?? 'MAD';
    const [generating, setGenerating] = useState(false);

    const generateInvoice = () => {
        if (! confirm('Generate a finalized invoice for this order? It will be locked once issued.')) return;
        setGenerating(true);
        router.post('/dashboard/invoices',
            { source_type: 'pos_order', source_id: order.id },
            { preserveScroll: true, onFinish: () => setGenerating(false) },
        );
    };

    return (
        <SaasLayout pageHeader={{
            title: `Order ${order.receipt_number}`,
            subtitle: order.customer_name || 'Walk-in customer',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Orders',    href: '/dashboard/orders' },
                { label: order.receipt_number },
            ],
            actions: (
                <div className="flex items-center gap-2">
                    <Link
                        href="/dashboard/orders"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content"
                    >
                        <ArrowLeft className="w-4 h-4" /> Back
                    </Link>
                    {invoice ? (
                        <Link
                            href={`/dashboard/factures/${invoice.id}`}
                            className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-surface-2 border border-line text-indigo-700 dark:text-indigo-300 hover:bg-surface-3"
                        >
                            <FileText className="w-4 h-4" /> View invoice
                        </Link>
                    ) : canInvoice ? (
                        <button
                            type="button"
                            onClick={generateInvoice}
                            disabled={generating}
                            className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-50"
                        >
                            {generating ? <Loader2 className="w-4 h-4 animate-spin" /> : <FilePlus className="w-4 h-4" />} Generate invoice
                        </button>
                    ) : null}
                    <a
                        href={`/dashboard/orders/${order.receipt_number}/receipt`}
                        target="_blank"
                        rel="noopener"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500"
                    >
                        <Printer className="w-4 h-4" /> Print receipt
                    </a>
                </div>
            ),
        }}>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 space-y-6">
                    <Card>
                        <CardHeader title="Items" />
                        {(order.items ?? []).length === 0 ? (
                            <div className="px-5 py-8 text-center text-content-muted text-sm">No items.</div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-surface-2/60 text-xs uppercase tracking-wider text-content-muted border-b border-line">
                                        <tr>
                                            <th className="px-4 py-2.5 text-left">Product</th>
                                            <th className="px-4 py-2.5 text-right">Qty</th>
                                            <th className="px-4 py-2.5 text-right">Unit</th>
                                            <th className="px-4 py-2.5 text-right">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-line/50">
                                        {order.items.map((it) => (
                                            <tr key={it.id} className="text-content-muted">
                                                <td className="px-4 py-3">
                                                    <div className="font-medium text-content">{it.product_name}</div>
                                                    <div className="text-xs font-mono text-content-muted">{it.product_sku}</div>
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">{it.quantity}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{fmtMoney(it.unit_price, currency)}</td>
                                                <td className="px-4 py-3 text-right tabular-nums font-semibold text-content">{fmtMoney(it.line_total, currency)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>

                    {order.notes && (
                        <Card>
                            <CardHeader title="Notes" />
                            <p className="px-5 py-4 text-sm text-content-muted whitespace-pre-wrap">{order.notes}</p>
                        </Card>
                    )}
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader title="Order summary" right={<StatusBadge type="order" status={order.status} />} />
                        <dl className="px-5 py-4 space-y-2 text-sm">
                            <Row label="Subtotal"        value={fmtMoney(order.subtotal, currency)} />
                            <Row label="Discount"        value={`−${fmtMoney(order.discount_amount, currency)}`} tone="red" />
                            <Row label="Tax"             value={fmtMoney(order.tax_amount, currency)} />
                            <div className="pt-2 mt-2 border-t border-line" />
                            <Row label="Total" value={fmtMoney(order.total_amount, currency)} large />
                        </dl>
                    </Card>

                    <Card>
                        <CardHeader title="Payment" />
                        <dl className="px-5 py-4 space-y-2 text-sm">
                            <Row label="Method"  value={<span className="uppercase text-xs tracking-wider">{order.payment_method}</span>} icon={CreditCard} />
                            <Row label="Paid"    value={fmtMoney(order.amount_paid, currency)} tone="emerald" />
                            <Row label="Change"  value={fmtMoney(order.change_amount, currency)} />
                        </dl>
                    </Card>

                    <Card>
                        <CardHeader title="Invoice" right={invoice ? <StatusBadge type="invoice" status={invoice.status} /> : null} />
                        <div className="px-5 py-4 text-sm">
                            {invoice ? (
                                <Link href={`/dashboard/factures/${invoice.id}`} className="flex items-center gap-2 text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:text-indigo-300 font-mono">
                                    <FileText className="w-4 h-4" /> {invoice.invoice_number}
                                </Link>
                            ) : (
                                <p className="text-content-muted text-xs">
                                    Not invoiced yet.{canInvoice ? ' Use “Generate invoice” above to create one.' : ' You lack permission to issue invoices.'}
                                </p>
                            )}
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Customer" />
                        <dl className="px-5 py-4 space-y-2 text-sm">
                            <Row label="Name"  value={order.customer_name || <span className="text-content-muted">Walk-in</span>} icon={User} />
                            {order.customer_email && <Row label="Email" value={order.customer_email} />}
                            {order.customer_phone && <Row label="Phone" value={order.customer_phone} />}
                        </dl>
                    </Card>

                    <Card>
                        <CardHeader title="Cashier & session" />
                        <dl className="px-5 py-4 space-y-2 text-sm">
                            <Row label="Cashier"    value={order.cashier?.name ?? '—'} icon={User} />
                            <Row label="Receipt #"  value={<span className="font-mono">{order.receipt_number}</span>} icon={Hash} />
                            <Row label="Created"    value={new Date(order.created_at).toLocaleString()} icon={Calendar} />
                        </dl>
                    </Card>
                </div>
            </div>
        </SaasLayout>
    );
}

function Card({ children }) { return <div className="bg-surface-2 border border-line rounded-xl overflow-hidden">{children}</div>; }
function CardHeader({ title, right }) {
    return (
        <div className="px-5 py-3 border-b border-line flex items-center justify-between">
            <h2 className="text-sm font-semibold text-content">{title}</h2>
            {right}
        </div>
    );
}
function Row({ label, value, tone = 'slate', large = false, icon: Icon }) {
    const toneClass = tone === 'red' ? 'text-red-600 dark:text-red-400' : tone === 'emerald' ? 'text-emerald-600 dark:text-emerald-400' : 'text-content';
    return (
        <div className="flex items-center justify-between">
            <span className={`flex items-center gap-1.5 ${large ? 'text-sm font-semibold text-content' : 'text-content-muted'}`}>
                {Icon && <Icon className="w-3.5 h-3.5" />} {label}
            </span>
            <span className={`tabular-nums ${large ? 'text-lg font-bold' : ''} ${toneClass}`}>{value}</span>
        </div>
    );
}
function fmtMoney(value, currency) {
    const n = Number(value) || 0;
    return `${currency} ${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
