import { useEffect, useState } from 'react';
import axios from 'axios';
import { Archive, Unlink, RotateCcw, Trash2, Loader2, AlertTriangle, X, ExternalLink } from 'lucide-react';

/**
 * Bulk cleanup action buttons + modals for imported products — archive /
 * unlink a platform mapping / reset sync state / purge. Renders only the
 * buttons and their modals (no wrapping selection bar — the caller already
 * has one); archive is a one-click confirm, unlink/reset-sync need a
 * connection picked first, purge always previews server-side blockers
 * before allowing a typed "PURGE" confirmation.
 */
export default function ProductCleanupBar({ selectedIds, connections = [], onDone, onClear }) {
    const [modal, setModal] = useState(null); // 'archive' | 'unlink' | 'reset-sync' | 'purge' | null
    const count = selectedIds.length;

    const close = () => setModal(null);
    const finish = () => {
        close();
        onClear?.();
        onDone?.();
    };

    if (count === 0) return null;

    return (
        <>
            <button type="button" onClick={() => setModal('archive')} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3">
                <Archive className="w-3.5 h-3.5" /> Archive
            </button>
            <button type="button" onClick={() => setModal('unlink')} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3">
                <Unlink className="w-3.5 h-3.5" /> Unlink from channel
            </button>
            <button type="button" onClick={() => setModal('reset-sync')} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3">
                <RotateCcw className="w-3.5 h-3.5" /> Reset sync
            </button>
            <button type="button" onClick={() => setModal('purge')} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-red-600 text-white hover:bg-red-500">
                <Trash2 className="w-3.5 h-3.5" /> Purge selected
            </button>

            {modal === 'archive' && (
                <ArchiveModal count={count} productIds={selectedIds} onClose={close} onDone={finish} />
            )}
            {(modal === 'unlink' || modal === 'reset-sync') && (
                <ConnectionActionModal
                    mode={modal}
                    count={count}
                    productIds={selectedIds}
                    connections={connections}
                    onClose={close}
                    onDone={finish}
                />
            )}
            {modal === 'purge' && (
                <PurgeModal count={count} productIds={selectedIds} onClose={close} onDone={finish} />
            )}
        </>
    );
}

function ModalShell({ title, subtitle, onClose, children, footer, danger = false }) {
    return (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
            <div className="bg-surface-2 border border-line rounded-xl shadow-xl max-w-lg w-full max-h-[85vh] overflow-hidden flex flex-col text-left">
                <div className={`p-4 border-b border-line flex justify-between items-center ${danger ? 'bg-red-500/10' : 'bg-surface'}`}>
                    <div>
                        <h3 className="font-semibold text-content">{title}</h3>
                        {subtitle && <p className="text-xs text-content-muted mt-0.5">{subtitle}</p>}
                    </div>
                    <button type="button" onClick={onClose} className="text-content-muted hover:text-content flex-shrink-0">
                        <X className="w-4 h-4" />
                    </button>
                </div>
                <div className="p-4 overflow-y-auto space-y-3">{children}</div>
                {footer && <div className="p-4 border-t border-line flex items-center justify-end gap-2 bg-surface flex-shrink-0">{footer}</div>}
            </div>
        </div>
    );
}

function ArchiveModal({ count, productIds, onClose, onDone }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const submit = () => {
        setBusy(true);
        setError(null);
        axios.post('/dashboard/products/bulk/archive', { product_ids: productIds })
            .then(() => onDone())
            .catch((err) => setError(err.response?.data?.message ?? 'Archive failed.'))
            .finally(() => setBusy(false));
    };

    return (
        <ModalShell
            title="Archive selected products"
            subtitle="Hides them from the catalog but keeps every order, return and inventory record intact."
            onClose={onClose}
            footer={(
                <>
                    <button type="button" onClick={onClose} className="px-3 py-2 text-sm font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10">Cancel</button>
                    <button type="button" onClick={submit} disabled={busy} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50">
                        {busy && <Loader2 className="w-4 h-4 animate-spin" />} Archive {count} product{count === 1 ? '' : 's'}
                    </button>
                </>
            )}
        >
            <p className="text-sm text-content-muted">
                Archived products are hidden from the default product list and cannot be published, but stay fully visible in historical orders. Use the "Archived" filter to find them again.
            </p>
            {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}
        </ModalShell>
    );
}

