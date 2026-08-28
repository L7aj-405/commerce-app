import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Loader2, Save } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

export default function Create({ cities = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '', location: '',
        address: '', city: '', state: '', country: '', zip: '',
        phone: '', email: '',
        is_default: false,
        service_city_ids: [],
    });

    const submit = (e) => { e.preventDefault(); post('/dashboard/warehouses'); };

    return (
        <SaasLayout pageHeader={{
            title: 'Add warehouse',
            breadcrumbs: [
                { label: 'Dashboard',  href: '/dashboard' },
                { label: 'Warehouses', href: '/dashboard/warehouses' },
                { label: 'Add' },
            ],
            actions: (
                <Link href="/dashboard/warehouses" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            <form onSubmit={submit} className="bg-surface-2 border border-line rounded-xl p-6 max-w-3xl space-y-6">
                <Section title="Basic information">
                    <Field label="Warehouse name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} required />
                    <Field label="Location label" value={data.location} onChange={(v) => setData('location', v)} error={errors.location} placeholder="e.g. Main Office" />
                </Section>

                <Section title="Address">
                    <Field label="Address" value={data.address} onChange={(v) => setData('address', v)} error={errors.address} />
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <Field label="City"    value={data.city}    onChange={(v) => setData('city', v)}    error={errors.city} />
                        <Field label="State"   value={data.state}   onChange={(v) => setData('state', v)}   error={errors.state} />
                        <Field label="ZIP"     value={data.zip}     onChange={(v) => setData('zip', v)}     error={errors.zip} />
                    </div>
                    <Field label="Country"  value={data.country} onChange={(v) => setData('country', v)} error={errors.country} />
                </Section>

                <Section title="Delivery service areas">
                    <p className="text-sm text-content-muted">Orders confirmed for these cities can be allocated to this warehouse.</p>
                    <CityPicker cities={cities} selected={data.service_city_ids} onChange={(ids) => setData('service_city_ids', ids)} />
                </Section>

                <Section title="Contact">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <Field label="Phone" type="tel"   value={data.phone} onChange={(v) => setData('phone', v)} error={errors.phone} />
                        <Field label="Email" type="email" value={data.email} onChange={(v) => setData('email', v)} error={errors.email} />
                    </div>
                </Section>

                <label className="flex items-center gap-2 text-sm text-content-muted cursor-pointer">
                    <input type="checkbox" checked={data.is_default} onChange={(e) => setData('is_default', e.target.checked)}
                        className="rounded bg-surface-3 border-line text-primary focus:ring-primary" />
                    Set as default warehouse
                </label>

                <button type="submit" disabled={processing} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-primary text-white hover:bg-primary-strong disabled:opacity-50">
                    {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Creating…</> : <><Save className="w-4 h-4" /> Create warehouse</>}
                </button>
            </form>
        </SaasLayout>
    );
}

function Section({ title, children }) {
    return <div className="space-y-4"><h3 className="text-sm font-semibold text-content">{title}</h3>{children}</div>;
}
function Field({ label, type = 'text', value, onChange, error, required, ...rest }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label} {required && <span className="text-red-600 dark:text-red-400">*</span>}</label>
            <input type={type} value={value} onChange={(e) => onChange(e.target.value)} {...rest}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-red-500' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`} />
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

function CityPicker({ cities, selected, onChange }) {
    const toggle = (id) => onChange(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]);
    return <div className="grid grid-cols-2 md:grid-cols-3 gap-2 max-h-56 overflow-auto p-3 rounded-lg bg-surface-3 border border-line">{cities.map((city) => <label key={city.id} className="flex items-center gap-2 text-sm text-content-muted"><input type="checkbox" checked={selected.includes(city.id)} onChange={() => toggle(city.id)} />{city.name}</label>)}</div>;
}
