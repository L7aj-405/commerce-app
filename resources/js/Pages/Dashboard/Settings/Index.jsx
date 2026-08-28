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
                <div className="bg-surface-2 border border-line rounded-[var(--radius-card)] p-8 text-center text-content-muted">
                    No active store. <a href="/dashboard/stores/create" className="text-primary hover:text-primary-strong">Create one</a>.
                </div>
            ) : (
                <>
                    <form onSubmit={submit} className="bg-surface-2 border border-line rounded-[var(--radius-card)] p-6 max-w-2xl space-y-5">
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
                            className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-50 transition"
                        >
                            {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving…</> : <><Save className="w-4 h-4" /> Save settings</>}
                        </button>
                    </form>

                    <section className="mt-8 max-w-2xl">
                        <div className="bg-danger-soft border border-danger/30 rounded-[var(--radius-card)] p-6">
                            <div className="flex items-start gap-3">
                                <AlertTriangle className="w-5 h-5 text-danger flex-shrink-0 mt-0.5" />
                                <div>
                                    <h3 className="text-sm font-semibold text-danger">Danger zone</h3>
                                    <p className="text-xs text-danger/80 mt-1">
                                        Deleting a store removes its products, orders, and team. This cannot be undone.
                                    </p>
                                    <button
                                        type="button"
                                        className="mt-3 inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-[var(--radius-button)] bg-danger-soft border border-danger/40 text-danger hover:brightness-95 transition"
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
                {label} {required && <span className="text-danger">*</span>}
            </label>
            <input
                type={type}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                {...rest}
                className={`w-full px-3 py-2 rounded-[var(--radius-button)] bg-surface-3 border ${
                    error ? 'border-danger' : 'border-line'
                } text-content focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent`}
            />
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
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
                className={`w-full px-3 py-2 rounded-[var(--radius-button)] bg-surface-3 border ${
                    error ? 'border-danger' : 'border-line'
                } text-content focus:outline-none focus:ring-2 focus:ring-primary`}
            >
                <option value="">Choose…</option>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}
