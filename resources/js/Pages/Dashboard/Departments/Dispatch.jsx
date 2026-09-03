import { useState, useMemo, useEffect } from 'react';
import { usePage, Link } from '@inertiajs/react';
import {
    Truck, User, Package, Loader2, CheckCircle2, XCircle, ExternalLink,
    MapPin, Clock, Send, FileText, Copy, Building2, Printer, RefreshCw, AlertTriangle,
    FileDown, Tag,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DepartmentNav from '@/Components/Departments/DepartmentNav';
import StatusBadge from '@/Components/StatusBadge';
import useQueue from '@/Hooks/useQueue';
import {
    StatTiles, SourceBadge, QueueToolbar, EmptyQueue, ReasonDialog,
    fmtMoney, timeAgo, ageTone,
} from '@/Components/Departments/QueueParts';

/**
 * Dispatch board — packed orders waiting for a carrier, and everything in flight.
 *
 * A failed delivery is not a dead end: it routes the order into the return flow,
 * where an inspector decides where the goods go. No stock moves here.
 */

const FAILURE_REASONS = [
    { value: 'refused',            label: 'Customer refused delivery' },
    { value: 'damaged_in_transit', label: 'Damaged in transit' },
    { value: 'other',              label: 'Other…' },
];

export default function Dispatch({ store, orders = [], agents = [], couriers = [], manifests = [], stats = {}, departments = [], ozon_connected: ozonConnected = false, sendit_connected: senditConnected = false, can_generate_labels: canGenerateLabels = false, can_view_labels: canViewLabels = false }) {
    const currency = store?.currency ?? 'MAD';
    const page     = usePage();
    const userId   = page.props.auth?.user?.id ?? null;
    // Set alongside flash.error only for a "Send to Ozon" city-mapping
    // failure — see DeliveryShipmentController::sendToOzon().
    const cityIssue = page.props.flash?.city_issue;
    // Set alongside flash.error only when Ozon rejected the parcel or its
    // response couldn't be parsed (never a city-mapping problem).
    const shipmentIssue = page.props.flash?.shipment_issue;
    // Set alongside flash.warning when add-parcel returned a tracking
    // number but parcel-info/tracking could not independently confirm it —
    // see DeliveryShipmentController::sendToOzon()/retryVerification().
    const shipmentVerification = page.props.flash?.shipment_verification;

    const q = useQueue(orders, { userId });
    const [assigning, setAssigning] = useState(null);   // order awaiting a carrier
    const [failing, setFailing]     = useState(null);   // shipment that failed

    const awaiting = useMemo(() => q.rows.filter((o) => ! o.shipment), [q.rows]);
    const inFlight = useMemo(() => q.rows.filter((o) => o.shipment), [q.rows]);

    const post = (order, url, data = {}) => q.submit(url, data, { key: q.keyOf(order) });

    return (
        <SaasLayout pageHeader={{
            title: 'Delivery Board',
            subtitle: 'Assign carriers, track shipments and confirm delivery',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Delivery Board' }],
        }}>
            <DepartmentNav departments={departments} current="dispatch" />

            {cityIssue && (
                <div className="mb-4 flex flex-wrap items-center gap-2 px-4 py-3 rounded-[var(--radius-card)] bg-warning-soft border border-warning/30 text-warning text-sm">
                    <AlertTriangle className="w-4 h-4 shrink-0" />
                    <span>
                        City &quot;{cityIssue.raw_city ?? 'unknown'}&quot; is not mapped to {cityIssue.provider === 'sendit' ? 'Sendit' : 'Ozon'}.
                        {cityIssue.suggested_city_name && <> Suggested match: <strong>{cityIssue.suggested_city_name}</strong>.</>}
                    </span>
                    <Link
                        href={cityIssue.provider === 'sendit' ? '/dashboard/delivery-connections/sendit' : '/dashboard/delivery-connections'}
                        className="ml-auto inline-flex items-center gap-1 px-2.5 py-1 rounded-[var(--radius-button)] bg-surface border border-warning/40 text-xs font-semibold hover:bg-surface-2 transition"
                    >
                        Open city mapping <ExternalLink className="w-3 h-3" />
                    </Link>
                </div>
            )}

            {shipmentIssue && (
                <div className="mb-4 px-4 py-3 rounded-[var(--radius-card)] bg-danger-soft border border-danger/30 text-danger text-sm">
                    <div className="flex items-center gap-2">
                        <AlertTriangle className="w-4 h-4 shrink-0" />
                        <span>
                            {'sent_district_id' in shipmentIssue ? 'Sendit' : 'Ozon Express'} did not accept this delivery — see the toast above for the exact reason.
                        </span>
                    </div>
                    {(shipmentIssue.http_status || (shipmentIssue.response_keys ?? []).length > 0) && (
                        <details className="mt-2 text-xs text-content-muted">
                            <summary className="cursor-pointer select-none hover:text-content">Debug details</summary>
                            <dl className="mt-2 space-y-1 pl-1">
                                {shipmentIssue.provider_message && <div>Provider message: <span className="font-mono">{shipmentIssue.provider_message}</span></div>}
                                {shipmentIssue.http_status && <div>HTTP status: <span className="font-mono">{shipmentIssue.http_status}</span></div>}
                                {shipmentIssue.content_type && <div>Content-Type: <span className="font-mono">{shipmentIssue.content_type}</span></div>}
                                {(shipmentIssue.response_keys ?? []).length > 0 && (
                                    <div>Response keys: <span className="font-mono">{shipmentIssue.response_keys.join(', ')}</span></div>
                                )}
                                {'sent_district_id' in shipmentIssue ? (
                                    <div className="pt-1 mt-1 border-t border-line/60">
                                        <div>Sent district_id: <span className="font-mono">{shipmentIssue.sent_district_id ?? '—'}</span></div>
                                        <div>Sent pickup_district_id: <span className="font-mono">{shipmentIssue.sent_pickup_district_id ?? '—'}</span></div>
                                        <div>Sent amount: <span className="font-mono">{shipmentIssue.sent_amount ?? '—'}</span></div>
                                        <div>Required fields present: <span className="font-mono">{String(!!shipmentIssue.has_required_fields)}</span></div>
                                    </div>
                                ) : (
                                    <div className="pt-1 mt-1 border-t border-line/60">
                                        <div>Sent parcel-stock: <span className="font-mono">{shipmentIssue.parcel_stock_sent ?? '—'}</span></div>
                                        <div>Sent parcel-price: <span className="font-mono">{shipmentIssue.parcel_price_sent ?? '—'}</span></div>
                                        <div>Sent parcel-city: <span className="font-mono">{shipmentIssue.parcel_city_sent ?? '—'}</span></div>
                                        <div>Sent parcel-open: <span className="font-mono">{shipmentIssue.parcel_open_sent ?? '—'}</span></div>
                                        <div>Sent parcel-fragile: <span className="font-mono">{shipmentIssue.parcel_fragile_sent ?? '—'}</span></div>
                                        <div>Sent parcel-replace: <span className="font-mono">{shipmentIssue.parcel_replace_sent ?? '—'}</span></div>
                                        <div>Receiver / phone / address present: <span className="font-mono">
                                            {String(!!shipmentIssue.receiver_present)} / {String(!!shipmentIssue.phone_present)} / {String(!!shipmentIssue.address_present)}
                                        </span></div>
                                        <div>Products: <span className="font-mono">
                                            {shipmentIssue.has_products ? `${shipmentIssue.products_count} sent` : 'none sent'}
                                        </span></div>
                                        {(shipmentIssue.product_refs_preview ?? []).length > 0 && (
                                            <div>Product refs: <span className="font-mono">{shipmentIssue.product_refs_preview.join(', ')}</span></div>
                                        )}
                                        {shipmentIssue.products_json_preview && (
                                            <div>Products JSON sent:
                                                <pre className="mt-1 p-2 rounded bg-surface-3 border border-line overflow-x-auto whitespace-pre-wrap break-all">{shipmentIssue.products_json_preview}</pre>
                                            </div>
                                        )}
                                    </div>
                                )}
                                {shipmentIssue.response_preview && (
                                    <div className="pt-1 mt-1 border-t border-line/60">
                                        Response preview:
                                        <pre className="mt-1 p-2 rounded bg-surface-3 border border-line overflow-x-auto whitespace-pre-wrap break-all">{shipmentIssue.response_preview}</pre>
                                    </div>
                                )}
                            </dl>
                        </details>
                    )}
                </div>
            )}

            {shipmentVerification && (
                <div className="mb-4 px-4 py-3 rounded-[var(--radius-card)] bg-warning-soft border border-warning/30 text-warning text-sm">
                    <div className="flex items-center gap-2">
                        <AlertTriangle className="w-4 h-4 shrink-0" />
                        <span>Ozon returned a tracking number, but the parcel could not be verified in Ozon. Do not hand this parcel to carrier yet.</span>
                    </div>
                    {shipmentVerification.tracking_number_returned && (
                        <p className="mt-1 font-mono text-xs">{shipmentVerification.tracking_number_returned}</p>
                    )}
                    <p className="mt-1.5 text-xs text-warning/80">
                        Some Ozon accounts may require adding parcels to a Bon de Livraison before operational pickup. Verify with Ozon parcel-info/tracking.
                    </p>
                    <details className="mt-2 text-xs text-content-muted">
                        <summary className="cursor-pointer select-none hover:text-content">Debug details</summary>
                        <dl className="mt-2 space-y-1 pl-1">
                            <div>Add-parcel result: <span className="font-mono">{shipmentVerification.add_parcel_result ?? '—'}</span></div>
                            <div>Add-parcel message: <span className="font-mono">{shipmentVerification.add_parcel_message ?? '—'}</span></div>
                            <div>parcel-info HTTP status: <span className="font-mono">{shipmentVerification.parcel_info_http_status ?? '—'}</span></div>
                            <div>parcel-info message: <span className="font-mono">{shipmentVerification.parcel_info_provider_message ?? '—'}</span></div>
                            <div>tracking HTTP status: <span className="font-mono">{shipmentVerification.tracking_http_status ?? '—'}</span></div>
                            <div>tracking message: <span className="font-mono">{shipmentVerification.tracking_provider_message ?? '—'}</span></div>
                            <div>Verification status: <span className="font-mono">{shipmentVerification.verification_status}</span></div>
                            {shipmentVerification.verification_error && (
                                <div>Verification error: <span className="font-mono">{shipmentVerification.verification_error}</span></div>
                            )}
                        </dl>
                    </details>
                </div>
            )}

            <StatTiles tiles={[
                { label: 'Awaiting carrier', value: stats.awaiting ?? 0,  icon: Package,      tone: 'amber' },
                { label: 'In flight',        value: stats.in_flight ?? 0, icon: Truck,        tone: 'blue' },
                { label: 'Delivered',        value: stats.delivered ?? 0, icon: CheckCircle2, tone: 'emerald' },
                { label: 'Failed',           value: stats.failed ?? 0,    icon: XCircle,      tone: 'red' },
            ]} />

            {manifests.length > 0 && <ManifestBar manifests={manifests} />}

            <QueueToolbar
                scope={q.scope} onScope={q.setScope} counts={q.counts}
                search={q.search} onSearch={q.setSearch}
                placeholder="Search order, customer or tracking…"
            />

            {q.rows.length === 0 ? (
                <EmptyQueue
                    title="Nothing to dispatch"
                    hint="Packed orders appear here as soon as the warehouse marks them ready."
                />
            ) : (
                <div className="space-y-6">
                    <Section title="Awaiting carrier" count={awaiting.length}>
                        {awaiting.length === 0 ? (
                            <p className="px-4 py-6 text-center text-sm text-content-muted">
                                Everything packed has been handed over.
                            </p>
                        ) : (
                            <ul className="divide-y divide-line/60">
                                {awaiting.map((o) => (
                                    <li key={q.keyOf(o)} className="p-4 flex flex-col gap-3">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <OrderSummary order={o} currency={currency} />
                                            <div className="flex items-center gap-2">
                                                {/* Quick-send only when this specific order is actually ready for
                                                    that provider right now (readiness computed server-side —
                                                    same check the modal's Integrated Provider tab shows/enforces).
                                                    Otherwise the reason is only visible inside the modal, never a
                                                    disabled/mystery button cluttering the card. */}
                                                {o.dispatch_readiness?.ozon?.ready && (
                                                    <button
                                                        disabled={q.isBusy(o)}
                                                        onClick={() => post(o, `/dashboard/delivery-shipments/orders/${o.id}/ozon`)}
                                                        className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-surface border border-primary/40 text-primary hover:bg-primary-soft disabled:opacity-40 transition"
                                                    >
                                                        {q.isBusy(o) ? <Loader2 className="w-4 h-4 animate-spin" /> : <Truck className="w-4 h-4" />}
                                                        {o.ozon_unverified ? 'Retry send to Ozon' : 'Send to Ozon'}
                                                    </button>
                                                )}
                                                {o.dispatch_readiness?.sendit?.ready && (
                                                    <button
                                                        disabled={q.isBusy(o)}
                                                        onClick={() => post(o, `/dashboard/delivery-shipments/orders/${o.id}/sendit`)}
                                                        className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-surface border border-primary/40 text-primary hover:bg-primary-soft disabled:opacity-40 transition"
                                                    >
                                                        {q.isBusy(o) ? <Loader2 className="w-4 h-4 animate-spin" /> : <Truck className="w-4 h-4" />}
                                                        Send to Sendit
                                                    </button>
                                                )}
                                                <button
                                                    disabled={q.isBusy(o)}
                                                    onClick={() => setAssigning(o)}
                                                    className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-40 transition"
                                                >
                                                    {q.isBusy(o) ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
                                                    Dispatch order
                                                </button>
                                            </div>
                                        </div>
                                        {o.ozon_unverified && (
                                            <OzonUnverifiedBanner
                                                info={o.ozon_unverified}
                                                busy={q.isBusy(o)}
                                                onRetryVerification={() => post(o, `/dashboard/delivery-shipments/${o.ozon_unverified.id}/retry-verification`)}
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section title="In flight" count={inFlight.length}>
                        {inFlight.length === 0 ? (
                            <p className="px-4 py-6 text-center text-sm text-content-muted">Nothing on the road.</p>
                        ) : (
                            <ul className="divide-y divide-line/60">
                                {inFlight.map((o) => {
                                    const s    = o.shipment;
                                    const busy = q.isBusy(o);
                                    const done = s.status === 'delivered' || s.status === 'failed';

                                    return (
                                        <li key={q.keyOf(o)} className="p-4">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <OrderSummary order={o} currency={currency} />

                                                <div className="flex flex-col items-end gap-2">
                                                    <CarrierChip
                                                        shipment={s}
                                                        busy={busy}
                                                        onRefreshTracking={s.provider ? () => post(o, `/dashboard/delivery-shipments/${s.provider.id}/refresh-tracking`) : null}
                                                    />
                                                    {! done && (
                                                        <div className="flex items-center gap-2">
                                                            <button
                                                                disabled={busy}
                                                                onClick={() => setFailing({ order: o, shipment: s })}
                                                                className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-surface border border-danger/40 text-danger hover:bg-danger-soft disabled:opacity-40 transition"
                                                            >
                                                                <XCircle className="w-4 h-4" /> Failed
                                                            </button>
                                                            <button
                                                                disabled={busy}
                                                                onClick={() => post(o, `/dashboard/departments/shipments/${s.id}/delivered`)}
                                                                className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-success text-white hover:brightness-90 disabled:opacity-40 transition"
                                                            >
                                                                {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle2 className="w-4 h-4" />}
                                                                Delivered
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>

                                            {o.ozon_labels && (canGenerateLabels || canViewLabels) && (
                                                <OzonLabelPanel
                                                    labels={o.ozon_labels}
                                                    busy={busy}
                                                    canGenerate={canGenerateLabels}
                                                    canView={canViewLabels}
                                                    onGenerate={() => post(o, '/dashboard/delivery-notes/ozon/generate-labels', { shipment_ids: [o.ozon_labels.shipment_id] })}
                                                />
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </Section>
                </div>
            )}

            <DispatchModal
                order={assigning}
                couriers={couriers}
                agents={agents}
                busy={assigning ? q.isBusy(assigning) : false}
                onCancel={() => setAssigning(null)}
                onManualOrInternal={(data) => {
                    post(assigning, `/dashboard/departments/${assigning.type}/${assigning.id}/carrier`, data);
                    setAssigning(null);
                }}
                onSendToProvider={(providerCode) => {
                    post(assigning, `/dashboard/delivery-shipments/orders/${assigning.id}/${providerCode}`);
                    setAssigning(null);
                }}
            />

            <ReasonDialog
                open={Boolean(failing)}
                title="Delivery failed"
                description="The order moves to the returns queue. No stock moves until an inspector sees the goods."
                confirmLabel="Record failure"
                presets={FAILURE_REASONS}
                onCancel={() => setFailing(null)}
                onConfirm={(reason) => {
                    post(failing.order, `/dashboard/departments/shipments/${failing.shipment.id}/failed`, { reason });
                    setFailing(null);
                }}
            />
        </SaasLayout>
    );
}

/* ------------------------------------------------------------------ */
/* Manifests — batch handover sheets                                   */
/* ------------------------------------------------------------------ */

function ManifestBar({ manifests }) {
    return (
        <section className="mb-4 bg-surface-2 border border-line rounded-[var(--radius-card)] p-3">
            <div className="flex items-center gap-2 mb-2.5 px-1">
                <FileText className="w-4 h-4 text-content-muted" />
                <h2 className="text-sm font-semibold text-content">Manifests</h2>
                <span className="text-[11px] text-content-muted">handover sheets for carrier batches</span>
            </div>
            <div className="flex flex-wrap gap-2">
                {manifests.map((m) => (
                    <a
                        key={m.reference}
                        href={`/dashboard/departments/manifests/${encodeURIComponent(m.reference)}`}
                        target="_blank"
                        rel="noopener"
                        className="group inline-flex items-center gap-2.5 pl-3 pr-2.5 py-2 rounded-[var(--radius-button)] bg-surface border border-line hover:border-primary/50 transition"
                    >
                        <span className="min-w-0">
                            <span className="block font-mono text-xs text-content truncate">{m.reference}</span>
                            <span className="block text-[11px] text-content-muted">
                                {m.carrier} · {m.parcels} parcel{m.parcels === 1 ? '' : 's'}
                                {m.pending > 0 && ` · ${m.pending} in flight`}
                            </span>
                        </span>
                        <span className="shrink-0 inline-flex items-center gap-1 px-2 py-1 rounded-md bg-surface-2 border border-line text-[11px] font-semibold text-content-muted group-hover:text-content transition">
                            <Printer className="w-3.5 h-3.5" /> Print
                        </span>
                    </a>
                ))}
            </div>
        </section>
    );
}

function Section({ title, count, children }) {
    return (
        <section className="bg-surface-2 border border-line rounded-[var(--radius-card)] overflow-hidden">
            <header className="flex items-center justify-between gap-2 px-4 py-3 border-b border-line">
                <h2 className="text-sm font-semibold text-content">{title}</h2>
                <span className="min-w-5 px-1.5 rounded-full bg-surface border border-line text-[11px] tabular-nums text-content-muted">
                    {count}
                </span>
            </header>
            <div className="bg-surface">{children}</div>
        </section>
    );
}

/** Persistent per-row banner for an Ozon parcel that add-parcel accepted but parcel-info/tracking could not confirm — the order stays "awaiting carrier" until this is retried. */
function OzonUnverifiedBanner({ info, busy, onRetryVerification }) {
    return (
        <div className="px-3 py-2.5 rounded-[var(--radius-card)] bg-warning-soft border border-warning/30 text-warning text-xs">
            <div className="flex items-start gap-2">
                <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                <div className="min-w-0">
                    <p>Ozon returned a tracking number, but the parcel could not be verified in Ozon. Do not hand this parcel to carrier yet.</p>
                    {info.tracking_number && <p className="mt-1 font-mono">{info.tracking_number}</p>}
                    <p className="mt-1.5 text-warning/80">
                        Some Ozon accounts may require adding parcels to a Bon de Livraison before operational pickup. Verify with Ozon parcel-info/tracking.
                    </p>
                </div>
            </div>
            <div className="mt-2">
                <button
                    disabled={busy}
                    onClick={onRetryVerification}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-[var(--radius-button)] bg-surface border border-warning/40 text-warning hover:bg-warning-soft disabled:opacity-40 transition"
                >
                    {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />}
                    Retry verification
                </button>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Ozon carrier labels — Bon de Livraison + stored/fallback PDFs       */
/* ------------------------------------------------------------------ */

const LABEL_STATUS_META = {
    shipment_created:  { label: 'Shipment created',        tone: 'text-content-muted' },
    bl_not_created:    { label: 'Tracking available · BL not created', tone: 'text-content-muted' },
    bl_created:        { label: 'BL created',              tone: 'text-blue-600 dark:text-blue-300' },
    bl_saved:          { label: 'BL saved',                tone: 'text-blue-600 dark:text-blue-300' },
    labels_ready:      { label: 'Labels ready',            tone: 'text-emerald-600 dark:text-emerald-300' },
    fallback_ready:    { label: 'Fallback label ready',    tone: 'text-warning' },
    pdf_fetch_failed:  { label: 'Ozon PDF fetch failed',   tone: 'text-danger' },
};

function OzonLabelPanel({ labels, busy, canGenerate, canView, onGenerate }) {
    const meta = LABEL_STATUS_META[labels.status] ?? { label: labels.status, tone: 'text-content-muted' };
    const downloadable = (labels.documents ?? []).filter((d) => d.downloadable && d.download_url);
    const needsGenerate = ['shipment_created', 'bl_not_created', 'pdf_fetch_failed'].includes(labels.status);
    const generateLabel = labels.status === 'pdf_fetch_failed'
        ? 'Retry Ozon BL / labels'
        : (labels.bl_ref ? 'Regenerate Ozon BL / labels' : 'Generate Ozon BL / labels');

    return (
        <div className="mt-3 px-3 py-2.5 rounded-[var(--radius-card)] bg-surface-2 border border-line">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-content-muted">
                    <Tag className="w-3.5 h-3.5" /> Ozon labels
                </span>
                <span className={`text-xs font-semibold ${meta.tone}`}>{meta.label}</span>
                {labels.bl_ref && (
                    <span className="font-mono text-[11px] text-content-muted">{labels.bl_ref}</span>
                )}

                <div className="ml-auto flex flex-wrap items-center gap-2">
                    {canView && downloadable.map((d) => (
                        <a
                            key={d.id}
                            href={d.download_url}
                            target="_blank"
                            rel="noopener"
                            className="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold rounded-[var(--radius-button)] bg-surface border border-line text-content hover:bg-surface-3 transition"
                        >
                            <FileDown className="w-3.5 h-3.5" />
                            {d.type === 'delivery_note' ? 'Download BL'
                                : d.type === 'fallback_label' ? 'Fallback label'
                                : d.variant === '4up' ? 'Tickets (4-up)'
                                : 'Download tickets'}
                        </a>
                    ))}
                    {canGenerate && (needsGenerate || labels.status === 'bl_saved' || labels.status === 'fallback_ready') && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={onGenerate}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-40 transition"
                        >
                            {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <FileText className="w-3.5 h-3.5" />}
                            {generateLabel}
                        </button>
                    )}
                </div>
            </div>

            {labels.status === 'fallback_ready' && (
                <p className="mt-1.5 text-[11px] text-warning/90">
                    Ozon&apos;s official label PDF could not be fetched — an internal fallback label was generated per parcel. Retry once Ozon is reachable.
                </p>
            )}
            {labels.status === 'pdf_fetch_failed' && (
                <p className="mt-1.5 text-[11px] text-danger/90">
                    The BL was saved on Ozon, but no label PDF could be fetched or generated. Retry to produce a fallback label.
                </p>
            )}
        </div>
    );
}

function OrderSummary({ order, currency }) {
    return (
        <div className="min-w-0">
            <div className="flex items-center gap-2">
                <SourceBadge source={order.source} label={order.source_label} />
                <span className="font-mono text-xs text-content-muted">{order.reference}</span>
                <span className={`inline-flex items-center gap-1 text-[11px] ${ageTone(order.updated_at ?? order.created_at)}`}>
                    <Clock className="w-3 h-3" /> {timeAgo(order.updated_at ?? order.created_at)}
                </span>
            </div>
            <h3 className="mt-1.5 text-sm font-semibold text-content truncate">
                {order.customer_name || 'Walk-in customer'}
            </h3>
            <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-content-muted">
                <span className="font-semibold tabular-nums text-content">{fmtMoney(order.total, currency)}</span>
                <span>{order.items?.length ?? 0} item(s)</span>
                {order.customer_phone && <span>{order.customer_phone}</span>}
            </div>
            {order.delivery_address && (
                <p className="mt-1 inline-flex items-start gap-1.5 text-xs text-content-muted">
                    <MapPin className="w-3.5 h-3.5 mt-px shrink-0" />
                    <span className="line-clamp-1">{order.delivery_address}</span>
                </p>
            )}
        </div>
    );
}

/** Ozon Express / Sendit / Manual courier / Internal agent — purely derived from data the board already has, no schema change needed. */
function dispatchMethodBadge(shipment) {
    if (shipment.provider?.code === 'ozon') return 'Ozon Express';
    if (shipment.provider?.code === 'sendit') return 'Sendit';
    if (shipment.carrier_type === 'internal') return 'Internal agent';

    return 'Manual courier';
}

function CarrierChip({ shipment, busy, onRefreshTracking }) {
    const internal = shipment.carrier_type === 'internal';
    const Icon = internal ? User : Building2;

    return (
        <div className="text-right">
            <div className="text-[10px] font-semibold uppercase tracking-wider text-content-muted mb-1">
                {dispatchMethodBadge(shipment)}
            </div>
            <span className={[
                'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold',
                internal
                    ? 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300'
                    : 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
            ].join(' ')}>
                <Icon className="w-3.5 h-3.5" />
                {shipment.carrier_label}
            </span>

            {shipment.provider && (
                <div className="mt-1 flex items-center justify-end gap-1.5">
                    <StatusBadge status={shipment.provider.status} type="shipment" />
                    {onRefreshTracking && (
                        <button
                            disabled={busy}
                            onClick={onRefreshTracking}
                            aria-label="Refresh tracking"
                            className="text-content-muted hover:text-content transition disabled:opacity-40"
                        >
                            <RefreshCw className="w-3.5 h-3.5" />
                        </button>
                    )}
                </div>
            )}

            {shipment.tracking_number && (
                <div className="mt-1 flex items-center justify-end gap-1.5 text-[11px] text-content-muted">
                    <span className="font-mono">{shipment.tracking_number}</span>
                    <button
                        onClick={() => navigator.clipboard?.writeText(shipment.tracking_number)}
                        aria-label="Copy tracking number"
                        className="hover:text-content transition"
                    >
                        <Copy className="w-3 h-3" />
                    </button>
                    {shipment.tracking_url && (
                        <a href={shipment.tracking_url} target="_blank" rel="noopener" className="hover:text-content transition">
                            <ExternalLink className="w-3 h-3" />
                        </a>
                    )}
                </div>
            )}

            {shipment.manifest_reference && (
                <div className="mt-0.5 inline-flex items-center gap-1 text-[11px] text-content-muted">
                    <FileText className="w-3 h-3" /> {shipment.manifest_reference}
                </div>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Dispatch modal — provider-aware: Integrated provider / Manual        */
/* external courier / Internal agent. Both the Integrated Provider tab  */
/* here and the order card's quick-send buttons hit the EXACT SAME      */
/* backend actions (/dashboard/delivery-shipments/orders/{id}/{ozon|    */
/* sendit}) — never a second, parallel dispatch path.                   */
/* ------------------------------------------------------------------ */

const PROVIDER_META = {
    ozon: {
        name: 'Ozon Express',
        sendLabel: 'Send to Ozon',
        settingsUrl: '/dashboard/delivery-connections',
        capabilities: ['Create shipment', 'Tracking', 'Delivery notes'],
    },
    sendit: {
        name: 'Sendit',
        sendLabel: 'Send to Sendit',
        settingsUrl: '/dashboard/delivery-connections/sendit',
        capabilities: ['Create shipment', 'Tracking', 'Labels', 'Webhooks'],
    },
};

const MODES = [
    { value: 'integrated', label: 'Integrated provider', hint: 'Send this order through a connected delivery company.', icon: Truck },
    { value: 'manual', label: 'Manual external courier', hint: 'Assign to a courier outside the system and enter tracking manually.', icon: Building2 },
    { value: 'internal', label: 'Internal agent', hint: 'Assign to one of your delivery agents.', icon: User },
];

function DispatchModal({ order, couriers, agents, busy, onCancel, onManualOrInternal, onSendToProvider }) {
    const readiness = order?.dispatch_readiness ?? {};
    const anyProviderAvailable = Boolean(readiness.ozon?.available || readiness.sendit?.available);
    const [mode, setMode] = useState(anyProviderAvailable ? 'integrated' : 'manual');

    // The modal component instance is reused across orders (it just renders
    // null when there's no order selected) — reset to the most useful tab
    // whenever a DIFFERENT order is opened, rather than keeping whatever tab
    // was last picked for a previous order.
    useEffect(() => {
        if (order) setMode(anyProviderAvailable ? 'integrated' : 'manual');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [order?.id]);

    if (! order) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div onClick={onCancel} className="absolute inset-0 bg-black/50 backdrop-blur-sm" />
            <div role="dialog" aria-modal="true" className="relative w-full max-w-xl bg-surface border border-line rounded-[var(--radius-card)] shadow-2xl p-5 max-h-[90vh] overflow-y-auto">
                <h3 className="text-base font-semibold text-content">Dispatch order</h3>
                <p className="mt-0.5 text-sm text-content-muted">Choose how this order will be delivered.</p>
                <p className="mt-1.5 text-sm text-content-muted">
                    <span className="font-mono text-xs">{order.reference}</span> · {order.customer_name || 'Walk-in customer'}
                </p>

                {/* Dispatch method */}
                <div className="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-2">
                    {MODES.map((opt) => {
                        const Icon = opt.icon;
                        const on = mode === opt.value;
                        return (
                            <button
                                key={opt.value}
                                onClick={() => setMode(opt.value)}
                                className={[
                                    'flex items-start gap-2.5 px-3 py-2.5 rounded-[var(--radius-button)] border text-left transition',
                                    on
                                        ? 'border-primary bg-primary-soft text-primary'
                                        : 'border-line bg-surface-2 text-content-muted hover:text-content hover:bg-surface-3',
                                ].join(' ')}
                            >
                                <Icon className="w-4 h-4 mt-0.5 shrink-0" />
                                <span className="min-w-0">
                                    <span className="block text-sm font-medium">{opt.label}</span>
                                    <span className="block text-[11px] opacity-80">{opt.hint}</span>
                                </span>
                            </button>
                        );
                    })}
                </div>

                <div className="mt-4">
                    {mode === 'integrated' && (
                        <IntegratedProviderPanel readiness={readiness} busy={busy} onSend={onSendToProvider} />
                    )}
                    {mode === 'manual' && (
                        <ManualCourierPanel couriers={couriers} busy={busy} onSubmit={onManualOrInternal} />
                    )}
                    {mode === 'internal' && (
                        <InternalAgentPanel agents={agents} busy={busy} onSubmit={onManualOrInternal} />
                    )}
                </div>

                <div className="mt-5 flex justify-end">
                    <button onClick={onCancel} className="px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted hover:text-content transition">
                        Never mind
                    </button>
                </div>
            </div>
        </div>
    );
}

/** Ozon/Sendit cards — only rendered for a provider with a connection configured at all (`available`). Send button disabled with exact reasons whenever not ready. */
function IntegratedProviderPanel({ readiness, busy, onSend }) {
    const providers = Object.keys(PROVIDER_META).filter((code) => readiness[code]?.available);

    if (providers.length === 0) {
        return (
            <div className="px-4 py-6 text-center text-sm text-content-muted bg-surface-2 border border-line rounded-[var(--radius-card)]">
                No delivery provider is connected yet.
                <div className="mt-2">
                    <Link href="/dashboard/integrations?tab=delivery" className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                        Connect a provider <ExternalLink className="w-3 h-3" />
                    </Link>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {providers.map((code) => {
                const meta = PROVIDER_META[code];
                const r = readiness[code];

                return (
                    <div key={code} className="p-3.5 rounded-[var(--radius-card)] border border-line bg-surface-2">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-semibold text-content">{meta.name}</span>
                                    <StatusBadge status={r.status ?? 'disabled'} type="delivery_connection" />
                                </div>
                                <div className="mt-1.5 flex flex-wrap gap-1.5">
                                    {meta.capabilities.map((cap) => (
                                        <span key={cap} className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-slate-500/15 text-slate-600 dark:text-slate-300">{cap}</span>
                                    ))}
                                </div>
                            </div>
                            <button
                                disabled={! r.ready || busy}
                                onClick={() => onSend(code)}
                                className="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-40 disabled:cursor-not-allowed transition"
                            >
                                {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Truck className="w-3.5 h-3.5" />}
                                {meta.sendLabel}
                            </button>
                        </div>

                        {! r.ready && r.reasons?.length > 0 && (
                            <div className="mt-2.5 pt-2.5 border-t border-line/60">
                                <ul className="space-y-1 text-xs text-warning">
                                    {r.reasons.map((reason, i) => (
                                        <li key={i} className="flex items-start gap-1.5">
                                            <AlertTriangle className="w-3.5 h-3.5 shrink-0 mt-px" /> {reason}
                                        </li>
                                    ))}
                                </ul>
                                <div className="mt-2 flex flex-wrap gap-3">
                                    <Link href={meta.settingsUrl} className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                        Open provider settings <ExternalLink className="w-3 h-3" />
                                    </Link>
                                    <Link href={meta.settingsUrl} className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                        Open city/district mapping <ExternalLink className="w-3 h-3" />
                                    </Link>
                                </div>
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

/** Never Ozon Express / Sendit as suggestions here — those are integrated providers, not a manual courier; typing them by hand is still allowed (still just a free-text carrier_name), only the autocomplete list is filtered. */
function ManualCourierPanel({ couriers, busy, onSubmit }) {
    const [name, setName]         = useState('');
    const [tracking, setTracking] = useState('');
    const [url, setUrl]           = useState('');
    const [manifest, setManifest] = useState('');
    const [notes, setNotes]       = useState('');

    const suggestions = (couriers ?? []).filter((c) => ! ['Ozon Express', 'Sendit'].includes(c));
    const valid = name.trim().length > 0;

    const submit = () => onSubmit({
        carrier_type: 'courier',
        carrier_name: name.trim(),
        tracking_number: tracking.trim() || null,
        tracking_url: url.trim() || null,
        manifest_reference: manifest.trim() || null,
        notes: notes.trim() || null,
    });

    return (
        <div className="space-y-3">
            <Field label="Courier name">
                <input
                    autoFocus
                    list="known-couriers"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="e.g. Amana, CTM, DHL…"
                    className={inputCls}
                />
                <datalist id="known-couriers">
                    {suggestions.map((c) => <option key={c} value={c} />)}
                </datalist>
            </Field>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Field label="Tracking number">
                    <input value={tracking} onChange={(e) => setTracking(e.target.value)} placeholder="Optional" className={inputCls} />
                </Field>
                <Field label="Tracking URL">
                    <input value={url} onChange={(e) => setUrl(e.target.value)} placeholder="Optional — https://…" className={inputCls} />
                </Field>
            </div>
            <Field label="Manifest reference" hint="Groups a day's handover to one carrier for signing.">
                <input value={manifest} onChange={(e) => setManifest(e.target.value)} placeholder="Optional — e.g. MAN-AMANA-20260724" className={inputCls} />
            </Field>
            <Field label="Notes">
                <input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional" className={inputCls} />
            </Field>

            <div className="flex justify-end">
                <button
                    disabled={! valid || busy}
                    onClick={submit}
                    className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-40 disabled:cursor-not-allowed transition"
                >
                    {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
                    Assign manual courier
                </button>
            </div>
        </div>
    );
}

function InternalAgentPanel({ agents, busy, onSubmit }) {
    const [agentId, setAgentId] = useState('');
    const [notes, setNotes]     = useState('');

    const valid = agentId !== '';

    const submit = () => onSubmit({
        carrier_type: 'internal',
        agent_id: agentId,
        notes: notes.trim() || null,
    });

    return (
        <div className="space-y-3">
            <Field label="Delivery agent">
                <select value={agentId} onChange={(e) => setAgentId(e.target.value)} className={inputCls}>
                    <option value="">Choose an agent…</option>
                    {(agents ?? []).map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.name}{a.assigned ? ` — ${a.assigned} in hand` : ''}
                        </option>
                    ))}
                </select>
            </Field>
            <Field label="Notes">
                <input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional" className={inputCls} />
            </Field>

            <div className="flex justify-end">
                <button
                    disabled={! valid || busy}
                    onClick={submit}
                    className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-40 disabled:cursor-not-allowed transition"
                >
                    {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
                    Assign agent
                </button>
            </div>
        </div>
    );
}

const inputCls = 'w-full px-3 py-2 text-sm rounded-[var(--radius-button)] bg-surface-2 border border-line text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-primary/40 focus:border-primary/50 transition';

function Field({ label, hint, children }) {
    return (
        <div>
            <label className="block text-xs font-medium text-content-muted mb-1.5">{label}</label>
            {children}
            {hint && <p className="mt-1 text-[11px] text-content-muted">{hint}</p>}
        </div>
    );
}
