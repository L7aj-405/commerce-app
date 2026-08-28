import { useState } from 'react';
import axios from 'axios';
import { X, Loader2, CheckCircle2, XCircle, MinusCircle, Clock } from 'lucide-react';
import StatusBadge from '@/Components/StatusBadge';

/**
 * Explicit publish-target selection — the fix for "clicking Publish pushes
 * to every connected platform". Nothing is selected by default; the user
 * must check at least one connection before "Publish selected" is enabled.
 *
 * mode="single": product = { id, name, sku, channel_listings }
 * mode="bulk":   productIds = [...], productCount = N
 * readiness (single mode only): { shopify: {status,warnings,errors}, woocommerce: {...} }
 *   — a platform with status "blocked" cannot be selected for publish here.
 */
export default function PublishTargetModal({ mode = 'single', product, productIds, productCount, connections = [], readiness = {}, onClose, onPublished }) {
    const [selected, setSelected] = useState([]);
    const [createMissing, setCreateMissing] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [outcome, setOutcome] = useState(null); // { results } | { summary, results } | { queued: true, batch_id, connections }
    const [submitError, setSubmitError] = useState(null);
    const [polling, setPolling] = useState(false);

    const linkedConnectionIds = mode === 'single'
        ? new Set((product?.channel_listings ?? []).map((l) => l.platform_connection_id))
        : null;

    const isBlocked = (platform) => mode === 'single' && readiness?.[platform]?.status === 'blocked';

    const toggle = (id, platform) => {
        if (isBlocked(platform)) return;
        setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    };

    const hasUnlinkedSelected = mode === 'single'
        ? selected.some((id) => !linkedConnectionIds.has(id))
        : selected.length > 0; // bulk: linkage is per-product, unknown up front

    const submit = () => {
        if (selected.length === 0) return;
        setSubmitting(true);
        setSubmitError(null);

        const payload = { connection_ids: selected, create_missing_listings: createMissing };
        const url = mode === 'single'
            ? `/dashboard/products/${product.id}/publish`
            : '/dashboard/products/bulk-publish';
        const body = mode === 'single' ? payload : { ...payload, product_ids: productIds };

        axios.post(url, body)
            .then((res) => {
                setOutcome(res.data);
                onPublished?.();
            })
            .catch((err) => setSubmitError(err.response?.data?.message ?? 'Publish request failed.'))
            .finally(() => setSubmitting(false));
    };

    // Queued variant (CV5) — single-product only. Returns immediately with a
    // batch id instead of waiting for the platform HTTP calls; "Check status"
    // polls once on demand rather than auto-refreshing.
    const submitQueued = () => {
        if (selected.length === 0 || mode !== 'single') return;
        setSubmitting(true);
        setSubmitError(null);

        axios.post(`/dashboard/products/${product.id}/publish-queued`, {
            connection_ids: selected,
            create_missing_listings: createMissing,
        })
            .then((res) => {
                setOutcome({ queued: true, batch_id: res.data.batch_id, connections: res.data.connections, results: [] });
                onPublished?.();
            })
            .catch((err) => setSubmitError(err.response?.data?.message ?? 'Publish request failed.'))
            .finally(() => setSubmitting(false));
    };

    const checkStatus = () => {
        if (!outcome?.batch_id) return;
        setPolling(true);
        axios.get(`/dashboard/products/publish-batches/${outcome.batch_id}`)
            .then((res) => setOutcome({ queued: true, batch_id: res.data.batch_id, connections: outcome.connections, results: res.data.results, batchStatus: res.data.status }))
            .finally(() => setPolling(false));
    };

    return (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
            <div className="bg-surface-2 border border-line rounded-xl shadow-xl max-w-lg w-full max-h-[85vh] overflow-hidden flex flex-col text-left">
                <div className="p-4 border-b border-line flex justify-between items-center bg-surface flex-shrink-0">
                    <div>
                        <h3 className="font-semibold text-content">Choose publish target</h3>
                        <p className="text-xs text-content-muted mt-0.5">
                            {mode === 'single' ? (
                                <>{product?.name} <span className="font-mono">({product?.sku})</span></>
                            ) : (
                                `${productCount} product${productCount === 1 ? '' : 's'} selected`
                            )}
                        </p>
                    </div>
                    <button type="button" onClick={onClose} className="text-content-muted hover:text-content flex-shrink-0">
                        <X className="w-4 h-4" />
                    </button>
                </div>

                <div className="p-4 overflow-y-auto space-y-4">
                    {outcome ? (
                        outcome.queued ? (
                            <QueuedResultsView outcome={outcome} connections={connections} onCheckStatus={checkStatus} polling={polling} />
                        ) : (
                            <ResultsView mode={mode} outcome={outcome} connections={connections} />
                        )
                    ) : (
                        <>
                            <p className="text-xs text-primary-strong bg-primary-soft border border-primary/30 rounded-[var(--radius-button)] p-2.5">
                                Publish sends this SaaS product <strong>to</strong> the channel(s) you select below — the SaaS product is always the source of truth. Publishing to an already-linked channel updates the existing remote product; publishing to a new channel creates one and links it here (only if you check "create new" below). <strong>Queue publish</strong> (recommended) runs this in the background and is the official publish action; "Publish now" waits for the platform response in this request and is kept mainly for quick single-connection checks.
                            </p>

                            {mode === 'bulk' && (
                                <p className="text-xs text-warning bg-warning-soft border border-warning/30 rounded-[var(--radius-button)] p-2.5">
                                    Only products with existing mappings for the selected channel will be updated. Unlinked products will be skipped unless create-on-publish is explicitly supported. Bulk publish is synchronous only for now (no background queue yet) — for a single product, prefer "Queue publish".
                                </p>
                            )}

                            {connections.length === 0 ? (
                                <p className="text-sm text-content-muted">No platform connections for this store yet.</p>
                            ) : (
                                <div className="space-y-2">
                                    {connections.map((conn) => {
                                        const linked = mode === 'single' ? linkedConnectionIds.has(conn.id) : null;
                                        const listing = mode === 'single'
                                            ? (product?.channel_listings ?? []).find((l) => l.platform_connection_id === conn.id)
                                            : null;
                                        const blocked = isBlocked(conn.platform);

                                        return (
                                            <label key={conn.id} className={`flex items-start gap-3 p-3 rounded-[var(--radius-button)] border ${blocked ? 'border-danger/30 bg-danger-soft cursor-not-allowed opacity-75' : 'border-line bg-surface hover:bg-surface-3 cursor-pointer'}`}>
                                                <input
                                                    type="checkbox"
                                                    checked={selected.includes(conn.id)}
                                                    disabled={blocked}
                                                    onChange={() => toggle(conn.id, conn.platform)}
                                                    className="mt-0.5 rounded border-line"
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        <span className="text-sm font-medium text-content uppercase">{conn.platform}</span>
                                                        {conn.label && <span className="text-xs text-content-muted">{conn.label}</span>}
                                                        <span className={`text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase ${conn.status === 'active' ? 'bg-success-soft text-success' : 'bg-slate-500/15 text-content-muted'}`}>
                                                            {conn.status}
                                                        </span>
                                                    </div>
                                                    {blocked ? (
                                                        <p className="mt-1 text-xs text-danger">
                                                            {readiness[conn.platform]?.errors?.[0] ?? 'Not ready to publish to this platform.'}
                                                        </p>
                                                    ) : mode === 'single' && (
                                                        linked ? (
                                                            <div className="mt-1 flex items-center gap-1.5">
                                                                <span className="text-xs text-content-muted">Linked</span>
                                                                {listing?.sync_status && <StatusBadge type="sync" status={listing.sync_status} />}
                                                            </div>
                                                        ) : (
                                                            <p className="mt-1 text-xs text-warning">
                                                                This product is not linked to this channel yet.
                                                            </p>
                                                        )
                                                    )}
                                                </div>
                                            </label>
                                        );
                                    })}
                                </div>
                            )}

                            {hasUnlinkedSelected && (
                                <label className="flex items-start gap-2 text-xs text-content-muted p-2.5 rounded-[var(--radius-button)] border border-line bg-surface">
                                    <input type="checkbox" checked={createMissing} onChange={(e) => setCreateMissing(e.target.checked)} className="mt-0.5 rounded border-line" />
                                    Create new product on channels without an existing link.
                                </label>
                            )}

                            {submitError && <p className="text-xs text-danger">{submitError}</p>}
                        </>
                    )}
                </div>

                <div className="p-4 border-t border-line flex items-center justify-between gap-3 flex-shrink-0 bg-surface">
                    {outcome ? (
                        <button type="button" onClick={onClose} className="ml-auto px-4 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-3 border border-line text-content hover:bg-content/10">
                            Close
                        </button>
                    ) : (
                        <>
                            <span className="text-xs text-content-muted">
                                {selected.length === 0 ? 'Choose at least one channel.' : `${selected.length} channel${selected.length === 1 ? '' : 's'} selected`}
                            </span>
                            <div className="flex items-center gap-2">
                                <button type="button" onClick={onClose} className="px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-3 border border-line text-content hover:bg-content/10">
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={submit}
                                    disabled={selected.length === 0 || submitting}
                                    title="Waits for the platform response in this request — kept for quick single-connection checks and backward compatibility."
                                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-3 border border-line text-content hover:bg-content/10 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {submitting && <Loader2 className="w-4 h-4 animate-spin" />}
                                    Publish now
                                </button>
                                {mode === 'single' && (
                                    <button
                                        type="button"
                                        onClick={submitQueued}
                                        disabled={selected.length === 0 || submitting}
                                        title="Official publish action — runs in the background, so it never times out on products with many variants."
                                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        {submitting && <Loader2 className="w-4 h-4 animate-spin" />}
                                        Queue publish
                                    </button>
                                )}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

function ResultsView({ mode, outcome, connections }) {
    const platformFor = (connectionId) => connections.find((c) => c.id === connectionId)?.platform ?? '—';
    const icon = { succeeded: CheckCircle2, failed: XCircle, skipped: MinusCircle };
    const tone = { succeeded: 'text-success', failed: 'text-danger', skipped: 'text-content-muted' };

    return (
        <div className="space-y-4">
            {mode === 'bulk' && outcome.summary && (
                <div className="grid grid-cols-3 gap-2 text-center">
                    <SummaryStat label="Succeeded" value={outcome.summary.succeeded} tone="text-success" />
                    <SummaryStat label="Failed" value={outcome.summary.failed} tone="text-danger" />
                    <SummaryStat label="Skipped" value={outcome.summary.skipped} tone="text-content-muted" />
                </div>
            )}

            <div className="space-y-1.5">
                {(outcome.results ?? []).map((r, i) => {
                    const Icon = icon[r.status] ?? MinusCircle;
                    return (
                        <div key={i} className="flex items-start gap-2 p-2.5 rounded-[var(--radius-button)] border border-line bg-surface text-xs">
                            <Icon className={`w-4 h-4 flex-shrink-0 mt-0.5 ${tone[r.status] ?? 'text-content-muted'}`} />
                            <div className="min-w-0">
                                <span className="font-medium text-content uppercase">{r.platform ?? platformFor(r.connection_id)}</span>
                                {mode === 'bulk' && r.product_id && <span className="text-content-muted"> · {r.product_id}</span>}
                                <p className="text-content-muted mt-0.5">{r.message}</p>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function QueuedResultsView({ outcome, connections, onCheckStatus, polling }) {
    const platformFor = (connectionId) => connections.find((c) => c.id === connectionId)?.platform ?? '—';
    const icon = { succeeded: CheckCircle2, failed: XCircle, skipped: MinusCircle, queued: Clock, running: Loader2 };
    const tone = { succeeded: 'text-success', failed: 'text-danger', skipped: 'text-content-muted', queued: 'text-content-muted', running: 'text-primary' };

    return (
        <div className="space-y-4">
            <div className="flex items-start gap-2 p-2.5 rounded-[var(--radius-button)] border border-primary/30 bg-primary-soft text-xs text-primary-strong">
                <Clock className="w-4 h-4 flex-shrink-0 mt-0.5" />
                <div>
                    <p className="font-medium">Publish queued{outcome.batchStatus ? ` — ${outcome.batchStatus}` : ''}.</p>
                    <p className="mt-0.5 text-content-muted">
                        {(outcome.connections ?? []).map((c) => c.platform).join(', ') || 'Selected channels'} will update in the background.
                    </p>
                </div>
            </div>

            {(outcome.results ?? []).length > 0 && (
                <div className="space-y-1.5">
                    {outcome.results.map((r, i) => {
                        const Icon = icon[r.status] ?? Clock;
                        return (
                            <div key={i} className="flex items-start gap-2 p-2.5 rounded-[var(--radius-button)] border border-line bg-surface text-xs">
                                <Icon className={`w-4 h-4 flex-shrink-0 mt-0.5 ${tone[r.status] ?? 'text-content-muted'} ${r.status === 'running' ? 'animate-spin' : ''}`} />
                                <div className="min-w-0">
                                    <span className="font-medium text-content uppercase">{r.platform ?? platformFor(r.connection_id)}</span>
                                    <span className="text-content-muted"> · {r.status}</span>
                                    {r.message && <p className="text-content-muted mt-0.5">{r.message}</p>}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            <button type="button" onClick={onCheckStatus} disabled={polling} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-[var(--radius-button)] bg-surface-3 border border-line text-content hover:bg-content/10 disabled:opacity-50">
                {polling && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                Check status
            </button>
        </div>
    );
}

function SummaryStat({ label, value, tone }) {
    return (
        <div className="p-2 rounded-[var(--radius-button)] bg-surface border border-line">
            <div className={`text-lg font-bold tabular-nums ${tone}`}>{value}</div>
            <div className="text-[10px] uppercase text-content-muted">{label}</div>
        </div>
    );
}
