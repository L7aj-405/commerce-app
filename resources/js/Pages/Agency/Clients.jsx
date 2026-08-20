import { useForm, router, Link } from '@inertiajs/react';
import { Users, Store as StoreIcon, ArrowRight, Settings as SettingsIcon } from 'lucide-react';
import AgencyLayout from '@/Layouts/AgencyLayout';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import EmptyState from '@/Components/EmptyState';

export default function Clients({ agency, clients = [] }) {
    const form = useForm({ client_name: '', brand_name: '', country: 'MA', currency: 'MAD' });
    const submit = (e) => { e.preventDefault(); form.post('/agency/clients', { onSuccess: () => form.reset() }); };

    return (
        <AgencyLayout pageHeader={{
            title: 'Clients',
            subtitle: `Businesses ${agency.name} manages`,
        }}>
            <Card title="Add a client" className="mb-6">
                <form onSubmit={submit} className="grid md:grid-cols-5 gap-3 items-end">
                    <Field label="Client business" value={form.data.client_name} onChange={(v) => form.setData('client_name', v)} error={form.errors.client_name} />
                    <Field label="First brand/store" value={form.data.brand_name} onChange={(v) => form.setData('brand_name', v)} error={form.errors.brand_name} />
                    <Field label="Country" value={form.data.country} onChange={(v) => form.setData('country', v)} error={form.errors.country} />
                    <Field label="Currency" value={form.data.currency} onChange={(v) => form.setData('currency', v)} error={form.errors.currency} />
                    <Button type="submit" loading={form.processing}>Add client</Button>
                </form>
            </Card>

            {clients.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={Users}
                        title="No clients yet"
                        description="Add your first client above — their first brand/store is created together with them."
                    />
                </Card>
            ) : (
                <div className="grid md:grid-cols-2 gap-3">
                    {clients.map((client) => (
                        <Card
                            key={client.id}
                            title={client.name}
                            subtitle={`${client.stores?.length ?? 0} brand/store(s)`}
                            actions={
                                <Link href={`/agency/clients/${client.id}`} className="p-2 rounded-lg text-content-muted hover:text-content hover:bg-surface-3" title="Setup">
                                    <SettingsIcon className="w-4 h-4" />
                                </Link>
                            }
                        >
                            {client.stores?.[0] && (
                                <Button
                                    variant="secondary"
                                    icon={StoreIcon}
                                    className="w-full justify-between"
                                    onClick={() => router.post(`/agency/clients/${client.id}/stores/${client.stores[0].id}/open`)}
                                >
                                    <span className="flex-1 text-left">Open dashboard</span>
                                    <ArrowRight className="w-3.5 h-3.5" />
                                </Button>
                            )}
                        </Card>
                    ))}
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
