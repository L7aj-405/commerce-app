import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import RecurringExpenseForm from '@/Components/Finance/RecurringExpenseForm';

export default function Create({ options }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '', description: '', amount: '', currency: 'MAD',
        category_id: '', vendor_id: '', store_id: '',
        frequency: 'monthly', starts_at: new Date().toISOString().slice(0, 10), next_due_at: new Date().toISOString().slice(0, 10),
        reminder_days_before: 7, auto_create_expense: true, generated_expense_status: 'unpaid', notes: '',
    });

    const submit = (e) => { e.preventDefault(); post('/dashboard/finance/recurring'); };

    return (
        <SaasLayout pageHeader={{
            title: 'Add recurring expense',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Finance', href: '/dashboard/finance' },
                { label: 'Recurring', href: '/dashboard/finance/recurring' },
                { label: 'Add' },
            ],
            actions: (
                <Link href="/dashboard/finance/recurring" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            <RecurringExpenseForm data={data} setData={setData} errors={errors} processing={processing} options={options} onSubmit={submit} submitLabel="Create subscription" />
        </SaasLayout>
    );
}