function ConnectionActionModal({ mode, count, productIds, connections, onClose, onDone }) {
    const [connectionId, setConnectionId] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const isReset = mode === 'reset-sync';
    const url = isReset ? '/dashboard/products/bulk/reset-sync' : '/dashboard/products/bulk/unlink-channel';

    const submit = () => {
        if (! connectionId) return;
        setBusy(true);
        setError(null);
        axios.post(url, { product_ids: productIds, platform_connection_id: connectionId })
            .then(() => onDone())
            .catch((err) => setError(err.response?.data?.message ?? 'Request failed.'))
            .finally(() => setBusy(false));
    };

    return (
        <ModalShell
            title={isReset ? 'Reset sync mapping' : 'Unlink from channel'}
            subtitle={`${count} product${count === 1 ? '' : 's'} selected`}
            onClose={onClose}
            footer={(
                <>
                    <button type="button" onClick={onClose} className="px-3 py-2 text-sm font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10">Cancel</button>
                    <button type="button" onClick={submit} disabled={busy || ! connectionId} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50">
                        {busy && <Loader2 className="w-4 h-4 animate-spin" />} {isReset ? 'Reset sync' : 'Unlink'}
                    </button>
                </>
            )}
        >
            <p className="text-xs text-amber-700 dark:text-amber-300 bg-amber-500/10 border border-amber-500/30 rounded-lg p-2.5">
                {isReset
                    ? 'Removes the mapping and sync metadata for the selected connection so these products can be imported again cleanly. The local product and its inventory are kept.'
                    : 'Unlinking removes the external mapping. Future sync may create a new local product unless SKU matching is safe.'}
            </p>
            <label className="block text-sm text-content-muted">
                Platform connection
                <select
                    value={connectionId}
                    onChange={(e) => setConnectionId(e.target.value)}
                    className="mt-1 w-full px-3 py-2 text-sm rounded-lg bg-surface border border-line text-content"
                >
                    <option value="">Choose a connection…</option>
                    {connections.map((c) => (
                        <option key={c.id} value={c.id}>{c.label ?? c.platform} ({c.platform})</option>
                    ))}
                </select>
            </label>
            {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}
        </ModalShell>
    );
}

