import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import ExpenseForm from '@/Components/Finance/ExpenseForm';

export default function Create({ options }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '', description: '', amount: '', currency: 'MAD',
        category_id: '', vendor_id: '', store_id: '',
        expense_date: new Date().toISOString().slice(0, 10), due_date: '',
        payment_method: '', reference: '',
    });

    const submit = (e) => { e.preventDefault(); post('/dashboard/finance/expenses'); };

    return (
        <SaasLayout pageHeader={{
            title: 'Record expense',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Finance', href: '/dashboard/finance' },
                { label: 'Expenses', href: '/dashboard/finance/expenses' },
                { label: 'Record' },
            ],
            actions: (
                <Link href="/dashboard/finance/expenses" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            <ExpenseForm data={data} setData={setData} errors={errors} processing={processing} options={options} onSubmit={submit} submitLabel="Record expense" />
        </SaasLayout>
    );
}
