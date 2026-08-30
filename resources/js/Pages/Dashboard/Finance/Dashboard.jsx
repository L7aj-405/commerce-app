import { Link } from '@inertiajs/react';
import {
    Wallet, CheckCircle2, Clock, AlertTriangle, RefreshCw, Tag, CreditCard, Receipt, Info,
    TrendingUp, HandCoins, Undo2, ArrowLeftRight, ListOrdered,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatsCard from '@/Components/StatsCard';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import { formatDateTime } from '@/Support/formatDate';

function money(amount, currency = 'MAD') {
    return `${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

export default function Dashboard({ period, cards, top_category, cash_out_by_method, upcoming_recurring, recent_expenses, recent_transactions }) {
    return (
        <SaasLayout pageHeader={{
            title: 'Finance Dashboard',
            subtitle: `Cashflow overview · ${period.from} to ${period.to}`,
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Finance' }],
            actions: (
                <Link href="/dashboard/finance/expenses/create" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-primary text-white hover:bg-primary-strong transition">
                    Record expense
                </Link>
            ),
        }}>
            <div className="mb-6 flex items-start gap-2 rounded-xl border border-line bg-surface-2 px-4 py-3 text-sm text-content-muted">
                <Info className="w-4 h-4 mt-0.5 flex-shrink-0 text-primary" />
                <span>Sales created, cash collected, pending receivables and expenses are tracked separately below — no combined &quot;profit&quot; figure yet (that needs product cost/COGS, coming later).</span>
            </div>

            <section className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-4">
                <StatsCard label="Sales created" value={money(cards.sales_created)} icon={TrendingUp} color="primary" sublabel="Orders placed this month" />
                <StatsCard label="Money collected" value={money(cards.money_collected)} icon={CheckCircle2} color="green" sublabel="Actually received this month" />
                <StatsCard label="Pending COD" value={money(cards.cod_pending)} icon={HandCoins} color="amber" sublabel="Not yet collected" />
                <StatsCard label="Net cash movement" value={money(cards.net_cash_movement)} icon={ArrowLeftRight} color={cards.net_cash_movement >= 0 ? 'green' : 'red'} sublabel="In minus out, this month" />
            </section>

            <section className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                <StatsCard label="Expenses this month" value={money(cards.expenses_this_month)} icon={Wallet} color="primary" />
                <StatsCard label="Paid this month" value={money(cards.paid_this_month)} icon={CheckCircle2} color="green" />
                <StatsCard label="Unpaid expenses" value={money(cards.unpaid_total)} icon={Clock} color="amber" sublabel={`${cards.unpaid_count} expense(s)`} />
                <StatsCard label="Refunds this month" value={money(cards.refunds_this_month)} icon={Undo2} color="red" />
            </section>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 space-y-6">
                    <Card title="Recent transactions" actions={<Link href="/dashboard/finance/transactions" className="text-xs font-medium text-primary hover:underline">View ledger</Link>}>
                        {recent_transactions.length === 0 ? (
                            <EmptyState icon={ListOrdered} title="No transactions yet" description="Sales, collections and expenses will appear here as they happen." />
                        ) : (
                            <div className="divide-y divide-line">
                                {recent_transactions.map((t) => (
                                    <div key={t.id} className="flex items-center justify-between gap-3 py-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-content truncate">{t.description ?? t.reference ?? t.type.replace(/_/g, ' ')}</p>
                                            <p className="text-xs text-content-muted capitalize">{t.type.replace(/_/g, ' ')} · {t.account?.name ?? 'No account'} · {formatDateTime(t.occurred_at)}</p>
                                        </div>
                                        <span className={`text-sm font-semibold tabular-nums flex-shrink-0 ${t.direction === 'in' ? 'text-success' : t.direction === 'out' ? 'text-danger' : 'text-content'}`}>
                                            {t.direction === 'out' ? '-' : ''}{money(t.amount, t.currency)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Card>

                    <Card title="Recent expenses" actions={<Link href="/dashboard/finance/expenses" className="text-xs font-medium text-primary hover:underline">View all</Link>}>
                        {recent_expenses.length === 0 ? (
                            <EmptyState icon={Receipt} title="No expenses recorded yet" description="Record your first business expense to start tracking cash out." />
                        ) : (
                            <div className="divide-y divide-line">
                                {recent_expenses.map((e) => (
                                    <div key={e.id} className="flex items-center justify-between gap-3 py-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-content truncate">{e.title}</p>
                                            <p className="text-xs text-content-muted">{e.category?.name ?? '—'} · {e.expense_date}</p>
                                        </div>
                                        <div className="flex items-center gap-3 flex-shrink-0">
                                            <StatusBadge status={e.status} type="payment" />
                                            <span className="text-sm font-semibold text-content tabular-nums">{money(e.amount, e.currency)}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Card>

                    <Card title="Cash out by payment method">
                        {cash_out_by_method.length === 0 ? (
                            <p className="text-sm text-content-muted">No paid expenses this month yet.</p>
                        ) : (
                            <div className="space-y-2">
                                {cash_out_by_method.map((row) => (
                                    <div key={row.payment_method} className="flex items-center justify-between text-sm">
                                        <span className="flex items-center gap-2 text-content-muted capitalize"><CreditCard className="w-4 h-4" /> {row.payment_method?.replace(/_/g, ' ')}</span>
                                        <span className="font-semibold text-content tabular-nums">{money(row.total)} <span className="text-content-muted font-normal">({row.count})</span></span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card title="Top expense category" subtitle="This month">
                        {top_category ? (
                            <div className="flex items-center justify-between">
                                <span className="flex items-center gap-2 text-sm font-medium text-content"><Tag className="w-4 h-4 text-primary" /> {top_category.category?.name}</span>
                                <span className="text-sm font-semibold text-content tabular-nums">{money(top_category.total)}</span>
                            </div>
                        ) : (
                            <p className="text-sm text-content-muted">No expenses recorded this month.</p>
                        )}
                    </Card>

                    <Card
                        title="Upcoming recurring payments"
                        subtitle={`${cards.upcoming_recurring_count} due within 30 days`}
                        actions={<Link href="/dashboard/finance/recurring" className="text-xs font-medium text-primary hover:underline">Manage</Link>}
                    >
                        {upcoming_recurring.length === 0 ? (
                            <EmptyState icon={RefreshCw} title="No upcoming subscriptions" description="Recurring expenses due within 30 days will show up here." />
                        ) : (
                            <div className="divide-y divide-line">
                                {upcoming_recurring.map((r) => (
                                    <div key={r.id} className="flex items-center justify-between gap-3 py-2.5">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-content truncate">{r.title}</p>
                                            <p className="text-xs text-content-muted">Due {r.next_due_at}</p>
                                        </div>
                                        <span className="text-sm font-semibold text-content tabular-nums flex-shrink-0">{money(r.amount, r.currency)}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Card>

                    <Card title="COD receivables" subtitle="Pending collection" actions={<Link href="/dashboard/finance/cod-receivables" className="text-xs font-medium text-primary hover:underline">Manage</Link>}>
                        <div className="flex items-center justify-between">
                            <span className="flex items-center gap-2 text-sm font-medium text-content"><HandCoins className="w-4 h-4 text-warning" /> Outstanding</span>
                            <span className="text-sm font-semibold text-content tabular-nums">{money(cards.cod_pending)}</span>
                        </div>
                    </Card>
                </div>
            </div>
        </SaasLayout>
    );
}
