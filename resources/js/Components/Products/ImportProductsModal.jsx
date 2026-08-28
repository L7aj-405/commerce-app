import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Upload, FileSpreadsheet, RefreshCw, Store, PenSquare, X } from 'lucide-react';

/**
 * Small import-choice modal shown next to Add product / Sync / Add platform.
 * Reuses the existing WooCommerce pull-sync (SyncProductsModal) rather than
 * inventing a second import path; Shopify always routes to its connection
 * page per the task's explicit instruction. Never fakes a successful import.
 */
export default function ImportProductsModal({ connections = [], onImportFromWooCommerce }) {
    const [isOpen, setIsOpen] = useState(false);

    const wooConnected = connections.some((c) => c.platform === 'woocommerce');
    const shopifyConnected = connections.some((c) => c.platform === 'shopify');

    return (
        <>
            <button
                type="button"
                onClick={() => setIsOpen(true)}
                className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content transition"
            >
                <Upload className="w-4 h-4" /> Import
            </button>

            {isOpen && (
                <div className="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
                    <div className="bg-surface-2 border border-line rounded-[var(--radius-card)] shadow-xl max-w-md w-full overflow-hidden text-left">
                        <div className="p-4 border-b border-line flex justify-between items-center bg-surface">
                            <h3 className="font-semibold text-content">Import products</h3>
                            <button type="button" onClick={() => setIsOpen(false)} className="text-content-muted hover:text-content">
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <div className="p-4 space-y-2">
                            <Option
                                icon={FileSpreadsheet}
                                title="Excel / CSV"
                                subtitle="Coming next"
                                disabled
                            />

                            {wooConnected ? (
                                <OptionButton
                                    icon={RefreshCw}
                                    title="Import from WooCommerce"
                                    subtitle="Pull products from your connected store"
                                    onClick={() => {
                                        setIsOpen(false);
                                        onImportFromWooCommerce?.();
                                    }}
                                />
                            ) : (
                                <OptionLink icon={RefreshCw} title="Connect WooCommerce first" subtitle="Not connected yet" href="/dashboard/integrations/woocommerce" />
                            )}

                            <OptionLink
                                icon={Store}
                                title={shopifyConnected ? 'Manage Shopify connection' : 'Connect Shopify first'}
                                subtitle={shopifyConnected ? 'Import via Shopify webhooks' : 'Not connected yet'}
                                href="/dashboard/integrations/shopify"
                            />

                            <OptionLink icon={PenSquare} title="Add product manually" subtitle="Use the product form" href="/dashboard/products/create" />
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

function Option({ icon: Icon, title, subtitle, disabled }) {
    return (
        <div className={`flex items-center gap-3 p-3 rounded-[var(--radius-button)] border border-line ${disabled ? 'opacity-50' : ''}`}>
            <Icon className="w-4 h-4 text-content-muted flex-shrink-0" />
            <div className="min-w-0">
                <p className="text-sm font-medium text-content">{title}</p>
                <p className="text-xs text-content-muted">{subtitle}</p>
            </div>
        </div>
    );
}

function OptionButton({ icon: Icon, title, subtitle, onClick }) {
    return (
        <button type="button" onClick={onClick} className="w-full flex items-center gap-3 p-3 rounded-[var(--radius-button)] border border-line bg-surface hover:bg-surface-3 text-left transition">
            <Icon className="w-4 h-4 text-content-muted flex-shrink-0" />
            <div className="min-w-0">
                <p className="text-sm font-medium text-content">{title}</p>
                <p className="text-xs text-content-muted">{subtitle}</p>
            </div>
        </button>
    );
}

function OptionLink({ icon: Icon, title, subtitle, href }) {
    return (
        <Link href={href} className="w-full flex items-center gap-3 p-3 rounded-[var(--radius-button)] border border-line bg-surface hover:bg-surface-3 text-left transition">
            <Icon className="w-4 h-4 text-content-muted flex-shrink-0" />
            <div className="min-w-0">
                <p className="text-sm font-medium text-content">{title}</p>
                <p className="text-xs text-content-muted">{subtitle}</p>
            </div>
        </Link>
    );
}
