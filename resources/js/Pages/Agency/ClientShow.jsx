import { router, Link } from '@inertiajs/react';
import { ArrowLeft, Store as StoreIcon, Warehouse as WarehouseIcon, Settings as SettingsIcon, CheckCircle2 } from 'lucide-react';
import AgencyLayout from '@/Layouts/AgencyLayout';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import TypeBadge from '@/Components/TypeBadge';
import EmptyState from '@/Components/EmptyState';

const SERVICE_LABELS = { confirmation: 'Confirmation', customer_support: 'Customer support', delivery: 'Delivery' };

export default function ClientShow({ agency, client, warehouses = [], assigned = [], services = {} }) {
    const assignedIds = new Set(assigned);
    const setService = (code, operator) => router.put(`/agency/clients/${client.id}/services/${code}`, { operator }, { preserveScroll: true });

    return (
        <AgencyLayout pageHeader={{
            title: client.name,
            subtitle: 'Client organization',
            breadcrumbs: [{ label: 'Clients', href: '/agency/clients' }, { label: client.name }],
            actions: <TypeBadge kind="organization" value="client" />,
        }}>
            <div className="mb-5">
                <Link href="/agency/clients" className="inline-flex items-center gap-1 text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                    <ArrowLeft className="w-3.5 h-3.5" /> Back to clients
                </Link>
            </div>

            <Card title="Brands / stores" className="mb-5">
                {(client.stores ?? []).length === 0 ? (
                    <EmptyState icon={StoreIcon} title="No brand/store yet" />
                ) : (
                    <ul className="divide-y divide-line -mx-5">
                        {client.stores.map((s) => (
                            <li key={s.id} className="flex items-center justify-between px-5 py-2.5">
                                <span className="text-sm text-content">{s.name}</span>
                                <Button variant="ghost" icon={SettingsIcon} onClick={() => router.post(`/agency/clients/${client.id}/stores/${s.id}/open`)}>
                                    Open dashboard
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Card
                title="Warehouses"
                subtitle="Assign physical agency warehouses this client may store stock in."
                className="mb-5"
            >
                {warehouses.length === 0 ? (
                    <EmptyState icon={WarehouseIcon} title="No agency warehouses to assign" description="Create one from the Warehouses page first." />
                ) : (
                    <ul className="divide-y divide-line -mx-5">
                        {warehouses.map((w) => (
                            <li key={w.id} className="flex items-center justify-between px-5 py-2.5">
                                <span className="text-sm text-content">{w.name}{w.city ? ` — ${w.city}` : ''}</span>
                                {assignedIds.has(w.id) ? (
                                    <span className="inline-flex items-center gap-1 text-sm text-emerald-600 dark:text-emerald-400">
                                        <CheckCircle2 className="w-3.5 h-3.5" /> Assigned
                                    </span>
                                ) : (
                                    <Button variant="ghost" onClick={() => router.post(`/agency/clients/${client.id}/warehouses/${w.id}`, {}, { preserveScroll: true })}>
                                        Assign
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Card
                title="Service operators"
                subtitle="Picking/packing/dispatch are intentionally not here — they follow the warehouse operator, not a toggle."
            >
                <div className="divide-y divide-line -mx-5">
                    {Object.entries(SERVICE_LABELS).map(([code, label]) => (
                        <div key={code} className="grid grid-cols-2 items-center gap-3 px-5 py-2.5">
                            <span className="text-sm text-content">{label}</span>
                            <select
                                className="w-full px-3 py-2 text-sm rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                                value={services[code] || 'self'}
                                onChange={(e) => setService(code, e.target.value)}
                            >
                                <option value="self">Client / self</option>
                                <option value="agency">{agency.name}</option>
                            </select>
                        </div>
                    ))}
                </div>
            </Card>
        </AgencyLayout>
    );
}