function PurgeModal({ count, productIds, onClose, onDone }) {
    const [preview, setPreview] = useState(null); // { products, summary }
    const [loading, setLoading] = useState(true);
    const [confirmText, setConfirmText] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [results, setResults] = useState(null);

    useEffect(() => {
        axios.post('/dashboard/products/bulk/purge-preview', { product_ids: productIds })
            .then((res) => setPreview(res.data))
            .catch((err) => setError(err.response?.data?.message ?? 'Preview failed.'))
            .finally(() => setLoading(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const allowed = preview?.summary?.allowed ?? 0;
    const blocked = preview?.summary?.blocked ?? 0;
    const skippedIds = (results?.results ?? []).filter((r) => ! r.purged).map((r) => r.product_id);

    const submit = () => {
        if (confirmText !== 'PURGE') return;
        setBusy(true);
        setError(null);
        axios.post('/dashboard/products/bulk/purge', { product_ids: productIds, confirmation: 'PURGE' })
            .then((res) => setResults(res.data))
            .catch((err) => setError(err.response?.data?.message ?? 'Purge failed.'))
            .finally(() => setBusy(false));
    };

    return (
        <ModalShell
            title="Purge selected products"
            subtitle={`${count} product${count === 1 ? '' : 's'} selected`}
            onClose={results ? onDone : onClose}
            danger
            footer={results ? (
                <button type="button" onClick={onDone} className="ml-auto px-4 py-2 text-sm font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10">Close</button>
            ) : (
                <>
                    <button type="button" onClick={onClose} className="px-3 py-2 text-sm font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10">Cancel</button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={busy || confirmText !== 'PURGE' || allowed === 0}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-red-600 text-white hover:bg-red-500 disabled:opacity-50"
                    >
                        {busy && <Loader2 className="w-4 h-4 animate-spin" />} Permanently purge {allowed} product{allowed === 1 ? '' : 's'}
                    </button>
                </>
            )}
        >
            <p className="text-xs text-red-700 dark:text-red-300 bg-red-500/10 border border-red-500/30 rounded-lg p-2.5 flex items-start gap-2">
                <AlertTriangle className="w-4 h-4 flex-shrink-0 mt-0.5" />
                Purge permanently deletes each product, its variants, options and channel mappings. Any product with order, return, transfer or inventory-ledger history is refused automatically and skipped — there is no override.
            </p>

            {loading && <p className="text-sm text-content-muted">Checking which products are safe to purge…</p>}

            {results ? (
                <div className="space-y-3">
                    <p className="text-sm text-content">
                        {results.summary.purged} purged, {results.summary.skipped} skipped.
                    </p>
                    <div className="space-y-1.5 max-h-64 overflow-y-auto">
                        {results.results.map((r) => (
                            <SkippedProductRow
                                key={r.product_id}
                                product={{
                                    product_id: r.product_id,
                                    name: r.name,
                                    sku: r.sku,
                                    blockers: r.blockers,
                                    recommended_action_label: r.recommended_action_label,
                                }}
                                purged={r.purged}
                            />
                        ))}
                    </div>

                    {skippedIds.length > 0 && (
                        <SkippedFollowUpActions skippedIds={skippedIds} onDone={onDone} />
                    )}
                </div>
            ) : preview && (
                <>
                    <div className="grid grid-cols-2 gap-2 text-center">
                        <div className="p-2 rounded-lg bg-surface border border-line">
                            <div className="text-lg font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{allowed}</div>
                            <div className="text-[10px] uppercase text-content-muted">Allowed</div>
                        </div>
                        <div className="p-2 rounded-lg bg-surface border border-line">
                            <div className="text-lg font-bold tabular-nums text-red-600 dark:text-red-400">{blocked}</div>
                            <div className="text-[10px] uppercase text-content-muted">Blocked</div>
                        </div>
                    </div>

                    <div className="space-y-1.5 max-h-56 overflow-y-auto">
                        {preview.products.map((p) => (
                            <SkippedProductRow key={p.product_id} product={p} purged={p.can_purge ? null : false} />
                        ))}
                    </div>

                    {allowed > 0 && (
                        <label className="block text-sm text-content-muted">
                            Type <span className="font-mono font-semibold text-content">PURGE</span> to confirm
                            <input
                                type="text"
                                value={confirmText}
                                onChange={(e) => setConfirmText(e.target.value)}
                                className="mt-1 w-full px-3 py-2 text-sm rounded-lg bg-surface border border-line text-content font-mono"
                                placeholder="PURGE"
                            />
                        </label>
                    )}
                </>
            )}

            {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}
        </ModalShell>
    );
}

/**
 * One product row in either the pre-purge preview or the post-purge results
 * list. `purged` is `true` (purged), `false` (skipped/blocked), or `null`
 * (preview row that is allowed but hasn't run yet — shown as "purgeable").
 */
function SkippedProductRow({ product, purged }) {
    const p = product;
    const blocked = purged === false;

    return (
        <div className="p-2 rounded-lg border border-line bg-surface text-xs">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <span className="font-medium text-content">{p.name}</span>
                    <span className="text-content-muted"> · {p.sku ?? '—'}</span>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0">
                    {purged === true && <span className="text-emerald-600 dark:text-emerald-400">Purged</span>}
                    {purged === null && <span className="text-emerald-600 dark:text-emerald-400">purgeable</span>}
                    {blocked && <span className="text-content-muted">Skipped</span>}
                    <a
                        href={`/dashboard/products/${p.product_id}/edit`}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 text-content-muted hover:text-content"
                        title="View product"
                    >
                        <ExternalLink className="w-3 h-3" /> View
                    </a>
                </div>
            </div>

            {blocked && (p.blockers?.length ?? 0) > 0 && (
                <ul className="mt-1 list-disc list-inside text-red-600 dark:text-red-400">
                    {p.blockers.map((b, i) => <li key={i}>{b}</li>)}
                </ul>
            )}

            {blocked && p.recommended_action_label && (
                <p className="mt-1 text-amber-700 dark:text-amber-300">
                    Recommended: {p.recommended_action_label}
                </p>
            )}
        </div>
    );
}

/** Bulk follow-up for whatever purge just skipped — reuses the existing archive / reset-sync-all endpoints. */
function SkippedFollowUpActions({ skippedIds, onDone }) {
    const [busyAction, setBusyAction] = useState(null); // 'archive' | 'reset' | null
    const [error, setError] = useState(null);

    const archiveSkipped = () => {
        setBusyAction('archive');
        setError(null);
        axios.post('/dashboard/products/bulk/archive', { product_ids: skippedIds })
            .then(() => onDone())
            .catch((err) => setError(err.response?.data?.message ?? 'Archive failed.'))
            .finally(() => setBusyAction(null));
    };

    const resetSkipped = () => {
        setBusyAction('reset');
        setError(null);
        axios.post('/dashboard/products/bulk/reset-sync-all', { product_ids: skippedIds })
            .then(() => onDone())
            .catch((err) => setError(err.response?.data?.message ?? 'Reset sync failed.'))
            .finally(() => setBusyAction(null));
    };

    return (
        <div className="p-2.5 rounded-lg border border-amber-500/30 bg-amber-500/10 space-y-2">
            <p className="text-xs text-amber-700 dark:text-amber-300">
                {skippedIds.length} product{skippedIds.length === 1 ? '' : 's'} could not be purged. Apply a safe next action to the skipped products:
            </p>
            <div className="flex items-center gap-2 flex-wrap">
                <button
                    type="button"
                    onClick={archiveSkipped}
                    disabled={busyAction !== null}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3 disabled:opacity-50"
                >
                    {busyAction === 'archive' && <Loader2 className="w-3.5 h-3.5 animate-spin" />} Archive skipped products
                </button>
                <button
                    type="button"
                    onClick={resetSkipped}
                    disabled={busyAction !== null}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3 disabled:opacity-50"
                >
                    {busyAction === 'reset' && <Loader2 className="w-3.5 h-3.5 animate-spin" />} Reset mappings for skipped products
                </button>
            </div>
            {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}
