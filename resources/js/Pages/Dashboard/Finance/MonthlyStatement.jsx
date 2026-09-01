import { Link, router } from '@inertiajs/react';
import { FileBarChart, Info, ListOrdered, Paperclip, Truck, AlertTriangle } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import Card from '@/Components/Card';
import StatsCard from '@/Components/StatsCard';
import EmptyState from '@/Components/EmptyState';
import { formatDateTime, formatDateOnly } from '@/Support/formatDate';

function money(amount, currency = 'MAD') {
    return `${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

export default function MonthlyStatement({ statement, stores }) {
    const update = (patch) => {
        router.get('/dashboard/finance/statement', { month: statement.month, store_id: statement.store_id ?? '', ...patch }, { preserveState: true, replace: true });
    };

    return (
        <SaasLayout pageHeader={{
            title: 'Monthly statement',
            subtitle: 'Expense totals for the selected month',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Finance', href: '/dashboard/finance' }, { label: 'Monthly statement' }],
        }}>
            <div className="mb-6 flex flex-wrap items-center gap-3">
                <input type="month" value={statement.month} onChange={(e) => update({ month: e.target.value })}
                    className="px-3 py-2 text-sm rounded-lg bg-surface-2 border border-line text-content" />
                <select value={statement.store_id ?? ''} onChange={(e) => update({ store_id: e.target.value })}
                    className="px-3 py-2 text-sm rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All stores (organization)</option>
                    {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
            </div>

            <div className="mb-6 flex items-start gap-2 rounded-xl border border-line bg-surface-2 px-4 py-3 text-sm text-content-muted">
                <Info className="w-4 h-4 mt-0.5 flex-shrink-0 text-primary" />
                <span>Sales created and money collected are kept separate — a sale created this month but collected next month appears as a sale here and as a collection next month. No combined &quot;profit&quot; figure yet.</span>
            </div>

            <h2 className="text-sm font-semibold text-content mb-3">1–3. Sales, collections &amp; pending receivables</h2>
            <section className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                <StatsCard label="Sales created" value={money(statement.cashflow.sales_created.amount)} sublabel={`${statement.cashflow.sales_created.count} order(s)`} />
                <StatsCard label="Collections" value={money(statement.cashflow.collections.amount)} color="green" sublabel={`${statement.cashflow.collections.count} payment(s)`} />
                <StatsCard label="Pending receivables (month end)" value={money(statement.cashflow.pending_receivables_at_month_end)} color="amber" sublabel="COD not yet collected" />
                <StatsCard label="Net cash movement" value={money(statement.cashflow.net_cash_movement)} color={statement.cashflow.net_cash_movement >= 0 ? 'green' : 'red'} sublabel="In minus out, this month" />
            </section>

            <h2 className="text-sm font-semibold text-content mb-3">COD breakdown</h2>
            <section className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                <StatsCard label="COD pending (month end)" value={money(statement.cod.pending_at_month_end)} color="amber" sublabel="Not yet closed by any workflow" />
                <StatsCard label="Collected via external settlement" value={money(statement.cod.collected_via_external_settlement.net_received)} color="green" sublabel={`${statement.cod.collected_via_external_settlement.count} settlement(s) · net received`} />
                <StatsCard label="Delivery fees deducted" value={money(statement.cod.collected_via_external_settlement.delivery_fees)} color="red" sublabel="Carrier fees, this month's settlements" />
                <StatsCard label="Collected via courier deposit" value={money(statement.cod.collected_via_courier_deposit.cash_received)} color="green" sublabel={`${statement.cod.collected_via_courier_deposit.count} deposit(s) · cash received`} />
            </section>
            {(statement.cod.collected_via_external_settlement.count > 0 || statement.cod.collected_via_courier_deposit.count > 0) && (
                <Card title="COD settlement detail" subtitle="Gross COD, fees deducted and net received — never double-counted with the ledger's own cash totals above" className="mb-6">
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div className="space-y-1.5">
                            <p className="font-medium text-content">External carrier settlements</p>
                            <div className="flex justify-between"><span className="text-content-muted">Gross COD</span><span className="tabular-nums text-content">{money(statement.cod.collected_via_external_settlement.gross)}</span></div>
                            <div className="flex justify-between"><span className="text-content-muted">Delivery fees</span><span className="tabular-nums text-danger">-{money(statement.cod.collected_via_external_settlement.delivery_fees)}</span></div>
                            <div className="flex justify-between"><span className="text-content-muted">Adjustments</span><span className="tabular-nums text-content">{money(statement.cod.collected_via_external_settlement.adjustments)}</span></div>
                            <div className="flex justify-between border-t border-line pt-1.5"><span className="font-medium text-content">Net received (bank)</span><span className="tabular-nums font-semibold text-success">{money(statement.cod.collected_via_external_settlement.net_received)}</span></div>
                        </div>
                        <div className="space-y-1.5">
                            <p className="font-medium text-content">Internal courier deposits</p>
                            <div className="flex justify-between"><span className="text-content-muted">Expected COD</span><span className="tabular-nums text-content">{money(statement.cod.collected_via_courier_deposit.expected)}</span></div>
                            <div className="flex justify-between"><span className="text-content-muted">Cash received</span><span className="tabular-nums text-content">{money(statement.cod.collected_via_courier_deposit.cash_received)}</span></div>
                            <div className="flex justify-between border-t border-line pt-1.5"><span className="font-medium text-content">Difference (cash)</span><span className={`tabular-nums font-semibold ${Number(statement.cod.collected_via_courier_deposit.difference) < 0 ? 'text-danger' : 'text-success'}`}>{money(statement.cod.collected_via_courier_deposit.difference)}</span></div>
                        </div>
                    </div>
                </Card>
            )}

            <h2 className="text-sm font-semibold text-content mb-3">External carrier COD (by provider)</h2>
            <div className="mb-6 flex items-start gap-2 rounded-xl border border-line bg-surface-2 px-4 py-3 text-sm text-content-muted">
                <Info className="w-4 h-4 mt-0.5 flex-shrink-0 text-primary" />
                <span>Gross COD, expected fees and expected net are never cash — only "Actual received" (a verified bank transfer) is. Non-delivered COD and delivered-but-not-yet-paid-out COD are shown separately below, also never cash.</span>
            </div>
            <section className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-4">
                <StatsCard label="Gross COD (settled this month)" value={money(statement.external_cod.gross_cod)} sublabel="Non-cash — informational" />
                <StatsCard label="Actual received (bank)" value={money(statement.external_cod.actual_received)} color="green" sublabel="Real cash — verified transfers" />
                <StatsCard label="Expected fees" value={money(statement.external_cod.expected_fees)} color="red" sublabel="From stored fee snapshots" />
                <StatsCard label="Variance" value={money(statement.external_cod.variance)} color={Math.abs(statement.external_cod.variance) < 0.01 ? 'slate' : 'amber'} sublabel={`${statement.external_cod.disputed_count} disputed · ${statement.external_cod.partial_count} partial`} />
            </section>

            {statement.external_cod.by_provider.length > 0 && (
                <Card title="Settled this month, by provider" className="mb-4">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-xs uppercase tracking-wider text-content-muted border-b border-line">
                                <tr>
                                    <th className="px-3 py-2 text-left">Provider</th>
                                    <th className="px-3 py-2 text-right">Settlements</th>
                                    <th className="px-3 py-2 text-right">Gross COD</th>
                                    <th className="px-3 py-2 text-right">Expected fees</th>
                                    <th className="px-3 py-2 text-right">Expected net</th>
                                    <th className="px-3 py-2 text-right">Actual received</th>
                                    <th className="px-3 py-2 text-right">Variance</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {statement.external_cod.by_provider.map((row) => (
                                    <tr key={row.delivery_provider_id}>
                                        <td className="px-3 py-2 text-content">{row.provider_name}</td>
                                        <td className="px-3 py-2 text-right text-content-muted tabular-nums">{row.settlements_count}</td>
                                        <td className="px-3 py-2 text-right tabular-nums text-content">{money(row.gross_cod)}</td>
                                        <td className="px-3 py-2 text-right tabular-nums text-danger">-{money(row.expected_fees)}</td>
                                        <td className="px-3 py-2 text-right tabular-nums text-content">{money(row.expected_net)}</td>
                                        <td className="px-3 py-2 text-right tabular-nums font-semibold text-success">{money(row.actual_received)}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{Math.abs(row.variance) < 0.01 ? <span className="text-content-muted">—</span> : <span className={row.variance < 0 ? 'text-danger' : 'text-warning'}>{row.variance > 0 ? '+' : ''}{money(row.variance)}</span>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}

            <Card title="Pending delivered COD (live, not month-scoped)" subtitle="Delivered but not yet even drafted into a settlement — no bank transfer behind it yet" className="mb-6">
                {statement.external_cod.pending_periods.length === 0 ? (
                    <EmptyState icon={Truck} title="Nothing pending" description="Every delivered COD order has already been settled or has no external provider." />
                ) : (
                    <>
                        <div className="flex items-center justify-between mb-3 text-sm">
                            <span className="text-content-muted">{money(statement.external_cod.pending_delivered_unpaid_cod)} across {statement.external_cod.pending_periods.length} period(s)</span>
                            {statement.external_cod.overdue_periods_count > 0 && (
                                <span className="inline-flex items-center gap-1 text-danger font-medium"><AlertTriangle className="w-3.5 h-3.5" /> {statement.external_cod.overdue_periods_count} overdue</span>
                            )}
                        </div>
                        <div className="divide-y divide-line">
                            {statement.external_cod.pending_periods.map((p) => (
                                <div key={`${p.delivery_provider_id}-${p.period_start}`} className="flex items-center justify-between py-2.5 text-sm">
                                    <span className="text-content">
                                        {p.provider_name} <span className="text-content-muted">· {formatDateOnly(p.period_start)} → {formatDateOnly(p.period_end)} · {p.delivered_orders_count} order(s)</span>
                                    </span>
                                    <span className="flex items-center gap-3">
                                        <span className="font-semibold tabular-nums text-content">{money(p.gross_cod)}</span>
                                        <Link href="/dashboard/finance/cod-receivables" className="text-xs text-primary hover:underline">Verify</Link>
                                    </span>
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </Card>

            <h2 className="text-sm font-semibold text-content mb-3">4. Expenses</h2>
            <section className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                <StatsCard label="Total expenses" value={money(statement.totals.total_expenses.amount)} sublabel={`${statement.totals.total_expenses.count} expense(s)`} />
                <StatsCard label="Paid (by paid date)" value={money(statement.cashflow.expenses_paid.amount)} color="green" sublabel={`${statement.cashflow.expenses_paid.count} expense(s)`} />
                <StatsCard label="Unpaid" value={money(statement.totals.unpaid_expenses.amount)} color="amber" sublabel={`${statement.totals.unpaid_expenses.count} expense(s)`} />
                <StatsCard label="Cancelled" value={money(statement.totals.cancelled_expenses.amount)} color="slate" sublabel={`${statement.totals.cancelled_expenses.count} expense(s)`} />
            </section>

            <h2 className="text-sm font-semibold text-content mb-3">4b. Documentation &amp; internal justification</h2>
            <section className="mb-6">
                <p className="text-xs text-content-muted mb-3">
                    Official invoice/receipt = external legal proof. Internal cash voucher / no-invoice = internal justification only —
                    the fiscal/accountant-ready total below never mixes the two.
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-4">
                    <StatsCard label="Officially documented" value={money(statement.justification.official_documented.amount)} color="green" sublabel={`${statement.justification.official_documented.count} expense(s)`} />
                    <StatsCard label="Internal cash voucher" value={money(statement.justification.internal_cash_voucher.amount)} color="blue" sublabel={`${statement.justification.internal_cash_voucher.count} expense(s)`} />
                    <StatsCard label="Missing / no document" value={money(statement.justification.missing_no_document.amount)} color="amber" sublabel={`${statement.justification.missing_no_document.count} expense(s)`} />
                    <StatsCard label="Rejected / cancelled" value={money(statement.justification.rejected_or_cancelled.amount)} color="red" sublabel={`${statement.justification.rejected_or_cancelled.count} expense(s)`} />
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div className="rounded-xl border border-line bg-surface-2 p-4">
                        <p className="text-xs text-content-muted">Fiscal/accountant-ready total (official documents only)</p>
                        <p className="text-lg font-semibold tabular-nums text-content">{money(statement.justification.fiscal_ready_amount)}</p>
                    </div>
                    <div className="rounded-xl border border-line bg-surface-2 p-4">
                        <p className="text-xs text-content-muted">Internal-only total (vouchers + undocumented — never fiscal proof)</p>
                        <p className="text-lg font-semibold tabular-nums text-content">{money(statement.justification.internal_only_amount)}</p>
                    </div>
                    <div className="rounded-xl border border-line bg-surface-2 p-4">
                        <p className="text-xs text-content-muted">Cashflow total (real cash paid out this month, all types)</p>
                        <p className="text-lg font-semibold tabular-nums text-content">{money(statement.justification.cashflow_total)}</p>
                    </div>
                </div>
                {statement.justification.pending_owner_review.count > 0 && (
                    <p className="mt-3 text-xs text-warning">
                        {statement.justification.pending_owner_review.count} expense(s) still awaiting owner review
                        {statement.justification.needs_more_info.count > 0 ? ` · ${statement.justification.needs_more_info.count} flagged as needing more info` : ''} — see the Expenses list.
                    </p>
                )}
            </section>

            <h2 className="text-sm font-semibold text-content mb-3">5. Refunds / returns</h2>
            <section className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                <StatsCard label="Refunds this month" value={money(statement.cashflow.refunds.amount)} color="red" sublabel={`${statement.cashflow.refunds.count} transaction(s)`} />
                <BreakdownCard title="6. Cash by account" rows={statement.cashflow.by_account.map((r) => ({ ...r, amount: r.in - r.out }))} labelKey="account_name" />
            </section>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <BreakdownCard title="Expenses by category" rows={statement.by_category} labelKey="category_name" />
                <BreakdownCard title="Expenses by payment method" rows={statement.by_payment_method} labelKey="payment_method" capitalize />
                <BreakdownCard title="Expenses by vendor" rows={statement.by_vendor} labelKey="vendor_name" />
            </div>

            {statement.cashflow.by_store.length > 0 && (
                <Card title="Sales &amp; collections by store" className="mb-6">
                    <div className="space-y-2">
                        {statement.cashflow.by_store.map((row) => (
                            <div key={row.store_id} className="flex items-center justify-between text-sm">
                                <span className="text-content-muted truncate">{row.store_name ?? 'Unknown store'}</span>
                                <span className="font-semibold tabular-nums text-content flex-shrink-0 ml-2">
                                    Sales {money(row.sales_created)} <span className="text-content-muted font-normal">· Collected {money(row.collected)}</span>
                                </span>
                            </div>
                        ))}
                    </div>
                </Card>
            )}

            <Card title="Recent transactions" subtitle="This month's ledger activity" className="mb-6">
                {statement.cashflow.recent_transactions.length === 0 ? (
                    <EmptyState icon={ListOrdered} title="No transactions this month" description="Sales, collections, expenses and adjustments will appear here." />
                ) : (
                    <div className="divide-y divide-line">
                        {statement.cashflow.recent_transactions.map((t) => (
                            <div key={t.id} className="flex items-center justify-between py-2.5 text-sm gap-3">
                                <span className="text-content-muted capitalize min-w-0 truncate">
                                    <span className="text-content-muted/80 whitespace-nowrap">{formatDateTime(t.occurred_at)}</span> · {t.type.replace(/_/g, ' ')} <span className="text-content">· {t.description ?? t.reference ?? '—'}</span>
                                </span>
                                <span className={`font-semibold tabular-nums flex-shrink-0 ml-2 ${t.direction === 'in' ? 'text-success' : t.direction === 'out' ? 'text-danger' : 'text-content'}`}>
                                    {t.direction === 'out' ? '-' : ''}{money(t.amount, t.currency)}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </Card>

            <Card title="Upcoming unpaid dues" subtitle="Not yet due, still unpaid" className="mb-6">
                {statement.upcoming_unpaid_due.length === 0 ? (
                    <EmptyState icon={FileBarChart} title="Nothing upcoming" description="No unpaid expenses with a future due date." />
                ) : (
                    <div className="divide-y divide-line">
                        {statement.upcoming_unpaid_due.map((e) => (
                            <div key={e.id} className="flex items-center justify-between py-2.5 text-sm">
                                <span className="text-content">
                                    <Link href={`/dashboard/finance/expenses/${e.id}/edit`} className="hover:underline">{e.title}</Link>{' '}
                                    <span className="text-content-muted">· due {e.due_date}</span>
                                    {e.documents_count > 0 && (
                                        <span className="inline-flex items-center gap-0.5 ml-1.5 text-xs text-content-muted" title={`${e.documents_count} document(s)`}>
                                            <Paperclip className="w-3 h-3" />{e.documents_count}
                                        </span>
                                    )}
                                </span>
                                <span className="font-semibold tabular-nums text-content">{money(e.amount, e.currency)}</span>
                            </div>
                        ))}
                    </div>
                )}
            </Card>

            <Card title="Export-ready detail" subtitle={`${statement.export_rows.length} row(s) — ready for Excel/PDF export`}>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-xs uppercase tracking-wider text-content-muted border-b border-line">
                            <tr>
                                <th className="px-3 py-2 text-left">Date</th>
                                <th className="px-3 py-2 text-left">Title</th>
                                <th className="px-3 py-2 text-left">Category</th>
                                <th className="px-3 py-2 text-left">Vendor</th>
                                <th className="px-3 py-2 text-left">Store</th>
                                <th className="px-3 py-2 text-right">Amount</th>
                                <th className="px-3 py-2 text-left">Status</th>
                                <th className="px-3 py-2 text-right">Docs</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {statement.export_rows.map((row, i) => (
                                <tr key={i}>
                                    <td className="px-3 py-2 text-content-muted">{row.date}</td>
                                    <td className="px-3 py-2 text-content">{row.title}</td>
                                    <td className="px-3 py-2 text-content-muted">{row.category ?? '—'}</td>
                                    <td className="px-3 py-2 text-content-muted">{row.vendor ?? '—'}</td>
                                    <td className="px-3 py-2 text-content-muted">{row.store ?? 'Organization'}</td>
                                    <td className="px-3 py-2 text-right tabular-nums text-content">{money(row.amount, row.currency)}</td>
                                    <td className="px-3 py-2 capitalize text-content-muted">{row.status}</td>
                                    <td className="px-3 py-2 text-right">
                                        {row.document_count > 0 ? (
                                            <Link href={`/dashboard/finance/expenses/${row.id}/edit`} className="inline-flex items-center gap-0.5 text-xs text-content-muted hover:text-content hover:underline">
                                                <Paperclip className="w-3 h-3" />{row.document_count}
                                            </Link>
                                        ) : <span className="text-content-muted">—</span>}
                                    </td>
                                </tr>
                            ))}
                            {statement.export_rows.length === 0 && (
                                <tr><td colSpan={8} className="px-3 py-8 text-center text-content-muted">No expenses this month.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </Card>
        </SaasLayout>
    );
}

function BreakdownCard({ title, rows, labelKey, capitalize }) {
    return (
        <Card title={title}>
            {rows.length === 0 ? (
                <p className="text-sm text-content-muted">No data for this month.</p>
            ) : (
                <div className="space-y-2">
                    {rows.map((row, i) => (
                        <div key={i} className="flex items-center justify-between text-sm">
                            <span className={`text-content-muted truncate ${capitalize ? 'capitalize' : ''}`}>{(row[labelKey] ?? 'Unspecified')?.toString().replace(/_/g, ' ')}</span>
                            <span className="font-semibold tabular-nums text-content flex-shrink-0 ml-2">{money(row.amount)} <span className="text-content-muted font-normal">({row.count})</span></span>
                        </div>
                    ))}
                </div>
            )}
        </Card>
    );
}
