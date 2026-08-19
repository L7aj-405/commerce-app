import { useForm } from '@inertiajs/react';
import AgencyLayout from '@/Layouts/AgencyLayout';

export default function Warehouses({ agency, warehouses = [], cities = [] }) {
    const form = useForm({ name:'', city:'', address:'', country:'MA', service_city_ids:[] });
    const submit=e=>{e.preventDefault();form.post('/agency/warehouses',{onSuccess:()=>form.reset('name','city','address')});};
    return <AgencyLayout title={`${agency.name} — Warehouses`}>
        <form onSubmit={submit} className="bg-surface-2 border border-line rounded-xl p-5 mb-6 grid md:grid-cols-5 gap-3">
            <input className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content" placeholder="Warehouse name" value={form.data.name} onChange={e=>form.setData('name',e.target.value)} />
            <input className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content" placeholder="City" value={form.data.city} onChange={e=>form.setData('city',e.target.value)} />
            <input className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content" placeholder="Address" value={form.data.address} onChange={e=>form.setData('address',e.target.value)} />
            <input className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content" placeholder="Country" value={form.data.country} onChange={e=>form.setData('country',e.target.value)} />
            <div className="md:col-span-5"><CityChecks cities={cities} selected={form.data.service_city_ids} onChange={(ids)=>form.setData('service_city_ids',ids)} /></div>
            <button className="rounded-lg bg-indigo-600 text-white px-4 py-2">Add warehouse</button>
        </form>
        <div className="grid md:grid-cols-2 gap-3">{warehouses.map(w=><WarehouseCard key={w.id} warehouse={w} cities={cities} />)}</div>
    </AgencyLayout>;
}

function CityChecks({ cities, selected, onChange }) {
    const toggle=(id)=>onChange(selected.includes(id)?selected.filter(x=>x!==id):[...selected,id]);
    return <div className="grid grid-cols-2 md:grid-cols-4 gap-1 max-h-44 overflow-auto border border-line rounded-lg p-2">{cities.map(c=><label key={c.id} className="text-xs text-content-muted flex gap-1.5 items-center"><input type="checkbox" checked={selected.includes(c.id)} onChange={()=>toggle(c.id)} />{c.name}</label>)}</div>;
}
function WarehouseCard({ warehouse, cities }) {
    const area=useForm({service_city_ids:(warehouse.service_cities||[]).map(c=>c.id)});
    return <div className="bg-surface-2 border border-line rounded-xl p-4 space-y-3"><div><div className="font-semibold">{warehouse.name}</div><div className="text-sm text-content-muted">{warehouse.city || 'No city'}</div><div className="text-xs mt-2">Clients: {(warehouse.accessible_organizations||[]).map(o=>o.name).join(', ') || 'none'}</div></div><CityChecks cities={cities} selected={area.data.service_city_ids} onChange={(ids)=>area.setData('service_city_ids',ids)} /><button onClick={()=>area.put(`/agency/warehouses/${warehouse.id}/service-areas`,{preserveScroll:true})} className="text-xs px-3 py-1.5 rounded-lg bg-surface-3 border border-line">Save service areas</button></div>;
}
