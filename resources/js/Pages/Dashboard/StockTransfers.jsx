import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    ArrowLeftRight, ArrowRight, Boxes, CalendarClock, Plus, Package,
    Printer, Download, Share2, Warehouse, Users, LogOut, Check,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatsCard from '@/Components/StatsCard';
import EmptyState from '@/Components/EmptyState';

const KIND_BADGE = {
    warehouse: { label: 'Warehouse', icon: Warehouse, cls: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300' },
    team:      { label: 'Team',      icon: Users,     cls: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' },
    external:  { label: 'External',  icon: LogOut,    cls: 'bg-amber-500/15 text-amber-700 dark:text-amber-300' },
};

export default function StockTransfers({ transfers, stats }) {
    const flash = usePage().props?.flash ?? {};
    const rows  = transfers?.data ?? [];

    return (
        <SaasLayout
            pageHeader={{
                title: 'Stock transfers',
                subtitle: 'Outbound movements & Bon de Sortie exit slips',
                breadcrumbs: [
                    { label: 'Dashboard', href: '/dashboard' },
                    { label: 'Stock', href: '/dashboard/stock' },
                    { label: 'Transfers' },
                ],
                actions: (
                    <Link
                        href="/dashboard/stock/transfers/create"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                    >
                        <Plus className="w-4 h-4" />
                        New transfer
                    </Link>
                ),
            }}
        >
            {flash.transfer_slip_url && (
                <div className="mb-6 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 flex flex-wrap items-center gap-3">
                    <span className="inline-flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-300">
                        <Check className="w-4 h-4" />
                        {flash.success ?? `Transfer ${flash.transfer_reference} recorded.`}
                    </span>
                    <div className="ml-auto flex items-center gap-2">
                        <SlipActions url={flash.transfer_slip_url} reference={flash.transfer_reference} />
                    </div>
                </div>
            )}

            <section className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <StatsCard label="Total transfers" value={stats.total}       icon={ArrowLeftRight} color="indigo" />
                <StatsCard label="Units moved"     value={stats.units_moved} icon={Boxes}          color="green" />
                <StatsCard label="This month"      value={stats.this_month}  icon={CalendarClock}  color="amber" />
            </section>

            {rows.length === 0 ? (
                <div className="bg-surface-2 border border-line rounded-xl">
                    <EmptyState
                        icon={Package}
                        title="No stock transfers yet"
                        description="Record an outbound movement to move stock between warehouses or hand it to a team — a Bon de Sortie is generated for each one."
                    />
                </div>
            ) : (
                <div className="bg-surface-2 border border-line rounded-xl overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-[11px] uppercase tracking-wider text-content-muted border-b border-line">
                                    <th className="px-4 py-3 font-medium">Reference</th>
                                    <th className="px-4 py-3 font-medium">Date</th>
                                    <th className="px-4 py-3 font-medium">Route</th>
                                    <th className="px-4 py-3 font-medium text-right">Items</th>
                                    <th className="px-4 py-3 font-medium text-right">Units</th>
                                    <th className="px-4 py-3 font-medium">Responsible</th>
                                    <th className="px-4 py-3 font-medium text-right">Slip</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line/60">
                                {rows.map((t) => {
                                    const badge = KIND_BADGE[t.destination_kind] ?? KIND_BADGE.warehouse;
                                    const Icon  = badge.icon;
                                    return (
                                        <tr key={t.id} className="hover:bg-surface-3/40 transition">
                                            <td className="px-4 py-3 font-mono text-xs text-content">{t.reference}</td>
                                            <td className="px-4 py-3 text-content-muted whitespace-nowrap">{t.transfer_date}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2 min-w-0">
                                                    <span className="text-content truncate">{t.source}</span>
                                                    <ArrowRight className="w-3.5 h-3.5 text-content-muted flex-shrink-0" />
                                                    <span className="text-content truncate">{t.destination}</span>
                                                    <span className={`inline-flex items-center gap-1 flex-shrink-0 px-1.5 py-0.5 rounded-full text-[10px] font-medium ${badge.cls}`}>
                                                        <Icon className="w-2.5 h-2.5" />
                                                        {badge.label}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums text-content-muted">{t.items_count}</td>
                                            <td className="px-4 py-3 text-right tabular-nums font-semibold text-content">{t.total_quantity}</td>
                                            <td className="px-4 py-3 text-content-muted">{t.responsible ?? '—'}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center justify-end gap-1">
                                                    <SlipActions url={t.slip_url} reference={t.reference} compact />
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {Array.isArray(transfers?.data) && transfers.links && transfers.links.length > 3 && (
                <Pagination links={transfers.links} />
            )}
        </SaasLayout>
    );
}

// Print (inline PDF) · Download (attachment) · Share (Web Share API or copy link).
function SlipActions({ url, reference, compact = false }) {
    const [copied, setCopied] = useState(false);
    const absolute = typeof window !== 'undefined' ? new URL(url, window.location.origin).href : url;

    const share = async () => {
        try {
            if (navigator.share) {
                await navigator.share({ title: `Bon de Sortie ${reference ?? ''}`.trim(), url: absolute });
                return;
            }
            await navigator.clipboard.writeText(absolute);
            setCopied(true);
            setTimeout(() => setCopied(false), 1800);
        } catch { /* user cancelled share — no-op */ }
    };

    const btn = 'inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface-2 text-content-muted hover:text-content hover:bg-surface-3 transition';
    const pad = compact ? 'p-1.5' : 'px-3 py-1.5 text-xs font-medium';

    return (
        <>
            <a href={url} target="_blank" rel="noopener noreferrer" className={`${btn} ${pad}`} title="Print slip" aria-label={`Print ${reference ?? 'slip'}`}>
                <Printer className="w-3.5 h-3.5" />{! compact && 'Print'}
            </a>
            <a href={`${url}?download=1`} className={`${btn} ${pad}`} title="Download PDF" aria-label={`Download ${reference ?? 'slip'}`}>
                <Download className="w-3.5 h-3.5" />{! compact && 'Download'}
            </a>
            <button type="button" onClick={share} className={`${btn} ${pad}`} title="Share" aria-label={`Share ${reference ?? 'slip'}`}>
                {copied ? <Check className="w-3.5 h-3.5 text-emerald-500" /> : <Share2 className="w-3.5 h-3.5" />}
                {! compact && (copied ? 'Copied' : 'Share')}
            </button>
        </>
    );
}

function Pagination({ links }) {
    return (
        <nav className="flex flex-wrap items-center justify-end gap-1 mt-6">
            {links.map((l, i) => (
                <Link
                    key={i}
                    href={l.url ?? '#'}
                    preserveScroll
                    dangerouslySetInnerHTML={{ __html: l.label }}
                    className={[
                        'min-w-8 px-2.5 py-1 rounded-md text-xs transition',
                        l.active ? 'bg-indigo-600 text-white' : 'text-content-muted hover:bg-surface-3 bg-surface-2 border border-line',
                        l.url ? '' : 'opacity-40 pointer-events-none',
                    ].join(' ')}
                />
            ))}
        </nav>
    );
}
