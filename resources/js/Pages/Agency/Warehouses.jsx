import { useForm } from '@inertiajs/react';
import { Warehouse as WarehouseIcon } from 'lucide-react';
import AgencyLayout from '@/Layouts/AgencyLayout';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import EmptyState from '@/Components/EmptyState';

export default function Warehouses({ agency, warehouses = [], cities = [] }) {
    const form = useForm({ name: '', city: '', address: '', country: 'MA', service_city_ids: [] });
    const submit = (e) => { e.preventDefault(); form.post('/agency/warehouses', { onSuccess: () => form.reset('name', 'city', 'address') }); };

    return (
        <AgencyLayout pageHeader={{
            title: 'Warehouses',
            subtitle: `Physical fulfillment locations ${agency.name} owns and operates`,
        }}>
            <Card title="Add a warehouse" className="mb-6">
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid md:grid-cols-4 gap-3">
                        <Field label="Warehouse name" value={form.data.name} onChange={(v) => form.setData('name', v)} error={form.errors.name} />
                        <Field label="City" value={form.data.city} onChange={(v) => form.setData('city', v)} error={form.errors.city} />
                        <Field label="Address" value={form.data.address} onChange={(v) => form.setData('address', v)} error={form.errors.address} />
                        <Field label="Country" value={form.data.country} onChange={(v) => form.setData('country', v)} error={form.errors.country} />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-content-muted mb-1">Service cities (optional)</label>
                        <CityChecks cities={cities} selected={form.data.service_city_ids} onChange={(ids) => form.setData('service_city_ids', ids)} />
                    </div>
                    <Button type="submit" loading={form.processing}>Add warehouse</Button>
                </form>
            </Card>

            {warehouses.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={WarehouseIcon}
                        title="No agency warehouses yet"
                        description="Add one above, then assign it to clients from their client page."
                    />
                </Card>
            ) : (
                <div className="grid md:grid-cols-2 gap-3">
                    {warehouses.map((w) => <WarehouseCard key={w.id} warehouse={w} cities={cities} />)}
                </div>
            )}
        </AgencyLayout>
    );
}

function Field({ label, value, onChange, error }) {
    return (
        <div>
            <label className="block text-xs font-medium text-content-muted mb-1">{label}</label>
            <input
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={`w-full px-3 py-2 text-sm rounded-lg bg-surface-3 border ${error ? 'border-red-500/60' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-indigo-500/40`}
            />
            {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
        </div>
    );
}

function CityChecks({ cities, selected, onChange }) {
    const toggle = (id) => onChange(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]);

    if (cities.length === 0) return null;

    return (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-1 max-h-44 overflow-auto border border-line rounded-lg p-2">
            {cities.map((c) => (
                <label key={c.id} className="text-xs text-content-muted flex gap-1.5 items-center cursor-pointer">
                    <input type="checkbox" checked={selected.includes(c.id)} onChange={() => toggle(c.id)} className="rounded border-line text-indigo-600 focus:ring-indigo-500" />
                    {c.name}
                </label>
            ))}
        </div>
    );
}

function WarehouseCard({ warehouse, cities }) {
    const area = useForm({ service_city_ids: (warehouse.service_cities || []).map((c) => c.id) });

    return (
        <Card title={warehouse.name} subtitle={warehouse.city || 'No city set'}>
            <p className="text-xs text-content-muted mb-3">
                Clients: {(warehouse.accessible_organizations || []).map((o) => o.name).join(', ') || 'none yet'}
            </p>
            <CityChecks cities={cities} selected={area.data.service_city_ids} onChange={(ids) => area.setData('service_city_ids', ids)} />
            <Button
                variant="secondary"
                className="mt-3 text-xs px-3 py-1.5"
                loading={area.processing}
                onClick={() => area.put(`/agency/warehouses/${warehouse.id}/service-areas`, { preserveScroll: true })}
            >
                Save service areas
            </Button>
        </Card>
    );
}
