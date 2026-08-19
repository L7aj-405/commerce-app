import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft, Download, Mail, Phone, MapPin, CreditCard, FileText,
    Pencil, Ban, Wallet, History, Lock, ShieldCheck, X, Loader2, Receipt, Check,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';

export default function FacturesDetail({ facture, issuedByName = null, activities = [], can = {} }) {
    const currency  = facture.store?.currency ?? 'MAD';
    const items     = facture.items ?? [];
    const remaining = Math.max(0, Number(facture.total_amount) - Number(facture.amount_paid));
    const isVoid    = facture.status === 'void';
    const sentAt    = facture.sent_at ? new Date(facture.sent_at) : null;
    const [modal, setModal] = useState(null); // 'amend' | 'void' | 'pay' | null
    const [emailing, setEmailing] = useState(false);

    const emailInvoice = () => {
        if (! facture.customer_email) { alert('This invoice has no customer email.'); return; }
        const action = sentAt ? 'Resend' : 'Email';
        if (! confirm(`${action} invoice ${facture.invoice_number} to ${facture.customer_email}?`)) return;
        router.post(`/dashboard/invoices/${facture.id}/email`, {}, {
            preserveScroll: true,
            onStart:  () => setEmailing(true),
            onFinish: () => setEmailing(false),
        });
    };

    return (
        <SaasLayout pageHeader={{
            title: `Invoice ${facture.invoice_number}`,
            subtitle: facture.customer_name || 'No customer name',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Factures',  href: '/dashboard/factures' },
                { label: facture.invoice_number },
            ],
            actions: (
                <div className="flex flex-wrap items-center gap-2">
                    <Link href="/dashboard/factures" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                        <ArrowLeft className="w-4 h-4" /> Back
                    </Link>
                    <a href={`/dashboard/invoices/${facture.id}/download`} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3">
                        <Download className="w-4 h-4" /> Print / PDF
                    </a>
                    <a href={`/dashboard/invoices/${facture.id}/receipt`} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3">
                        <Receipt className="w-4 h-4" /> Receipt
                    </a>
                    {! isVoid && (
                        <button
                            type="button"
                            onClick={emailInvoice}
                            disabled={emailing}
                            title={sentAt ? `Last emailed ${sentAt.toLocaleString()}` : undefined}
                            className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3 disabled:opacity-50"
                        >
                            {emailing ? <Loader2 className="w-4 h-4 animate-spin" /> : <Mail className="w-4 h-4" />}
                            {sentAt ? 'Resend email' : 'Email'}
                        </button>
                    )}
                    {can.pay && ! isVoid && remaining > 0 && (
                        <button type="button" onClick={() => setModal('pay')} className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500">
                            <Wallet className="w-4 h-4" /> Record payment
                        </button>
                    )}
                    {can.amend && ! isVoid && (
                        <button type="button" onClick={() => setModal('amend')} className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500">
                            <Pencil className="w-4 h-4" /> Amend
                        </button>
                    )}
                    {can.void && ! isVoid && (
                        <button type="button" onClick={() => setModal('void')} className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-red-600/90 text-white hover:bg-red-600">
                            <Ban className="w-4 h-4" /> Void
                        </button>
                    )}
                </div>
            ),
        }}>
            {isVoid && (
                <div className="mb-6 flex items-center gap-2 px-4 py-3 rounded-lg border border-red-500/30 bg-red-500/10 text-sm text-red-700 dark:text-red-300">
                    <Ban className="w-4 h-4" /> This invoice was voided{facture.void_reason ? `: ${facture.void_reason}` : '.'}
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* LEFT */}
                <div className="lg:col-span-2 space-y-6">
                    <Card>
                        <CardHeader>
                            <h2 className="text-sm font-semibold text-content">Invoice details</h2>
                            <div className="flex items-center gap-2">
                                {facture.locked_at && <span className="inline-flex items-center gap-1 text-[11px] text-content-muted"><Lock className="w-3 h-3" /> Finalized</span>}
                                <StatusBadge type="invoice" status={facture.status} />
                                <StatusBadge type="payment" status={facture.payment_status} />
                            </div>
                        </CardHeader>
                        <dl className="px-5 py-4 grid grid-cols-2 gap-y-3 text-sm">
                            <Dt>Invoice #</Dt><Dd className="font-mono text-content">{facture.invoice_number}</Dd>
                            <Dt>Invoice date</Dt><Dd>{facture.invoice_date ?? '—'}</Dd>
                            <Dt>Due date</Dt><Dd>{facture.due_date ?? '—'}</Dd>
                            <Dt>Issued by</Dt><Dd>{issuedByName ?? '—'}</Dd>
                            <Dt>Payment method</Dt><Dd className="uppercase text-xs tracking-wider">{facture.payment_method ?? '—'}</Dd>
                            <Dt>Emailed</Dt>
                            <Dd>
                                {sentAt ? (
                                    <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                                        <Check className="w-3.5 h-3.5" /> {sentAt.toLocaleString()}
                                    </span>
                                ) : (
                                    <span className="text-content-muted">Not sent</span>
                                )}
                            </Dd>
                        </dl>
                    </Card>

                    <Card>
                        <CardHeader><h2 className="text-sm font-semibold text-content">Customer</h2></CardHeader>
                        <div className="px-5 py-4 space-y-2 text-sm">
                            <div className="flex items-center gap-2">
                                <span className="w-7 h-7 rounded-full bg-gradient-to-br from-indigo-500 to-indigo-700 text-white text-xs font-bold flex items-center justify-center">
                                    {(facture.customer_name ?? '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase()}
                                </span>
                                <span className="text-content font-medium">{facture.customer_name || 'No name'}</span>
                            </div>
                            {facture.customer_email && <div className="flex items-center gap-2 text-content-muted"><Mail className="w-4 h-4 text-content-muted" /> {facture.customer_email}</div>}
                            {facture.customer_phone && <div className="flex items-center gap-2 text-content-muted"><Phone className="w-4 h-4 text-content-muted" /> {facture.customer_phone}</div>}
                            {facture.customer_address && <div className="flex items-start gap-2 text-content-muted"><MapPin className="w-4 h-4 text-content-muted mt-0.5" /> {facture.customer_address}</div>}
                        </div>
                    </Card>

                    <Card>
                        <CardHeader><h2 className="text-sm font-semibold text-content">Items <span className="text-content-muted font-normal">(snapshot)</span></h2></CardHeader>
                        {items.length === 0 ? (
                            <EmptyState icon={FileText} title="No line items" description="This invoice has no snapshot line items." />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-surface-2/60 text-xs uppercase tracking-wider text-content-muted border-b border-line">
                                        <tr>
                                            <th className="px-4 py-2.5 text-left">Description</th>
                                            <th className="px-4 py-2.5 text-right">Qty</th>
                                            <th className="px-4 py-2.5 text-right">Unit</th>
                                            <th className="px-4 py-2.5 text-right">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-line/50">
                                        {items.map((item) => (
                                            <tr key={item.id} className="text-content-muted">
                                                <td className="px-4 py-3">
                                                    <div className="font-medium text-content">{item.description}</div>
                                                    {item.sku && <div className="text-xs font-mono text-content-muted">{item.sku}</div>}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">{Number(item.quantity)}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{fmtMoney(item.unit_price, currency)}</td>
                                                <td className="px-4 py-3 text-right font-semibold text-content tabular-nums">{fmtMoney(item.line_total, currency)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>

                    {/* AUDIT TIMELINE */}
                    <Card>
                        <CardHeader><h2 className="flex items-center gap-2 text-sm font-semibold text-content"><History className="w-4 h-4 text-indigo-600 dark:text-indigo-400" /> Audit trail</h2></CardHeader>
                        <div className="px-5 py-4">
                            {activities.length === 0 ? (
                                <p className="text-xs text-content-muted">No recorded activity.</p>
                            ) : (
                                <ol className="relative border-l border-line ml-2 space-y-5">
                                    {activities.map((a) => (
                                        <li key={a.id} className="ml-4">
                                            <span className="absolute -left-1.5 mt-1 w-3 h-3 rounded-full bg-indigo-500 border-2 border-surface-2" />
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm font-medium text-content capitalize">{a.event || 'updated'}</span>
                                                <span className="text-[11px] text-content-muted">{a.created_at ? new Date(a.created_at).toLocaleString() : ''}</span>
                                            </div>
                                            <div className="text-xs text-content-muted">by {a.causer}</div>
                                            {a.reason && <div className="mt-1 text-xs text-amber-700 dark:text-amber-300/90">Reason: {a.reason}</div>}
                                            {a.old && a.new && (
                                                <div className="mt-1 space-y-0.5">
                                                    {Object.keys(a.new).map((k) => (
                                                        <div key={k} className="text-[11px] text-content-muted">
                                                            <span className="text-content-muted">{k}:</span>{' '}
                                                            <span className="line-through text-red-700 dark:text-red-300/70">{String(a.old?.[k] ?? '—')}</span>{' → '}
                                                            <span className="text-emerald-700 dark:text-emerald-300/80">{String(a.new[k])}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>
                    </Card>
                </div>

                {/* RIGHT */}
                <div className="space-y-6">
                    <Card>
                        <CardHeader><h2 className="text-sm font-semibold text-content">Payment summary</h2></CardHeader>
                        <dl className="px-5 py-4 space-y-2 text-sm">
                            <Row label="Subtotal" value={fmtMoney(facture.subtotal, currency)} />
                            <Row label="Discount" value={`−${fmtMoney(facture.discount_amount, currency)}`} tone="red" />
                            <Row label="Tax" value={fmtMoney(facture.tax_amount, currency)} />
                            <div className="pt-2 mt-2 border-t border-line" />
                            <Row label="Total" value={fmtMoney(facture.total_amount, currency)} large />
                            <Row label="Amount paid" value={fmtMoney(facture.amount_paid, currency)} tone="emerald" />
                            <Row label="Remaining" value={fmtMoney(remaining, currency)} tone={remaining > 0 ? 'red' : 'slate'} large />
                        </dl>
                    </Card>

                    {facture.pos_order_id && (
                        <Card>
                            <CardHeader><h2 className="text-sm font-semibold text-content">Source order</h2></CardHeader>
                            <div className="px-5 py-4 text-sm">
                                <Link href={`/dashboard/orders/${facture.pos_order_id}`} className="flex items-center gap-2 text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:text-indigo-300">
                                    <CreditCard className="w-4 h-4" /> View POS order
                                </Link>
                            </div>
                        </Card>
                    )}
                </div>
            </div>

            {modal === 'pay'   && <PayModal   facture={facture} remaining={remaining} onClose={() => setModal(null)} />}
            {modal === 'amend' && <AmendModal facture={facture} onClose={() => setModal(null)} />}
            {modal === 'void'  && <VoidModal  facture={facture} onClose={() => setModal(null)} />}
        </SaasLayout>
    );
}

/* ---------------- Modals ---------------- */

function Modal({ title, icon: Icon, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
            <div className="relative w-full max-w-md bg-surface-2 border border-line rounded-2xl shadow-2xl">
                <div className="flex items-center justify-between px-5 py-3 border-b border-line">
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-content">{Icon && <Icon className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />} {title}</h3>
                    <button type="button" onClick={onClose} className="p-1 text-content-muted hover:text-content"><X className="w-4 h-4" /></button>
                </div>
                <div className="p-5">{children}</div>
            </div>
        </div>
    );
}

const inputCls = 'w-full px-3 py-2.5 rounded-lg bg-surface border border-line text-content placeholder:text-content-muted/60 focus:outline-none focus:border-indigo-500';

function PayModal({ facture, remaining, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ amount: remaining.toFixed(2) });
    const submit = (e) => { e.preventDefault(); post(`/dashboard/invoices/${facture.id}/pay`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Modal title="Record a payment" icon={Wallet} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label className="block text-xs font-medium text-content-muted mb-1">Amount</label>
                    <input type="number" step="0.01" min="0.01" value={data.amount} onChange={(e) => setData('amount', e.target.value)} className={inputCls} autoFocus />
                    {errors.amount && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.amount}</p>}
                    <p className="mt-1 text-[11px] text-content-muted">Remaining balance: {remaining.toFixed(2)}</p>
                </div>
                <SubmitRow processing={processing} label="Record payment" onClose={onClose} />
            </form>
        </Modal>
    );
}

function AmendModal({ facture, onClose }) {
    const { data, setData, patch, processing, errors } = useForm({
        reason: '', customer_name: facture.customer_name ?? '', customer_email: facture.customer_email ?? '',
        customer_phone: facture.customer_phone ?? '', due_date: facture.due_date ?? '', notes: facture.notes ?? '',
    });
    const submit = (e) => { e.preventDefault(); patch(`/dashboard/invoices/${facture.id}`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Modal title="Amend invoice" icon={Pencil} onClose={onClose}>
            <form onSubmit={submit} className="space-y-3">
                <div className="flex items-start gap-2 px-3 py-2 rounded-lg bg-amber-500/10 border border-amber-500/30 text-[11px] text-amber-700 dark:text-amber-300">
                    <ShieldCheck className="w-3.5 h-3.5 mt-0.5" /> This invoice is finalized. Your change is logged with your name and the reason below.
                </div>
                <Field label="Reason (required)" error={errors.reason}>
                    <input type="text" value={data.reason} onChange={(e) => setData('reason', e.target.value)} placeholder="Why is this amendment needed?" className={inputCls} autoFocus />
                </Field>
                <Field label="Customer name" error={errors.customer_name}><input type="text" value={data.customer_name} onChange={(e) => setData('customer_name', e.target.value)} className={inputCls} /></Field>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Email" error={errors.customer_email}><input type="email" value={data.customer_email} onChange={(e) => setData('customer_email', e.target.value)} className={inputCls} /></Field>
                    <Field label="Phone" error={errors.customer_phone}><input type="text" value={data.customer_phone} onChange={(e) => setData('customer_phone', e.target.value)} className={inputCls} /></Field>
                </div>
                <Field label="Due date" error={errors.due_date}><input type="date" value={data.due_date ?? ''} onChange={(e) => setData('due_date', e.target.value)} className={inputCls} /></Field>
                <SubmitRow processing={processing} label="Save amendment" onClose={onClose} disabled={! data.reason} />
            </form>
        </Modal>
    );
}

function VoidModal({ facture, onClose }) {
    const { data, setData, post, processing, errors } = useForm({ reason: '' });
    const submit = (e) => { e.preventDefault(); post(`/dashboard/invoices/${facture.id}/void`, { preserveScroll: true, onSuccess: onClose }); };
    return (
        <Modal title="Void invoice" icon={Ban} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <p className="text-xs text-content-muted">Voiding is permanent and fully audited. The invoice stays on record but is marked void.</p>
                <Field label="Reason (required)" error={errors.reason}>
                    <input type="text" value={data.reason} onChange={(e) => setData('reason', e.target.value)} placeholder="Reason for voiding" className={inputCls} autoFocus />
                </Field>
                <SubmitRow processing={processing} label="Void invoice" onClose={onClose} disabled={! data.reason} danger />
            </form>
        </Modal>
    );
}

function Field({ label, error, children }) {
    return (
        <div>
            <label className="block text-xs font-medium text-content-muted mb-1">{label}</label>
            {children}
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

function SubmitRow({ processing, label, onClose, disabled = false, danger = false }) {
    return (
        <div className="flex items-center justify-end gap-2 pt-1">
            <button type="button" onClick={onClose} className="px-3 py-2 text-sm text-content-muted hover:text-content">Cancel</button>
            <button type="submit" disabled={processing || disabled} className={`inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg text-white disabled:opacity-50 ${danger ? 'bg-red-600 hover:bg-red-500' : 'bg-indigo-600 hover:bg-indigo-500'}`}>
                {processing && <Loader2 className="w-4 h-4 animate-spin" />} {label}
            </button>
        </div>
    );
}

/* ---------------- Primitives ---------------- */

function Card({ children }) { return <div className="bg-surface-2 border border-line rounded-xl overflow-hidden">{children}</div>; }
function CardHeader({ children }) { return <div className="px-5 py-3 border-b border-line flex items-center justify-between">{children}</div>; }
function Dt({ children }) { return <dt className="text-content-muted">{children}</dt>; }
function Dd({ children, className = '' }) { return <dd className={`text-content text-right ${className}`}>{children}</dd>; }
function Row({ label, value, tone = 'slate', large = false }) {
    const toneClass = tone === 'red' ? 'text-red-600 dark:text-red-400' : tone === 'emerald' ? 'text-emerald-600 dark:text-emerald-400' : 'text-content';
    return (
        <div className="flex items-center justify-between">
            <span className={large ? 'text-sm font-semibold text-content' : 'text-content-muted'}>{label}</span>
            <span className={`tabular-nums ${large ? 'text-lg font-bold' : ''} ${toneClass}`}>{value}</span>
        </div>
    );
}
function fmtMoney(value, currency) {
    const n = Number(value) || 0;
    return `${currency} ${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
