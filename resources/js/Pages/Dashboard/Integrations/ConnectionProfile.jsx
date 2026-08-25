import { useState, useEffect, useRef } from 'react';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    ShieldCheck, RefreshCw, RotateCcw, Archive, AlertTriangle, Loader2,
    ArrowLeft, Unlink, ExternalLink, X, Pencil,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatusBadge from '@/Components/StatusBadge';

const PLATFORM_LABELS = { shopify: 'Shopify', woocommerce: 'WooCommerce', youcan: 'YouCan' };

export default function ConnectionProfile({ connection, store, auth, syncStatus }) {
    const [state, setState] = useState({ connection, auth, syncStatus });
    const [modal, setModal] = useState(null); // 'reset-mappings' | 'reset-product-cursor' | 'reset-order-cursor' | 'archive' | 'disconnect' | null
    const [busy, setBusy] = useState(null); // key of the button currently running
    const [feedback, setFeedback] = useState(null); // { tone: 'success'|'error', message }
    const [orderBatchId, setOrderBatchId] = useState(state.syncStatus.current_order_batch?.batch_id ?? null);
    const [orderBatchAgeMs, setOrderBatchAgeMs] = useState(0);
    const pollRef = useRef(null);
    const pollStartRef = useRef(null);

    const platformLabel = PLATFORM_LABELS[state.connection.platform] ?? state.connection.platform;

    const refresh = () => {
        router.reload({
            only: ['connection', 'auth', 'syncStatus'],
            onSuccess: (page) => setState({
                connection: page.props.connection,
                auth: page.props.auth,
                syncStatus: page.props.syncStatus,
            }),
        });
    };

    const base = `/dashboard/integrations/connections/${state.connection.id}`;

    // Polls the order-sync batch every 3s while queued/running, so the
    // "Sync orders now"/"Full order resync" buttons show real progress
    // instead of just a one-shot "queued" toast — stops once the batch
    // reaches a terminal status, or after a queue-worker hint kicks in.
    useEffect(() => {
        if (! orderBatchId) return;

        pollStartRef.current = Date.now();
        const tick = () => {
            axios.get(`${base}/sync-orders/batches/${orderBatchId}`)
                .then((res) => {
                    setState((s) => ({ ...s, syncStatus: { ...s.syncStatus, current_order_batch: res.data } }));
                    setOrderBatchAgeMs(Date.now() - pollStartRef.current);
                    if (res.data.status === 'completed' || res.data.status === 'failed') {
                        clearInterval(pollRef.current);
                        pollRef.current = null;
                    }
                })
                .catch(() => { clearInterval(pollRef.current); pollRef.current = null; });
        };

        tick();
        pollRef.current = setInterval(tick, 3000);

        return () => { if (pollRef.current) clearInterval(pollRef.current); };
    }, [orderBatchId]);

    const run = (key, url, payload = {}) => {
        setBusy(key);
        setFeedback(null);
        return axios.post(url, payload)
            .then((res) => {
                setFeedback({ tone: 'success', message: res.data.message ?? 'Done.' });
                if (res.data.batch_id) {
                    setOrderBatchAgeMs(0);
                    setOrderBatchId(res.data.batch_id);
                } else {
                    refresh();
                }
                return res.data;
            })
            .catch((err) => {
                setFeedback({ tone: 'error', message: err.response?.data?.message ?? 'Request failed.' });
                throw err;
            })
            .finally(() => setBusy(null));
    };

    return (
        <SaasLayout pageHeader={{
            title: state.connection.label || platformLabel,
            subtitle: `${platformLabel} connection profile`,
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Integrations', href: '/dashboard/integrations' },
                { label: state.connection.label || platformLabel },
            ],
            actions: (
                <Link
                    href="/dashboard/integrations"
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3"
                >
                    <ArrowLeft className="w-4 h-4" /> Back to integrations
                </Link>
            ),
        }}>
            {feedback && (
                <div className={`mb-4 px-4 py-2.5 rounded-lg border text-sm ${feedback.tone === 'success'
                    ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-700 dark:text-emerald-300'
                    : 'bg-red-500/10 border-red-500/30 text-red-700 dark:text-red-300'}`}
                >
                    {feedback.message}
                </div>
            )}

            <p className="mb-4 text-xs text-content-muted bg-surface-2 border border-line rounded-lg p-2.5">
                Reset actions keep the connection credentials. Disconnect removes or disables the connection.
            </p>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {/* A. Authentication */}
                <SectionCard title="Authentication" icon={ShieldCheck}>
                    <dl className="space-y-2 text-sm">
                        <Row label="Platform" value={platformLabel} />
                        <Row label="Connection name" value={state.connection.label || '—'} />
                        <Row label="Store / domain" value={state.connection.shop_domain || state.connection.api_url || '—'} />
                        <Row label="Auth status" value={<StatusBadge type="auth" status={state.auth.status} label={authLabel(state.auth.status)} />} />
                        <Row label="Last auth check" value={state.auth.checked_at ? new Date(state.auth.checked_at).toLocaleString() : 'Never'} />
                        {state.auth.error && (
                            <Row label="Last auth error" value={<span className="text-red-600 dark:text-red-400">{state.auth.error}</span>} />
                        )}
                        <Row label="Store" value={store?.name ?? '—'} />
                    </dl>

                    {state.auth.warning && (
                        <p className="mt-3 text-xs text-amber-700 dark:text-amber-300 bg-amber-500/10 border border-amber-500/30 rounded-lg p-2.5">
                            {state.auth.warning}
                        </p>
                    )}

                    {state.auth.capabilities && (
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            <CapabilityChip label="Products" status={state.auth.capabilities.products_read} message={state.auth.capability_messages?.products_read} />
                            <CapabilityChip label="Orders" status={state.auth.capabilities.orders_read} message={state.auth.capability_messages?.orders_read} />
                            <CapabilityChip label="Inventory" status={state.auth.capabilities.inventory_locations} message={state.auth.capability_messages?.inventory_locations} />
                        </div>
                    )}

                    <div className="mt-4 flex flex-wrap items-center gap-2">
                        <ActionButton
                            label="Test connection"
                            icon={ShieldCheck}
                            busy={busy === 'test'}
                            onClick={() => run('test', `${base}/test`)}
                        />
                        <Link
                            href={`/dashboard/integrations/${state.connection.platform}`}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10"
                        >
                            <Pencil className="w-3.5 h-3.5" /> Reconnect / Edit credentials
                        </Link>
                    </div>
                </SectionCard>

                {/* B. Sync Status */}
                <SectionCard title="Sync Status" icon={RefreshCw}>
                    <dl className="space-y-2 text-sm">
                        <Row label="Last product sync" value={fmtDate(state.syncStatus.last_product_sync_at)} />
                        <Row label="Last order sync" value={fmtDate(state.syncStatus.last_order_sync_at)} />
                        <Row label="Product mappings" value={state.syncStatus.product_mappings_count} />
                        <Row label="Variant mappings" value={state.syncStatus.variant_mappings_count} />
                        <Row label="Imported orders" value={state.syncStatus.imported_orders_count} />
                        <Row label="Last sync status" value={<StatusBadge type="sync" status={state.syncStatus.last_sync_status === 'ok' ? 'synced' : state.syncStatus.last_sync_status === 'error' ? 'error' : 'pending'} label={syncStatusLabel(state.syncStatus.last_sync_status)} />} />
                        {state.syncStatus.last_sync_error && (
                            <Row label="Last sync error" value={<span className="text-red-600 dark:text-red-400">{state.syncStatus.last_sync_error}</span>} />
                        )}
                    </dl>

                    {state.syncStatus.current_batch && (
                        <div className="mt-3 p-2.5 rounded-lg bg-surface border border-line text-xs">
                            <span className="font-medium text-content">Current sync batch: </span>
                            <span className="text-content-muted">
                                {state.syncStatus.current_batch.status} ({state.syncStatus.current_batch.succeeded_count}/{state.syncStatus.current_batch.total_count} succeeded
                                {state.syncStatus.current_batch.failed_count > 0 ? `, ${state.syncStatus.current_batch.failed_count} failed` : ''})
                            </span>
                        </div>
                    )}

                    {state.syncStatus.current_order_batch && (
                        <div className="mt-3 p-2.5 rounded-lg bg-surface border border-line text-xs space-y-1">
                            <div>
                                <span className="font-medium text-content">Current order sync batch: </span>
                                <span className="text-content-muted">
                                    {state.syncStatus.current_order_batch.status}
                                    {' — '}{state.syncStatus.current_order_batch.imported_count ?? 0} imported,{' '}
                                    {state.syncStatus.current_order_batch.updated_count ?? 0} updated,{' '}
                                    {state.syncStatus.current_order_batch.skipped_count ?? 0} skipped
                                    {(state.syncStatus.current_order_batch.failed_count ?? 0) > 0
                                        ? `, ${state.syncStatus.current_order_batch.failed_count} failed` : ''}
                                </span>
                            </div>
                            {state.syncStatus.current_order_batch.last_error && (
                                <div className="text-red-600 dark:text-red-400">{state.syncStatus.current_order_batch.last_error}</div>
                            )}
                            {['queued', 'running'].includes(state.syncStatus.current_order_batch.status) && orderBatchAgeMs > 15000 && (
                                <div className="text-amber-700 dark:text-amber-300">
                                    Order sync is queued. Make sure <code className="font-mono">php artisan queue:work</code> is running.
                                </div>
                            )}
                        </div>
                    )}
                </SectionCard>

                {/* C. Sync Actions */}
                <SectionCard title="Sync Actions" icon={RefreshCw}>
                    <p className="text-xs text-content-muted mb-3">Both sync actions run in the background and return immediately — this page polls their progress.</p>
                    <div className="flex flex-wrap gap-2">
                        <ActionButton label="Sync products now" busy={busy === 'sync-products'} onClick={() => run('sync-products', `${base}/sync-products`)} />
                        <ActionButton label="Sync orders now" busy={busy === 'sync-orders'} onClick={() => run('sync-orders', `${base}/sync-orders`)} />
                        <ActionButton label="Full product resync" variant="secondary" busy={busy === 'queue-products'} onClick={() => run('queue-products', `${base}/sync-products/queue`)} />
                        <ActionButton label="Full order resync" variant="secondary" busy={busy === 'queue-orders'} onClick={() => run('queue-orders', `${base}/sync-orders/queue`)} />
                    </div>
                </SectionCard>

                {/* D. Reset Actions */}
                <SectionCard title="Reset Actions" icon={RotateCcw}>
                    <div className="flex flex-wrap gap-2">
                        <ActionButton label="Reset product mappings" variant="warning" onClick={() => setModal('reset-mappings')} />
                        <ActionButton label="Reset product sync cursor" variant="warning" busy={busy === 'reset-product-cursor'} onClick={() => run('reset-product-cursor', `${base}/reset-product-cursor`)} />
                        <ActionButton label="Reset order sync cursor" variant="warning" onClick={() => setModal('reset-order-cursor')} />
                        <ActionButton label="Archive imported products" variant="warning" icon={Archive} onClick={() => setModal('archive')} />
                        <Link
                            href={`/dashboard/products?connection_id=${state.connection.id}&has_listing=1`}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10"
                        >
                            <ExternalLink className="w-3.5 h-3.5" /> Open product cleanup
                        </Link>
                    </div>
                </SectionCard>
            </div>

            {/* E. Dangerous */}
            <div className="mt-4">
                <SectionCard title="Danger Zone" icon={AlertTriangle} danger>
                    <p className="text-sm text-content-muted mb-3">
                        Disconnecting removes this connection's saved credentials and marks it disconnected. It does <strong>not</strong> delete products, mappings, orders, or inventory — use "Reset product mappings" or "Archive imported products" above for that.
                    </p>
                    <button
                        type="button"
                        onClick={() => setModal('disconnect')}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-red-600 text-white hover:bg-red-500"
                    >
                        <Unlink className="w-4 h-4" /> Disconnect connection
                    </button>
                </SectionCard>
            </div>

            {modal === 'reset-mappings' && (
                <ConfirmModal
                    title="Reset product mappings"
                    danger={false}
                    warning="Resetting product mappings keeps your products and credentials, but removes the external product links for this connection. Future sync may reattach by safe SKU matching or import new products."
                    confirmLabel="Reset mappings"
                    busy={busy === 'reset-mappings'}
                    onClose={() => setModal(null)}
                    onConfirm={() => run('reset-mappings', `${base}/reset-product-mappings`).then(() => setModal(null))}
                />
            )}

            {modal === 'reset-order-cursor' && (
                <ConfirmModal
                    title="Reset order sync cursor"
                    danger={false}
                    warning="Resetting the order cursor may re-scan old platform orders. Existing orders should update, not duplicate."
                    confirmLabel="Reset cursor"
                    busy={busy === 'reset-order-cursor'}
                    onClose={() => setModal(null)}
                    onConfirm={() => run('reset-order-cursor', `${base}/reset-order-cursor`).then(() => setModal(null))}
                />
            )}

            {modal === 'archive' && (
                <ConfirmModal
                    title="Archive imported products"
                    danger={false}
                    warning="Archives every product imported from this connection. Order history, returns, and inventory ledger are kept — this never purges anything."
                    confirmLabel="Archive products"
                    busy={busy === 'archive'}
                    onClose={() => setModal(null)}
                    onConfirm={() => run('archive', `${base}/archive-imported-products`).then(() => setModal(null))}
                />
            )}

            {modal === 'disconnect' && (
                <DisconnectModal
                    busy={busy === 'disconnect'}
                    onClose={() => setModal(null)}
                    onConfirm={() => run('disconnect', `${base}/disconnect`, { confirmation: 'DISCONNECT' }).then(() => setModal(null))}
                />
            )}
        </SaasLayout>
    );
}

