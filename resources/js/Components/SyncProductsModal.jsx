import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { RefreshCw } from 'lucide-react';

export default function SyncProductsModal({ connections = [], onSyncCompleted, open: controlledOpen, onOpenChange }) {
    const [internalOpen, setInternalOpen] = useState(false);
    // Controlled when a parent passes `open`/`onOpenChange` (e.g. the Import
    // modal opening this one for a WooCommerce import) — otherwise falls back
    // to its own internal state exactly as before.
    const isOpen = controlledOpen ?? internalOpen;
    const setIsOpen = onOpenChange ?? setInternalOpen;
    const [selectedIds, setSelectedIds] = useState([]);
    const [syncState, setSyncState] = useState({
        in_progress: false,
        done: false,
        progress: 0,
        status: '',
        results: {},
        elapsed: '0s'
    });

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

    // الـ Polling تتبع تقدم العملية كل ثانية ونصف
    useEffect(() => {
        let interval;
        if (syncState.in_progress) {
            interval = setInterval(() => {
                axios.get('/dashboard/products/sync/progress')
                    .then(res => {
                        setSyncState(res.data);
                        // إذا انتهت المزامنة، نحدث جدول المنتجات ف الكواليس
                        if (res.data.done) {
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
    }, [syncState.in_progress]);

    const startSync = () => {
        if (selectedIds.length === 0) {
            alert('المرجو اختيار منصة واحدة على الأقل');
            return;
        }

        setSyncState(prev => ({ ...prev, in_progress: true, status: 'Starting...' }));

        axios.post('/dashboard/products/sync/start', {
            connection_ids: selectedIds
        }).catch(err => {
            console.error("Failed to start sync:", err);
            setSyncState(prev => ({ ...prev, in_progress: false, status: 'Failed to start sync' }));
        });
    };

    return (
        <>
            {/* بوطونة المزامنة في الـ Header */}
            <button
                type="button"
                onClick={() => setIsOpen(true)}
                className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition shadow-sm"
            >
                <RefreshCw className={`w-4 h-4 ${syncState.in_progress ? 'animate-spin' : ''}`} />
                Sync Platforms
            </button>

            {/* الـ Modal */}
            {isOpen && (
                <div className="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
                    <div className="bg-surface-2 border border-line rounded-xl shadow-xl max-w-lg w-full overflow-hidden text-left">
                        
                        {/* الـ Header ديال المودال */}
                        <div className="p-4 border-b border-line flex justify-between items-center bg-surface">
                            <h3 className="font-semibold text-content">Platform Sync</h3>
                            <button type="button" onClick={() => setIsOpen(false)} className="text-content-muted hover:text-content-muted">✕</button>
                        </div>

                        {/* محتوى المودال */}
                        <div className="p-6 space-y-4">
                            {!syncState.in_progress && !syncState.done ? (
                                <>
                                    <p className="text-sm text-content-muted">Select connected integrations to pull sync updates:</p>
                                    <div className="space-y-2">
                                        {connections.map(conn => (
                                            // أصلحنا الخطأ هنا: أضفنا الـ key الفريد لكل عنصر ف القائمة
                                            <div key={conn.id} className="flex items-center justify-between p-3 border border-line bg-surface rounded-lg">
                                                <div className="flex items-center gap-3">
                                                    <input 
                                                        type="checkbox" 
                                                        checked={selectedIds.includes(conn.id)}
                                                        onChange={() => {
                                                            setSelectedIds(prev => 
                                                                prev.includes(conn.id) ? prev.filter(id => id !== conn.id) : [...prev, conn.id]
                                                            );
                                                        }}
                                                        className="rounded bg-surface-3 border-line text-indigo-600 focus:ring-indigo-500"
                                                    />
                                                    <div>
                                                        <span className="font-medium text-content">{conn.label}</span>
                                                        <p className="text-xs text-content-muted">Products: {conn.synced_products_count || 0} | Synced: {conn.last_synced_at || 'never'}</p>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>

                                    <button 
                                        type="button"
                                        onClick={startSync}
                                        className="w-full mt-4 bg-indigo-600 hover:bg-indigo-500 text-white py-2 rounded-lg font-medium transition"
                                    >
                                        Start Sync Pipeline
                                    </button>
                                </>
                            ) : (
                                /* واجهة الـ Progress بار والنتائج */
                                <div className="space-y-4 py-2">
                                    <div className="flex justify-between text-sm font-medium text-content-muted">
                                        <span>{syncState.status}</span>
                                        <span className="text-content-muted">{syncState.elapsed}</span>
                                    </div>

                                    <div className="w-full bg-surface-3 rounded-full h-2 overflow-hidden">
                                        <div 
                                            className="bg-indigo-500 h-full transition-all duration-500 rounded-full"
                                            style={{ width: `${syncState.progress}%` }}
                                        ></div>
                                    </div>

                                    {/* عرض الإحصائيات لكل منصة */}
                                    {syncState.results && Object.keys(syncState.results).length > 0 && (
                                        <div className="mt-4 border border-line rounded-lg divide-y divide-line bg-surface text-sm">
                                            {Object.entries(syncState.results).map(([platform, res]) => (
                                                <div key={platform} className="p-3 flex justify-between items-center">
                                                    <span className="font-medium text-content-muted">{res.label}</span>
                                                    {res.error ? (
                                                        <span className="text-red-600 dark:text-red-400 text-xs">{res.error}</span>
                                                    ) : (
                                                        <div className="space-x-2 text-xs flex gap-2">
                                                            <span className="text-emerald-600 dark:text-emerald-400">🆕 {res.created}</span>
                                                            <span className="text-indigo-600 dark:text-indigo-400">🔄 {res.updated}</span>
                                                            {res.failed > 0 && <span className="text-red-600 dark:text-red-400">⚠️ {res.failed}</span>}
                                                        </div>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    {syncState.done && (
                                        <button 
                                            type="button"
                                            onClick={() => {
                                                // تصفير الحالة والـ Progress عند الإغلاق لتستعد للمرة القادمة
                                                setSyncState({ in_progress: false, done: false, progress: 0, status: '', results: {}, elapsed: '0s' });
                                                setIsOpen(false);
                                            }}
                                            className="w-full mt-4 bg-surface-3 hover:bg-content/10 text-content py-2 rounded-lg font-medium transition"
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