import { useForm, router, Link } from '@inertiajs/react';
import AgencyLayout from '@/Layouts/AgencyLayout';

export default function Clients({ agency, clients = [] }) {
    const form = useForm({ client_name: '', brand_name: '', country: 'MA', currency: 'MAD' });
    const submit = (e) => { e.preventDefault(); form.post('/agency/clients'); };
    return <AgencyLayout title={`${agency.name} — Clients`}>
        <form onSubmit={submit} className="bg-surface-2 border border-line rounded-xl p-5 mb-6 grid md:grid-cols-5 gap-3">
            <input className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content" placeholder="Client business" value={form.data.client_name} onChange={e=>form.setData('client_name',e.target.value)} />
            <input className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content" placeholder="First brand/store" value={form.data.brand_name} onChange={e=>form.setData('brand_name',e.target.value)} />
            <input className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content" placeholder="Country" value={form.data.country} onChange={e=>form.setData('country',e.target.value)} />
            <input className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content" placeholder="Currency" value={form.data.currency} onChange={e=>form.setData('currency',e.target.value)} />
            <button disabled={form.processing} className="rounded-lg bg-indigo-600 text-white px-4 py-2">Add client</button>
        </form>
        <div className="space-y-3">
            {clients.map(client => <div key={client.id} className="bg-surface-2 border border-line rounded-xl p-4 flex items-center justify-between gap-4">
                <div><div className="font-semibold">{client.name}</div><div className="text-xs text-content-muted">{client.stores?.length ?? 0} brand/store(s)</div></div>
                <div className="flex gap-2">
                    <Link href={`/agency/clients/${client.id}`} className="px-3 py-2 border border-line rounded-lg text-sm">Setup</Link>
                    {client.stores?.[0] && <button onClick={()=>router.post(`/agency/clients/${client.id}/stores/${client.stores[0].id}/open`)} className="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm">Open dashboard</button>}
                </div>
            </div>)}
            {clients.length === 0 && <div className="text-content-muted">No clients yet.</div>}
        </div>
    </AgencyLayout>;
}
