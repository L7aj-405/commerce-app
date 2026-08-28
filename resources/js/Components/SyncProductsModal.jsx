import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { RefreshCw } from 'lucide-react';

const DONE_STATUSES = ['completed', 'failed', 'partial'];

export default function SyncProductsModal({ connections = [], onSyncCompleted, open: controlledOpen, onOpenChange }) {
    const [internalOpen, setInternalOpen] = useState(false);
    // Controlled when a parent passes `open`/`onOpenChange` (e.g. the Import
    // modal opening this one for a WooCommerce import) — otherwise falls back
    // to its own internal state exactly as before.
    const isOpen = controlledOpen ?? internalOpen;
    const setIsOpen = onOpenChange ?? setInternalOpen;
    const [selectedIds, setSelectedIds] = useState([]);
    const [batchId, setBatchId] = useState(null);
    const [batch, setBatch] = useState(null);
    const [starting, setStarting] = useState(false);
    const [startError, setStartError] = useState(null);

    const inProgress = batchId !== null && batch !== null && !DONE_STATUSES.includes(batch.status);
    const done = batch !== null && DONE_STATUSES.includes(batch.status);
    const progress = batch && batch.total_count > 0
        ? Math.round(((batch.succeeded_count + batch.failed_count + batch.skipped_count) / batch.total_count) * 100)
        : 0;

    // تحميل الـ Connections التلقائي عند فتح المودال
    useEffect(() => {
        if (isOpen) {
            // تعويض الـ route helper بـ المسار المباشر للـ API لتفادي ReferenceError
            axios.get('/dashboard/products/sync/connections')
                .then(res => {
                    if (Array.isArray(res.data)) {
                        setSelectedIds(res.data.map(c => c.id));
                    }
                })
                .catch(err => console.error("Failed to load connections:", err));
        }
    }, [isOpen]);

    // Sync now runs in the background (queued jobs, one per connection) — this
    // just polls the batch's status until every connection has finished.
    useEffect(() => {
        let interval;
        if (batchId !== null && inProgress) {
            interval = setInterval(() => {
                axios.get(`/dashboard/products/sync-batches/${batchId}`)
                    .then(res => {
                        setBatch(res.data);
                        if (DONE_STATUSES.includes(res.data.status)) {
                            clearInterval(interval);
                            if (onSyncCompleted) onSyncCompleted();
                        }
                    })
                    .catch(err => {
                        console.error("Polling error:", err);
                        clearInterval(interval);
                    });
            }, 1500);
        }
        return () => clearInterval(interval);
    }, [batchId, inProgress]);

    const startSync = () => {
        if (selectedIds.length === 0) {
            alert('المرجو اختيار منصة واحدة على الأقل');
            return;
        }

        setStarting(true);
        setStartError(null);

        axios.post('/dashboard/products/sync/start', {
            connection_ids: selectedIds
        }).then(res => {
            setBatchId(res.data.batch_id);
            setBatch({
                batch_id: res.data.batch_id, status: 'pending',
                total_count: selectedIds.length, succeeded_count: 0, failed_count: 0, skipped_count: 0, results: [],
            });
        }).catch(err => {
            console.error("Failed to start sync:", err);
            setStartError(err.response?.data?.error || 'Failed to start sync');
        }).finally(() => setStarting(false));
    };

    const closeLog = () => {
        setBatchId(null);
        setBatch(null);
        setStartError(null);
        setIsOpen(false);
    };

    return (
        <>
            {/* بوطونة المزامنة في الـ Header */}
            <button
                type="button"
                onClick={() => setIsOpen(true)}
                className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong transition shadow-sm"
            >
                <RefreshCw className={`w-4 h-4 ${inProgress ? 'animate-spin' : ''}`} />
                Sync Platforms
            </button>

            {/* الـ Modal */}
            {isOpen && (
                <div className="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
                    <div className="bg-surface-2 border border-line rounded-[var(--radius-card)] shadow-xl max-w-lg w-full overflow-hidden text-left">

                        {/* الـ Header ديال المودال */}
                        <div className="p-4 border-b border-line flex justify-between items-center bg-surface">
                            <h3 className="font-semibold text-content">Platform Sync</h3>
                            <button type="button" onClick={() => setIsOpen(false)} className="text-content-muted hover:text-content-muted">✕</button>
                        </div>

                        {/* محتوى المودال */}
                        <div className="p-6 space-y-4">
                            {!inProgress && !done ? (
                                <>
                                    <p className="text-xs text-primary bg-primary-soft border border-primary/30 rounded-[var(--radius-button)] p-2.5">
                                        Sync imports updates <strong>from</strong> the selected platform(s) <strong>into</strong> SaaS, in the background — this queues one job per platform and returns immediately. To send a SaaS product <strong>to</strong> a platform instead, use "Publish" on that product.
                                    </p>
                                    <p className="text-sm text-content-muted">Select connected integrations to pull sync updates:</p>
                                    <div className="space-y-2">
                                        {connections.map(conn => (
                                            // أصلحنا الخطأ هنا: أضفنا الـ key الفريد لكل عنصر ف القائمة
                                            <div key={conn.id} className="flex items-center justify-between p-3 border border-line bg-surface rounded-[var(--radius-button)]">
                                                <div className="flex items-center gap-3">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedIds.includes(conn.id)}
                                                        onChange={() => {
                                                            setSelectedIds(prev =>
                                                                prev.includes(conn.id) ? prev.filter(id => id !== conn.id) : [...prev, conn.id]
                                                            );
                                                        }}
                                                        className="rounded bg-surface-3 border-line text-primary focus:ring-primary"
                                                    />
                                                    <div>
                                                        <span className="font-medium text-content">{conn.label}</span>
                                                        <p className="text-xs text-content-muted">Products: {conn.synced_products_count || 0} | Synced: {conn.last_synced_at || 'never'}</p>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>

                                    {startError && (
                                        <p className="text-xs text-danger">{startError}</p>
                                    )}

                                    <button
                                        type="button"
                                        onClick={startSync}
                                        disabled={starting}
                                        className="w-full mt-4 bg-primary hover:bg-primary-strong text-primary-contrast py-2 rounded-[var(--radius-button)] font-medium transition disabled:opacity-60"
                                    >
                                        {starting ? 'Queuing…' : 'Queue Sync'}
                                    </button>
                                </>
                            ) : (
                                /* واجهة الـ Progress بار والنتائج */
                                <div className="space-y-4 py-2">
                                    <div className="flex justify-between text-sm font-medium text-content-muted">
                                        <span>{inProgress ? 'Sync queued — running in the background…' : 'Sync complete'}</span>
                                    </div>

                                    <div className="w-full bg-surface-3 rounded-full h-2 overflow-hidden">
                                        <div
                                            className="bg-primary h-full transition-all duration-500 rounded-full"
                                            style={{ width: `${progress}%` }}
                                        ></div>
                                    </div>

                                    {/* عرض الإحصائيات لكل منصة */}
                                    {batch?.results && batch.results.length > 0 && (
                                        <div className="mt-4 border border-line rounded-[var(--radius-button)] divide-y divide-line bg-surface text-sm">
                                            {batch.results.map((res) => (
                                                <div key={res.connection_id} className="p-3 flex justify-between items-center">
                                                    <span className="font-medium text-content-muted">{res.label}</span>
                                                    {res.status === 'failed' ? (
                                                        <span className="text-danger text-xs">{res.message || 'Failed'}</span>
                                                    ) : res.status === 'succeeded' ? (
                                                        <div className="space-x-2 text-xs flex gap-2">
                                                            <span className="text-success">🆕 {res.created}</span>
                                                            <span className="text-primary">🔄 {res.updated}</span>
                                                            {res.failed > 0 && <span className="text-danger">⚠️ {res.failed}</span>}
                                                        </div>
                                                    ) : (
                                                        <span className="text-content-muted text-xs capitalize">{res.status}…</span>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    {done && (
                                        <button
                                            type="button"
                                            onClick={closeLog}
                                            className="w-full mt-4 bg-surface-3 hover:bg-content/10 text-content py-2 rounded-[var(--radius-button)] font-medium transition"
                                        >
                                            Close Log
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
