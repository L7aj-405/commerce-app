import { Link } from '@inertiajs/react';
import { Plug, ExternalLink, ArrowRight } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';

const ICONS = {
    shopify:         { letter: 'S', bg: 'from-emerald-500 to-emerald-700' },
    woocommerce:     { letter: 'W', bg: 'from-purple-500 to-purple-700' },
    youcan:          { letter: 'Y', bg: 'from-indigo-500 to-indigo-700' },
    whatsapp:        { letter: 'W', bg: 'from-green-500 to-green-700' },
    ozon:            { letter: 'O', bg: 'from-blue-500 to-blue-700' },
    sendit:          { letter: 'S', bg: 'from-amber-500 to-amber-700' },
    amana:           { letter: 'A', bg: 'from-teal-500 to-teal-700' },
    google_sheets:   { letter: 'G', bg: 'from-emerald-500 to-emerald-700' },
    barcode_scanner: { letter: 'B', bg: 'from-slate-500 to-slate-700' },
    label_printer:   { letter: 'L', bg: 'from-slate-500 to-slate-700' },
};

const TABS = [
    {
        key: 'commerce',
        label: 'E-commerce Platforms',
        helper: 'Connect online stores such as Shopify, WooCommerce, and YouCan to sync products, orders, and stock.',
        empty: 'No e-commerce platforms available.',
    },
    {
        key: 'delivery',
        label: 'Delivery Companies',
        helper: 'Connect carriers such as Ozon Express to send packed orders, sync tracking, and manage delivery notes.',
        empty: 'No delivery companies available.',
    },
    {
        key: 'tools',
        label: 'Tools & Devices',
        helper: 'Connect helper tools and operational devices such as WhatsApp, spreadsheets, scanners, and printers.',
        empty: 'No tools or devices available.',
    },
];

export default function Index({ tab = 'commerce', can = { commerce: true, delivery: true }, commerce = [], delivery = [], tools = [] }) {
    const cardsByTab = { commerce, delivery, tools };
    const visibleTabs = TABS.filter((t) => (t.key === 'delivery' ? can.delivery : can.commerce));
    const activeTab = visibleTabs.find((t) => t.key === tab) ?? visibleTabs[0];
    const cards = cardsByTab[activeTab?.key] ?? [];

    return (
        <SaasLayout pageHeader={{
            title: 'Integrations',
            subtitle: 'Connect stores, delivery companies, and tools to automate your operations.',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Integrations' }],
        }}>
            <div className="mb-5 flex flex-wrap gap-2 border-b border-line">
                {visibleTabs.map((t) => {
                    const active = t.key === activeTab?.key;
                    return (
                        <Link
                            key={t.key}
                            href={`/dashboard/integrations?tab=${t.key}`}
                            className={`px-3 py-2.5 text-sm font-medium border-b-2 -mb-px transition ${
                                active
                                    ? 'border-primary text-content'
                                    : 'border-transparent text-content-muted hover:text-content'
                            }`}
                        >
                            {t.label}
                        </Link>
                    );
                })}
            </div>

            {activeTab && (
                <>
                    <p className="mb-5 text-sm text-content-muted">{activeTab.helper}</p>

                    {cards.length === 0 ? (
                        <EmptyState icon={Plug} title="Nothing here yet" description={activeTab.empty} />
                    ) : (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {cards.map((card) => <ProviderCard key={card.code} card={card} />)}
                        </div>
                    )}
                </>
            )}
        </SaasLayout>
    );
}

function ProviderCard({ card }) {
    const ico = ICONS[card.code] ?? { letter: card.name[0], bg: 'from-slate-500 to-slate-700' };
    const hasSyncStats = card.category === 'commerce' && (card.synced_products_count != null || card.synced_orders_count != null);

    return (
        <div className={`bg-surface-2 border border-line rounded-[var(--radius-card)] p-5 ${card.coming_soon ? 'opacity-70' : ''}`}>
            <div className="flex items-start gap-3">
                <div className={`w-10 h-10 rounded-[var(--radius-button)] bg-gradient-to-br ${ico.bg} text-white font-bold flex items-center justify-center text-sm flex-shrink-0`}>
                    {ico.letter}
                </div>
                <div className="flex-1 min-w-0">
                    <div className="flex items-start justify-between gap-2">
                        <h3 className="text-base font-semibold text-content">{card.name}</h3>
                        <StatusBadge status={card.status} type="integration_card" />
                    </div>
                    <p className="text-xs text-content-muted mt-1">{card.description}</p>

                    {card.capabilities?.length > 0 && (
                        <div className="flex flex-wrap gap-1.5 mt-2">
                            {card.capabilities.map((cap) => (
                                <span key={cap} className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-slate-500/15 text-slate-600 dark:text-slate-300">
                                    {cap}
                                </span>
                            ))}
                        </div>
                    )}

                    {hasSyncStats && (
                        <dl className="mt-3 grid grid-cols-2 gap-y-1 text-xs text-content-muted">
                            <dt>Synced products</dt>
                            <dd className="text-content-muted text-right tabular-nums">{card.synced_products_count ?? 0}</dd>
                            <dt>Synced orders</dt>
                            <dd className="text-content-muted text-right tabular-nums">{card.synced_orders_count ?? 0}</dd>
                            {card.last_sync && (
                                <>
                                    <dt>Last sync</dt>
                                    <dd className="text-content-muted text-right">{new Date(card.last_sync).toLocaleString()}</dd>
                                </>
                            )}
                        </dl>
                    )}

                    <div className="mt-4 flex flex-wrap gap-2">
                        <CardActions card={card} />
                    </div>
                </div>
            </div>
        </div>
    );
}

function CardActions({ card }) {
    if (card.coming_soon) {
        return (
            <button type="button" disabled className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-[var(--radius-button)] bg-surface-3 border border-line text-content-muted cursor-not-allowed">
                Coming soon
            </button>
        );
    }

    if (card.category === 'delivery') {
        return (
            <Link href={card.manage_url} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong transition">
                {card.is_connected ? <>Manage <ArrowRight className="w-3.5 h-3.5" /></> : <><ExternalLink className="w-3.5 h-3.5" /> Connect</>}
            </Link>
        );
    }

    if (card.is_connected) {
        return (
            <>
                <Link href={card.connect_url} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-[var(--radius-button)] bg-surface-3 border border-line text-content hover:bg-content/10 transition">
                    <Plug className="w-3.5 h-3.5" /> Configure
                </Link>
                {card.manage_url && card.manage_url !== card.connect_url && (
                    <Link href={card.manage_url} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong transition">
                        Connection profile
                    </Link>
                )}
            </>
        );
    }

    return (
        <Link href={card.connect_url} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong transition">
            <ExternalLink className="w-3.5 h-3.5" /> Connect
        </Link>
    );
}
