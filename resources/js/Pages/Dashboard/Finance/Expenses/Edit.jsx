import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import ExpenseForm from '@/Components/Finance/ExpenseForm';

export default function Edit({ expense, options }) {
    const { data, setData, patch, processing, errors } = useForm({
        title: expense.title, description: expense.description ?? '', amount: expense.amount, currency: expense.currency,
        category_id: expense.category_id, vendor_id: expense.vendor_id ?? '', store_id: expense.store_id ?? '',
        expense_date: expense.expense_date, due_date: expense.due_date ?? '',
        payment_method: expense.payment_method ?? '', reference: expense.reference ?? '',
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
                <Link href="/dashboard/finance/expenses" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            <ExpenseForm data={data} setData={setData} errors={errors} processing={processing} options={options} onSubmit={submit} submitLabel="Save changes" ledgerLocked={expense.status === 'paid'} />
        </SaasLayout>
    );
}
