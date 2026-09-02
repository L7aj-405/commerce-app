import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import EmployeeForm from '@/Components/Payroll/EmployeeForm';

export default function Create({ options }) {
    const { data, setData, post, processing, errors } = useForm({
        first_name: '', last_name: '', display_name: '', phone: '', email: '',
        employee_code: '', role_type: '', employment_status: 'active',
        store_id: '', hired_at: new Date().toISOString().slice(0, 10), left_at: '', notes: '',
    });

    const submit = (e) => { e.preventDefault(); post('/dashboard/employees'); };

    return (
        <SaasLayout pageHeader={{
            title: 'Add employee',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Employees', href: '/dashboard/employees' },
                { label: 'Add' },
            ],
            actions: (
                <Link href="/dashboard/employees" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            <EmployeeForm data={data} setData={setData} errors={errors} processing={processing} options={options} onSubmit={submit} submitLabel="Add employee" />
        </SaasLayout>
    );
}
