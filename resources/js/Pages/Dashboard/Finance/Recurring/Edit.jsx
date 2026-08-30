import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import RecurringExpenseForm from '@/Components/Finance/RecurringExpenseForm';

export default function Edit({ recurring, options }) {
    const { data, setData, patch, processing, errors } = useForm({
        title: recurring.title, description: recurring.description ?? '', amount: recurring.amount, currency: recurring.currency,
        category_id: recurring.category_id, vendor_id: recurring.vendor_id ?? '', store_id: recurring.store_id ?? '',
        frequency: recurring.frequency, starts_at: recurring.starts_at, next_due_at: recurring.next_due_at,
        reminder_days_before: recurring.reminder_days_before, auto_create_expense: recurring.auto_create_expense,
        generated_expense_status: recurring.generated_expense_status, notes: recurring.notes ?? '',
    });

    const submit = (e) => { e.preventDefault(); patch(`/dashboard/finance/recurring/${recurring.id}`); };

    return (
        <SaasLayout pageHeader={{
            title: 'Edit recurring expense',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Finance', href: '/dashboard/finance' },
                { label: 'Recurring', href: '/dashboard/finance/recurring' },
                { label: 'Edit' },
            ],
            actions: (
                <Link href="/dashboard/finance/recurring" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            <RecurringExpenseForm data={data} setData={setData} errors={errors} processing={processing} options={options} onSubmit={submit} submitLabel="Save changes" />
        </SaasLayout>
    );
}