function authLabel(status) {
    return { connected: 'Connected', needs_setup: 'Needs setup', error: 'Error' }[status] ?? status;
}

function syncStatusLabel(status) {
    return { ok: 'OK', error: 'Error', never: 'Never synced' }[status] ?? status;
}

function fmtDate(value) {
    return value ? new Date(value).toLocaleString() : 'Never';
}

function Row({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="text-content-muted">{label}</dt>
            <dd className="text-content font-medium text-right">{value}</dd>
        </div>
    );
}

const CAPABILITY_STYLES = {
    ok: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    error: 'bg-red-500/15 text-red-700 dark:text-red-300',
    skipped: 'bg-slate-500/15 text-content-muted',
    unknown: 'bg-slate-500/15 text-content-muted',
};

const CAPABILITY_LABELS = { ok: 'OK', error: 'Error', skipped: 'Not tested', unknown: 'Unknown' };

/** One capability pill (Products/Orders/Inventory) — separate from the overall Auth badge on purpose (requirement: a single failing endpoint must never read as "the whole connection is broken"). */
function CapabilityChip({ label, status, message }) {
    const tone = CAPABILITY_STYLES[status] ?? CAPABILITY_STYLES.unknown;

    return (
        <span
            className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium ${tone}`}
            title={message || undefined}
        >
            {label}: {CAPABILITY_LABELS[status] ?? status}
        </span>
    );
}

function SectionCard({ title, icon: Icon, danger = false, children }) {
    return (
        <div className={`bg-surface-2 border rounded-xl p-4 ${danger ? 'border-red-500/30' : 'border-line'}`}>
            <div className="flex items-center gap-2 mb-3">
                <Icon className={`w-4 h-4 ${danger ? 'text-red-600 dark:text-red-400' : 'text-content-muted'}`} />
                <h3 className={`text-sm font-semibold ${danger ? 'text-red-700 dark:text-red-300' : 'text-content'}`}>{title}</h3>
            </div>
            {children}
        </div>
    );
}

function ActionButton({ label, icon: Icon, busy, variant = 'primary', onClick }) {
    const styles = {
        primary: 'bg-indigo-600 text-white hover:bg-indigo-500',
        secondary: 'bg-surface-3 border border-line text-content hover:bg-content/10',
        warning: 'bg-amber-500/10 border border-amber-500/30 text-amber-700 dark:text-amber-300 hover:bg-amber-500/20',
    };

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={busy}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg disabled:opacity-50 ${styles[variant]}`}
        >
            {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : Icon && <Icon className="w-3.5 h-3.5" />}
            {label}
        </button>
    );
}

