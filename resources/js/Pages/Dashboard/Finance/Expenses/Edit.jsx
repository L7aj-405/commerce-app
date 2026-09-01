import { useState } from 'react';
import { Link, useForm, usePage, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, XCircle, HelpCircle, Printer } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import ExpenseForm from '@/Components/Finance/ExpenseForm';
import ExpenseDocumentsCard from '@/Components/Finance/ExpenseDocumentsCard';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import { formatDateTime } from '@/Support/formatDate';
import JustificationBadges from '@/Components/Finance/JustificationBadges';

export default function Edit({ expense, options, can }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const canManage = permissions.includes('*') || permissions.includes('finance.manage_expenses');
    const canReview = can?.review ?? false;

    const { data, setData, patch, processing, errors } = useForm({
        title: expense.title, description: expense.description ?? '', amount: expense.amount, currency: expense.currency,
        category_id: expense.category_id, vendor_id: expense.vendor_id ?? '', store_id: expense.store_id ?? '',
        expense_date: expense.expense_date, due_date: expense.due_date ?? '',
        payment_method: expense.payment_method ?? '', reference: expense.reference ?? '',
        justification_type: expense.justification_type ?? 'official_document',
        beneficiary_name: expense.beneficiary_name ?? '', justification_reason: expense.justification_reason ?? '',
        paid_by: expense.paid_by ?? '', justification_notes: expense.justification_notes ?? '',
    });

    const submit = (e) => { e.preventDefault(); patch(`/dashboard/finance/expenses/${expense.id}`); };

    return (
        <SaasLayout pageHeader={{
            title: 'Edit expense',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Finance', href: '/dashboard/finance' },
                { label: 'Expenses', href: '/dashboard/finance/expenses' },
                { label: 'Edit' },
            ],
            actions: (
                <div className="flex items-center gap-2">
                    {expense.justification_type !== 'official_document' && (
                        <a href={`/dashboard/finance/expenses/${expense.id}/voucher`} target="_blank" rel="noopener noreferrer"
                            className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                            <Printer className="w-4 h-4" /> Print internal voucher
                        </a>
                    )}
                    <Link href="/dashboard/finance/expenses" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                        <ArrowLeft className="w-4 h-4" /> Back
                    </Link>
                </div>
            ),
        }}>
            <div className="max-w-3xl mb-4 flex flex-wrap items-center gap-2">
                <JustificationBadges expense={expense} />
            </div>

            <ExpenseForm
                data={data} setData={setData} errors={errors} processing={processing} options={options} onSubmit={submit} submitLabel="Save changes" ledgerLocked={expense.status === 'paid'}
                justificationChildren={<p className="text-xs text-content-muted">An optional photo of the voucher/receipt can be attached below.</p>}
            />

            {expense.justification_type !== 'official_document' && (
                <ReviewCard expense={expense} canReview={canReview} />
            )}

            <ExpenseDocumentsCard expense={expense} canManage={canManage} documentTypes={options.documentTypes ?? []} />
        </SaasLayout>
    );
}

function ReviewCard({ expense, canReview }) {
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const act = (action) => {
        setBusy(true);
        router.post(`/dashboard/finance/expenses/${expense.id}/${action}`, { note }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Card title="Owner review" subtitle="Internal cash voucher / no-invoice expenses need owner or accountant review" className="max-w-3xl mt-6">
            <div className="space-y-3">
                {expense.owner_review_status && (
                    <p className="text-sm text-content-muted">
                        Current status: <span className="font-medium text-content capitalize">{expense.owner_review_status.replace(/_/g, ' ')}</span>
                        {expense.owner_reviewed_by?.name && <> · reviewed by {expense.owner_reviewed_by.name}{expense.owner_reviewed_at ? ` on ${formatDateTime(expense.owner_reviewed_at)}` : ''}</>}
                    </p>
                )}
                {expense.owner_review_note && (
                    <p className="text-sm text-content-muted italic">"{expense.owner_review_note}"</p>
                )}

                {expense.status === 'paid' && expense.owner_review_status !== 'rejected' && (
                    <p className="text-xs text-warning">Rejecting this expense after payment will not delete the recorded transaction — a reversal will be created instead.</p>
                )}

                {canReview && expense.owner_review_status !== 'rejected' && (
                    <>
                        <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={2} placeholder="Note (optional, recommended for reject / needs more info)"
                            className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        <div className="flex flex-wrap gap-2">
                            <Button variant="secondary" icon={CheckCircle2} loading={busy} onClick={() => act('approve')}>Approve</Button>
                            <Button variant="secondary" icon={HelpCircle} loading={busy} onClick={() => act('request-info')}>Needs more info</Button>
                            <Button variant="danger" icon={XCircle} loading={busy} onClick={() => act('reject')}>Reject</Button>
                        </div>
                    </>
                )}
            </div>
        </Card>
    );
}
