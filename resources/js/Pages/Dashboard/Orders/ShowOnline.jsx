import { useState, useEffect } from 'react';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, Printer, User, Calendar, Hash, FileText, FilePlus, Loader2, Globe, MapPin, Phone, Mail, Warehouse, AlertTriangle, Truck, RefreshCw, FileDown } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatusBadge from '@/Components/StatusBadge';

export default function ShowOnline({ order, store, invoice = null, canInvoice = false, shipment = null, ozon_city_resolution: ozonCityResolution = null, fulfillment_documents: fulfillmentDocuments = [], can_view_fulfillment_documents: canViewFulfillmentDocuments = false }) {
    const currency = store?.currency ?? 'MAD';
    const [generating, setGenerating] = useState(false);

    // Opening this order's detail page marks only ITS new-order notification
    // seen for this user — never all of them, per the ticket's own scoping.
    useEffect(() => {
        if (! order?.id) return;
        axios.post('/dashboard/notifications/mark-seen', { context: 'order_detail', order_id: order.id }).catch(() => {});
    }, [order?.id]);

    const generateInvoice = () => {
        if (! confirm('Generate a finalized invoice for this online order? It will be locked once issued.')) return;
        setGenerating(true);
        router.post('/dashboard/invoices',
            { source_type: 'order', source_id: order.id },
            { preserveScroll: true, onFinish: () => setGenerating(false) },
        );
    };

    const receiptUrl = `/dashboard/orders/online/${order.id}/receipt`;

    return (
        <SaasLayout pageHeader={{
            title: `Order ${order.reference}`,
            subtitle: order.customer_name || 'Online customer',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Orders',    href: '/dashboard/orders' },
                { label: order.reference },
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
                        href={receiptUrl}
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
                                        {order.items.map((it, i) => (
                                            <tr key={i} className="text-content-muted">
                                                <td className="px-4 py-3">
                                                    <div className="font-medium text-content">{it.name}</div>
                                                    {it.sku && <div className="text-xs font-mono text-content-muted">{it.sku}</div>}
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
                        <CardHeader
                            title="Order summary"
                            right={<StatusBadge type="fulfillment" status={order.status} label={order.status_label} />}
                        />
                        <dl className="px-5 py-4 space-y-2 text-sm">
                            <Row label="Subtotal" value={fmtMoney(order.subtotal, currency)} />
                            {order.discount > 0 && <Row label="Discount" value={`−${fmtMoney(order.discount, currency)}`} tone="red" />}
                            {order.tax > 0 && <Row label="Tax" value={fmtMoney(order.tax, currency)} />}
                            <div className="pt-2 mt-2 border-t border-line" />
                            <Row label="Total" value={fmtMoney(order.total, currency)} large />
                        </dl>
                    </Card>

                    <Card>
                        <CardHeader title="Invoice" right={invoice ? <StatusBadge type="invoice" status={invoice.status} /> : null} />
                        <div className="px-5 py-4 text-sm">
                            {invoice ? (
                                <Link href={`/dashboard/factures/${invoice.id}`} className="flex items-center gap-2 text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 font-mono">
                                    <FileText className="w-4 h-4" /> {invoice.invoice_number}
                                </Link>
                            ) : (
                                <p className="text-content-muted text-xs">
                                    Not invoiced yet.{canInvoice ? ' Use “Generate invoice” above to create the A4 invoice.' : ' You lack permission to issue invoices.'}
                                </p>
                            )}
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="Customer" />
                        <dl className="px-5 py-4 space-y-2 text-sm">
                            <Row label="Name"  value={order.customer_name || <span className="text-content-muted">—</span>} icon={User} />
                            {order.customer_phone && <Row label="Phone" value={order.customer_phone} icon={Phone} />}
                            {order.customer_email && <Row label="Email" value={order.customer_email} icon={Mail} />}
                            {order.delivery_address && <Row label="Address" value={order.delivery_address} icon={MapPin} />}
                        </dl>
                    </Card>

                    {shipment && <DeliveryCard shipment={shipment} />}
                    {!shipment && ozonCityResolution && <OzonCityCard resolution={ozonCityResolution} />}

                    {(fulfillmentDocuments.length > 0 || shipment) && (
                        <DocumentsCard documents={fulfillmentDocuments} canView={canViewFulfillmentDocuments} />
                    )}

                    <Card>
                        <CardHeader title="Origin" />
                        <dl className="px-5 py-4 space-y-2 text-sm">
                            <Row label="Platform"   value={order.platform_label ?? order.source_label ?? 'Online store'} icon={Globe} />
                            {order.store_domain && <Row label="Store" value={order.store_domain} />}
                            <Row label="Order #"    value={<span className="font-mono">{order.reference}</span>} icon={Hash} />
                            {order.external_order_number && order.external_order_number !== order.reference && (
                                <Row label="Platform order #" value={<span className="font-mono">{order.external_order_number}</span>} />
                            )}
                            <Row label="Created"    value={order.created_at ? new Date(order.created_at).toLocaleString() : '—'} icon={Calendar} />
                        </dl>
                    </Card>

                    {(order.inventory_status || order.allocation || (order.unmapped_lines ?? []).length > 0) && (
                        <Card>
                            <CardHeader title="Inventory" />
                            <dl className="px-5 py-4 space-y-2 text-sm">
                                {order.inventory_status && <Row label="Status" value={order.inventory_status.label} icon={Warehouse} />}
                                {order.allocation?.warehouse_name && <Row label="Warehouse" value={order.allocation.warehouse_name} icon={Warehouse} />}
                                {(order.unmapped_lines ?? []).length > 0 && (
                                    <p className="flex items-start gap-1.5 text-amber-600 dark:text-amber-400 text-xs pt-1">
                                        <AlertTriangle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
                                        Some lines are not linked to local inventory: {order.unmapped_lines.join(', ')}.
                                    </p>
                                )}
                            </dl>
                        </Card>
                    )}
                </div>
            </div>
        </SaasLayout>
    );
}

/** Pre-send visibility into how "Send to Ozon" would resolve this order's city — helps debug "not mapped" errors. */
function OzonCityCard({ resolution }) {
    return (
        <Card>
            <CardHeader
                title="Ozon city mapping"
                right={<StatusBadge type="city_match" status={resolution.resolved ? 'exact' : 'no_match'} label={resolution.resolved ? 'Resolved' : 'Unresolved'} />}
            />
            <dl className="px-5 py-4 space-y-2 text-sm">
                <Row label="Order city" value={resolution.raw_city || <span className="text-content-muted">—</span>} icon={MapPin} />
                <Row label="Internal city" value={resolution.internal_city_name || <span className="text-content-muted">Not matched</span>} />
                <Row label="Ozon city" value={resolution.provider_city_name || <span className="text-content-muted">Not mapped</span>} />
            </dl>
            {!resolution.resolved && resolution.suggested_internal_city_name && (
                <p className="px-5 pb-4 text-xs text-amber-600 dark:text-amber-400">
                    Suggested match: <strong>{resolution.suggested_internal_city_name}</strong> — map it on the Delivery providers page.
                </p>
            )}
        </Card>
    );
}

function DeliveryCard({ shipment }) {
    const [refreshing, setRefreshing] = useState(false);

    const refresh = () => {
        setRefreshing(true);
        router.post(`/dashboard/delivery-shipments/${shipment.id}/refresh-tracking`, {}, {
            preserveScroll: true,
            onFinish: () => setRefreshing(false),
        });
    };

    return (
        <Card>
            <CardHeader
                title="Delivery"
                right={<StatusBadge type="shipment" status={shipment.status} />}
            />
            <dl className="px-5 py-4 space-y-2 text-sm">
                <Row label="Carrier" value={shipment.provider === 'ozon' ? 'Ozon Express' : shipment.provider} icon={Truck} />
                {shipment.tracking_number && <Row label="Tracking #" value={<span className="font-mono">{shipment.tracking_number}</span>} />}
                {shipment.last_update && <Row label="Last update" value={new Date(shipment.last_update).toLocaleString()} icon={Calendar} />}
            </dl>
            <div className="px-5 pb-4">
                <button
                    type="button"
                    onClick={refresh}
                    disabled={refreshing}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-surface-1 disabled:opacity-50"
                >
                    {refreshing ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />} Refresh tracking
                </button>
            </div>
        </Card>
    );
}

const DOC_STATUS_TONE = {
    stored: 'text-emerald-600 dark:text-emerald-400',
    generated: 'text-amber-600 dark:text-amber-400',
    external_url_unavailable: 'text-amber-600 dark:text-amber-400',
    fetch_failed: 'text-red-600 dark:text-red-400',
};

/** Carrier labels, Bon de Livraison and fallback labels stored for this order. Read-only. */
function DocumentsCard({ documents = [], canView = false }) {
    return (
        <Card>
            <CardHeader title="Documents" />
            <div className="px-5 py-4 space-y-2 text-sm">
                {documents.length === 0 ? (
                    <p className="text-content-muted text-xs">
                        No carrier labels yet. Generate the Ozon BL / labels from the Delivery Board once this order has a shipment.
                    </p>
                ) : (
                    documents.map((d) => (
                        <div key={d.id} className="flex items-center justify-between gap-3">
                            <span className="flex items-center gap-1.5 text-content-muted">
                                <FileText className="w-3.5 h-3.5" />
                                {d.label}{d.variant === '4up' ? ' (4-up)' : ''}
                            </span>
                            <span className="flex items-center gap-2">
                                <span className={`text-xs ${DOC_STATUS_TONE[d.status] ?? 'text-content-muted'}`}>
                                    {d.status_label ?? d.status}
                                </span>
                                {canView && d.downloadable && d.download_url && (
                                    <a
                                        href={d.download_url}
                                        target="_blank"
                                        rel="noopener"
                                        className="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-surface-1"
                                    >
                                        <FileDown className="w-3.5 h-3.5" /> Download
                                    </a>
                                )}
                            </span>
                        </div>
                    ))
                )}
            </div>
        </Card>
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
        <div className="flex items-center justify-between gap-3">
            <span className={`flex items-center gap-1.5 ${large ? 'text-sm font-semibold text-content' : 'text-content-muted'}`}>
                {Icon && <Icon className="w-3.5 h-3.5" />} {label}
            </span>
            <span className={`tabular-nums text-right ${large ? 'text-lg font-bold' : ''} ${toneClass}`}>{value}</span>
        </div>
    );
}
function fmtMoney(value, currency) {
    const n = Number(value) || 0;
    return `${currency} ${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
