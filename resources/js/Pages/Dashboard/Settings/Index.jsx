import { useForm } from '@inertiajs/react';
import { Loader2, Save, AlertTriangle } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

export default function Index({ store }) {
    const { data, setData, post, processing, errors } = useForm({
        name:          store?.name ?? '',
        country:       store?.country ?? '',
        currency:      store?.currency ?? '',
        phone:         store?.phone ?? '',
        business_type: store?.business_type ?? '',
        tax_rate:      store?.settings?.tax_rate ?? 0,
    });

    const submit = (e) => {
        e.preventDefault();
        post('/dashboard/settings');
    };

    return (
        <SaasLayout pageHeader={{
            title: 'Store settings',
            subtitle: 'Edit basic information about your store',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Settings' }],
        }}>
            {! store ? (
                <div className="bg-surface-2 border border-line rounded-xl p-8 text-center text-content-muted">
                    No active store. <a href="/dashboard/stores/create" className="text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:text-indigo-300">Create one</a>.
                </div>
            ) : (
                <>
                    <form onSubmit={submit} className="bg-surface-2 border border-line rounded-xl p-6 max-w-2xl space-y-5">
                        <Field label="Store name"    value={data.name}     onChange={(v) => setData('name', v)}     error={errors.name} required />
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <Field label="Country (ISO 2)" value={data.country}  onChange={(v) => setData('country', v.toUpperCase().slice(0, 2))} error={errors.country} />
                            <Field label="Currency (ISO 3)" value={data.currency} onChange={(v) => setData('currency', v.toUpperCase().slice(0, 3))} error={errors.currency} />
                        </div>
                        <Field label="Phone" type="tel" value={data.phone} onChange={(v) => setData('phone', v)} error={errors.phone} />

                        <Select
                            label="Business type"
                            value={data.business_type}
                            onChange={(v) => setData('business_type', v)}
                            options={[
                                { value: 'retail',      label: 'Retail' },
                                { value: 'restaurant',  label: 'Restaurant' },
                                { value: 'fashion',     label: 'Fashion' },
                                { value: 'electronics', label: 'Electronics' },
                                { value: 'grocery',     label: 'Grocery' },
                                { value: 'other',       label: 'Other' },
                            ]}
                            error={errors.business_type}
                        />

                        <Field
                            label="Tax rate (0.00 — 1.00)"
                            type="number"
                            step="0.01"
                            min="0"
                            max="1"
                            value={data.tax_rate}
                            onChange={(v) => setData('tax_rate', Number(v))}
                            error={errors.tax_rate}
                        />

                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50 transition"
                        >
                            {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving…</> : <><Save className="w-4 h-4" /> Save settings</>}
                        </button>
                    </form>

                    <section className="mt-8 max-w-2xl">
                        <div className="bg-red-500/5 border border-red-500/30 rounded-xl p-6">
                            <div className="flex items-start gap-3">
                                <AlertTriangle className="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                                <div>
                                    <h3 className="text-sm font-semibold text-red-200">Danger zone</h3>
                                    <p className="text-xs text-red-700 dark:text-red-300/80 mt-1">
                                        Deleting a store removes its products, orders, and team. This cannot be undone.
                                    </p>
                                    <button
                                        type="button"
                                        className="mt-3 inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg bg-red-600/20 border border-red-500/40 text-red-200 hover:bg-red-600/30 transition"
                                        onClick={() => alert('Delete-store endpoint not yet wired.')}
                                    >
                                        Delete store
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>
                </>
            )}
        </SaasLayout>
    );
}

function Field({ label, type = 'text', value, onChange, error, required, ...rest }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">
                {label} {required && <span className="text-red-600 dark:text-red-400">*</span>}
            </label>
            <input
                type={type}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                {...rest}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${
                    error ? 'border-red-500' : 'border-line'
                } text-content focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent`}
            />
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

function Select({ label, value, onChange, options, error }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label}</label>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${
                    error ? 'border-red-500' : 'border-line'
                } text-content focus:outline-none focus:ring-2 focus:ring-indigo-500`}
            >
                <option value="">Choose…</option>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}