function ConfirmModal({ title, warning, confirmLabel, busy, onClose, onConfirm }) {
    return (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
            <div className="bg-surface-2 border border-line rounded-xl shadow-xl max-w-md w-full overflow-hidden text-left">
                <div className="p-4 border-b border-line flex justify-between items-center bg-surface">
                    <h3 className="font-semibold text-content">{title}</h3>
                    <button type="button" onClick={onClose} className="text-content-muted hover:text-content"><X className="w-4 h-4" /></button>
                </div>
                <div className="p-4 space-y-3">
                    <p className="text-xs text-amber-700 dark:text-amber-300 bg-amber-500/10 border border-amber-500/30 rounded-lg p-2.5">
                        {warning}
                    </p>
                </div>
                <div className="p-4 border-t border-line flex items-center justify-end gap-2 bg-surface">
                    <button type="button" onClick={onClose} className="px-3 py-2 text-sm font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10">Cancel</button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={busy}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {busy && <Loader2 className="w-4 h-4 animate-spin" />} {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}

function DisconnectModal({ busy, onClose, onConfirm }) {
    const [confirmText, setConfirmText] = useState('');

    return (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
            <div className="bg-surface-2 border border-line rounded-xl shadow-xl max-w-md w-full overflow-hidden text-left">
                <div className="p-4 border-b border-line flex justify-between items-center bg-red-500/10">
                    <h3 className="font-semibold text-content">Disconnect connection</h3>
                    <button type="button" onClick={onClose} className="text-content-muted hover:text-content"><X className="w-4 h-4" /></button>
                </div>
                <div className="p-4 space-y-3">
                    <p className="text-xs text-red-700 dark:text-red-300 bg-red-500/10 border border-red-500/30 rounded-lg p-2.5 flex items-start gap-2">
                        <AlertTriangle className="w-4 h-4 flex-shrink-0 mt-0.5" />
                        This removes the saved credentials for this connection and marks it disconnected. This is different from a reset — mappings, products, and orders are kept, but you will need to reconnect (re-enter credentials) before syncing again.
                    </p>
                    <label className="block text-sm text-content-muted">
                        Type <span className="font-mono font-semibold text-content">DISCONNECT</span> to confirm
                        <input
                            type="text"
                            value={confirmText}
                            onChange={(e) => setConfirmText(e.target.value)}
                            className="mt-1 w-full px-3 py-2 text-sm rounded-lg bg-surface border border-line text-content font-mono"
                            placeholder="DISCONNECT"
                        />
                    </label>
                </div>
                <div className="p-4 border-t border-line flex items-center justify-end gap-2 bg-surface">
                    <button type="button" onClick={onClose} className="px-3 py-2 text-sm font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10">Cancel</button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={busy || confirmText !== 'DISCONNECT'}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-red-600 text-white hover:bg-red-500 disabled:opacity-50"
                    >
                        {busy && <Loader2 className="w-4 h-4 animate-spin" />} Disconnect
                    </button>
                </div>
            </div>
        </div>
    );
}
