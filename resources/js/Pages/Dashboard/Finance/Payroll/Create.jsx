import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import Select from '@/Components/Select';

export default function Create({ options }) {
    const today = new Date();
    const monthStart = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().slice(0, 10);
    const monthEnd = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().slice(0, 10);

    const { data, setData, post, processing, errors } = useForm({
        store_id: '', period_start: monthStart, period_end: monthEnd, pay_date: '', notes: '',
    });

    const submit = (e) => { e.preventDefault(); post('/dashboard/finance/payroll'); };

    return (
        <SaasLayout pageHeader={{
            title: 'New payroll period',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Finance', href: '/dashboard/finance' },
                { label: 'Payroll', href: '/dashboard/finance/payroll' },
                { label: 'New' },
            ],
            actions: (
                <Link href="/dashboard/finance/payroll" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            <form onSubmit={submit} className="bg-surface-2 border border-line rounded-xl p-6 max-w-2xl space-y-4">
                <p className="text-sm text-content-muted">Creates a draft — calculate it afterwards to see salary due per employee. Nothing is posted to the ledger until you pay it.</p>

                <div>
                    <label className="block text-sm font-medium text-content-muted mb-1">Store (optional — leave blank for organization-wide)</label>
                    <Select value={data.store_id} onChange={(v) => setData('store_id', v)}
                        options={[{ value: '', label: 'Organization-wide' }, ...options.stores.map((s) => ({ value: s.id, label: s.name }))]} />
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label className="block text-sm font-medium text-content-muted mb-1">Period start</label>
                        <input type="date" value={data.period_start} onChange={(e) => setData('period_start', e.target.value)}
                            className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${errors.period_start ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`} />
                        {errors.period_start && <p className="text-xs text-danger mt-1">{errors.period_start}</p>}
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-content-muted mb-1">Period end</label>
                        <input type="date" value={data.period_end} onChange={(e) => setData('period_end', e.target.value)}
                            className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${errors.period_end ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`} />
                        {errors.period_end && <p className="text-xs text-danger mt-1">{errors.period_end}</p>}
                    </div>
                </div>

                <div>
                    <label className="block text-sm font-medium text-content-muted mb-1">Pay date (optional)</label>
                    <input type="date" value={data.pay_date} onChange={(e) => setData('pay_date', e.target.value)}
                        className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>

                <div>
                    <label className="block text-sm font-medium text-content-muted mb-1">Notes</label>
                    <textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2}
                        className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>

                <button type="submit" disabled={processing} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-primary text-white hover:bg-primary-strong disabled:opacity-50">
                    <Save className="w-4 h-4" /> Create period
                </button>
            </form>
        </SaasLayout>
    );
}
