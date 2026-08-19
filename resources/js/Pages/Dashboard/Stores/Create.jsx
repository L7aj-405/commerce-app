import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Loader2, Store as StoreIcon, Globe, Phone, Tag, Building2, Users, Info } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

/**
 * Organization-first "Add Store": the workspace is never invented here — it
 * always attaches to whichever organization is already active (shown, not
 * chosen), matching StoreController::resolveActiveOrganization(). An
 * agency's own organization never gets a store, so this renders a guidance
 * state instead of the form when organization.type === 'agency'.
 */
export default function Create({ organization, storeTypes = [], industries = [], countries = [] }) {
    if (organization?.type === 'agency') {
        return <AgencyGuidance organization={organization} />;
    }

    const { data, setData, post, processing, errors } = useForm({
        store_name:  '',
        store_type:  storeTypes[0]?.value ?? '',
        business_type: '',
        country:     countries[0]?.code ?? '',
        currency:    countries[0]?.currency ?? '',
        phone:       '',
    });

    const onCountryChange = (code) => {
        const c = countries.find((c) => c.code === code);
        setData((d) => ({ ...d, country: code, currency: c?.currency ?? d.currency }));
    };

    const submit = (e) => {
        e.preventDefault();
        post('/dashboard/stores');
    };

    return (
        <SaasLayout pageHeader={{
            title: 'Add a store',
            subtitle: 'Create a new store or brand inside your workspace.',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Stores',    href: '/dashboard/stores' },
                { label: 'Add' },
            ],
            actions: (
                <Link
                    href="/dashboard/stores"
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content"
                >
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            {organization && (
                <div className="max-w-2xl mb-5 flex items-center gap-2.5 px-4 py-3 rounded-xl bg-surface-2 border border-line text-sm">
                    <Building2 className="w-4 h-4 text-content-muted flex-shrink-0" />
                    <span className="text-content-muted">Adding a store to</span>
                    <span className="font-semibold text-content">{organization.name}</span>
                    <span className="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide bg-slate-500/15 text-slate-600 dark:text-slate-300">
                        {organization.type}
                    </span>
                </div>
            )}

            <form onSubmit={submit} className="max-w-2xl space-y-5">
                <Field
                    label="Store / brand name"
                    icon={StoreIcon}
                    value={data.store_name}
                    onChange={(v) => setData('store_name', v)}
                    error={errors.store_name}
                    required
                />

                <Select
                    label="Store type"
                    icon={Tag}
                    value={data.store_type}
                    onChange={(v) => setData('store_type', v)}
                    options={storeTypes}
                    error={errors.store_type}
                    required
                />

                <Select
                    label="Industry (optional)"
                    value={data.business_type}
                    onChange={(v) => setData('business_type', v)}
                    options={industries}
                    placeholder="Not specified"
                    error={errors.business_type}
                />

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Select
                        label="Country"
                        icon={Globe}
                        value={data.country}
                        onChange={onCountryChange}
                        options={countries.map((c) => ({ value: c.code, label: c.name }))}
                        error={errors.country}
                        required
                    />
                    <Field label="Currency" value={data.currency} onChange={(v) => setData('currency', v.toUpperCase().slice(0, 3))} error={errors.currency} required />
                </div>

                <Field label="Phone (optional)" icon={Phone} type="tel" value={data.phone} onChange={(v) => setData('phone', v)} error={errors.phone} />

                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50 transition"
                >
                    {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Creating…</> : 'Create store'}
                </button>
            </form>
        </SaasLayout>
    );
}

function AgencyGuidance({ organization }) {
    return (
        <SaasLayout pageHeader={{
            title: 'Add a store',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Stores',    href: '/dashboard/stores' },
                { label: 'Add' },
            ],
        }}>
            <div className="max-w-2xl bg-surface-2 border border-line rounded-xl p-8 text-center">
                <div className="w-12 h-12 mx-auto rounded-xl bg-amber-500/15 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                    <Info className="w-6 h-6" />
                </div>
                <h2 className="mt-4 text-base font-semibold text-content">
                    {organization?.name ?? 'This workspace'} is an agency
                </h2>
                <p className="mt-2 text-sm text-content-muted max-w-md mx-auto">
                    Stores belong to client organizations. Add or open a client first — their store/brand is created together with the client.
                </p>
                <div className="mt-6 flex items-center justify-center gap-3">
                    <Link
                        href="/agency/clients"
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                    >
                        <Users className="w-4 h-4" /> Agency clients
                    </Link>
                    <Link
                        href="/dashboard/stores"
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-surface-3 border border-line text-content-muted hover:text-content"
                    >
                        Back to stores
                    </Link>
                </div>
            </div>
        </SaasLayout>
    );
}

function Field({ label, icon: Icon, type = 'text', value, onChange, error, required }) {
    return (
        <div>
            <label className="block text-xs font-medium text-content-muted mb-1">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            <div className="relative">
                {Icon && <Icon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-content-muted pointer-events-none" />}
                <input
                    type={type}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className={`w-full ${Icon ? 'pl-9' : 'pl-3'} pr-3 py-2.5 rounded-lg bg-surface-2 border ${
                        error ? 'border-red-500/60' : 'border-line'
                    } text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-indigo-500/40`}
                />
            </div>
            {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
        </div>
    );
}

function Select({ label, icon: Icon, value, onChange, options, error, required, placeholder }) {
    return (
        <div>
            <label className="block text-xs font-medium text-content-muted mb-1">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            <div className="relative">
                {Icon && <Icon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-content-muted pointer-events-none" />}
                <select
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className={`w-full ${Icon ? 'pl-9' : 'pl-3'} pr-3 py-2.5 rounded-lg bg-surface-2 border ${
                        error ? 'border-red-500/60' : 'border-line'
                    } text-content focus:outline-none focus:ring-2 focus:ring-indigo-500/40`}
                >
                    {placeholder && <option value="">{placeholder}</option>}
                    {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
            </div>
            {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
        </div>
    );
}
