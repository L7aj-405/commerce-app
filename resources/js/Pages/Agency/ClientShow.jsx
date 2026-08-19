import { router, Link } from '@inertiajs/react';
import AgencyLayout from '@/Layouts/AgencyLayout';

const SERVICE_LABELS = { confirmation:'Confirmation', customer_support:'Customer support', delivery:'Delivery' };
export default function ClientShow({ agency, client, warehouses = [], assigned = [], services = {} }) {
    const assignedIds = new Set(assigned);
    const setService=(code,operator)=>router.put(`/agency/clients/${client.id}/services/${code}`,{operator},{preserveScroll:true});
    return <AgencyLayout title={client.name}>
        <div className="mb-5"><Link href="/agency/clients" className="text-indigo-600 text-sm">← Back to clients</Link></div>
        <section className="bg-surface-2 border border-line rounded-xl p-5 mb-5"><h2 className="font-semibold mb-3">Brands / stores</h2>{client.stores?.map(s=><div key={s.id} className="flex justify-between py-2 border-b border-line last:border-0"><span>{s.name}</span><button className="text-indigo-600" onClick={()=>router.post(`/agency/clients/${client.id}/stores/${s.id}/open`)}>Open dashboard</button></div>)}</section>
        <section className="bg-surface-2 border border-line rounded-xl p-5 mb-5"><h2 className="font-semibold mb-1">Warehouses</h2><p className="text-xs text-content-muted mb-3">Assign physical agency warehouses this client may store stock in.</p>{warehouses.map(w=><div key={w.id} className="flex items-center justify-between py-2"><span>{w.name} {w.city ? `— ${w.city}`:''}</span>{assignedIds.has(w.id)?<span className="text-emerald-600 text-sm">Assigned</span>:<button className="text-indigo-600 text-sm" onClick={()=>router.post(`/agency/clients/${client.id}/warehouses/${w.id}`,{}, {preserveScroll:true})}>Assign</button>}</div>)}</section>
        <section className="bg-surface-2 border border-line rounded-xl p-5"><h2 className="font-semibold mb-1">Service operators</h2><p className="text-xs text-content-muted mb-3">Picking/packing/dispatch are intentionally not here; they follow the warehouse operator.</p>{Object.entries(SERVICE_LABELS).map(([code,label])=><div key={code} className="grid grid-cols-2 items-center py-2"><span>{label}</span><select className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content" value={services[code]||'self'} onChange={e=>setService(code,e.target.value)}><option value="self">Client / self</option><option value="agency">{agency.name}</option></select></div>)}</section>
    </AgencyLayout>;
}
