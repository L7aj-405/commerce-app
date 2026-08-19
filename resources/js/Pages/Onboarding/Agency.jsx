import { useState, useMemo } from 'react';
import { router } from '@inertiajs/react';
import { Building2, Globe, Phone, Warehouse, Users, Package, CheckCircle2, Sparkles, Store as StoreIcon } from 'lucide-react';
import OnboardingShell from '@/Components/Onboarding/OnboardingShell';
import WizardFooter from '@/Components/Onboarding/WizardFooter';
import Field from '@/Components/Onboarding/Field';
import Select from '@/Components/Onboarding/Select';

const TITLES = {
    organization: 'Your agency',
    services: 'What services do you provide to clients?',
    warehouses: 'Agency warehouses',
    client: 'Add your first client',
    client_warehouse: 'Assign a warehouse to this client',
    client_services: 'Who operates this client\'s services?',
    client_setup: "This client's inventory & sales channels",
    review: 'Review & open workspace',
};

function nextKey(key, ctx) {
    switch (key) {
        case 'organization':      return 'services';
        case 'services':          return ctx.servicesOffered.includes('warehousing') ? 'warehouses' : 'client';
        case 'warehouses':        return 'client';
        case 'client':            return ctx.clientAdded ? (ctx.agencyHasWarehouses ? 'client_warehouse' : 'client_services') : 'review';
        case 'client_warehouse':  return 'client_services';
        case 'client_services':   return 'client_setup';
        case 'client_setup':      return 'review';
        default:                  return 'review';
    }
}

function initialKey(progress) {
    if (! progress.organization) return 'organization';
    if (! progress.client) return 'client';
    return 'review';
}

export default function Agency({ progress, countries = [], businessTypes = [], platforms = [], inventorySources = [], agencyServices = [], cities = [] }) {
    const [step, setStep] = useState(() => initialKey(progress));
    const [history, setHistory] = useState([]);
    const [busy, setBusy] = useState(false);
    const [servicesOffered, setServicesOffered] = useState(progress.services_offered ?? []);
    const [clientAdded, setClientAdded] = useState(!! progress.client);

    const agencyHasWarehouses = (progress.warehouses ?? []).length > 0;
    const ctx = { servicesOffered, clientAdded, agencyHasWarehouses };

    const goNext = () => {
        setHistory((h) => [...h, step]);
        setStep(nextKey(step, ctx));
    };
    const goBack = () => setHistory((h) => {
        const prev = h[h.length - 1];
        if (prev) setStep(prev);
        return h.slice(0, -1);
    });

    const post = (url, data, after) => {
        setBusy(true);
        router.post(url, data, { preserveScroll: true, onSuccess: () => after?.(), onFinish: () => setBusy(false) });
    };

    const stepTitles = useMemo(() => {
        const t = [TITLES.organization, TITLES.services];
        if (servicesOffered.includes('warehousing')) t.push(TITLES.warehouses);
        t.push(TITLES.client);
        if (clientAdded) {
            if (agencyHasWarehouses) t.push(TITLES.client_warehouse);
            t.push(TITLES.client_services, TITLES.client_setup);
        }
        t.push(TITLES.review);
        return t;
    }, [servicesOffered, clientAdded, agencyHasWarehouses]);

    const currentIndex = Math.max(1, stepTitles.indexOf(TITLES[step]) + 1);

    return (
        <OnboardingShell title="Set up your agency" steps={stepTitles} currentStep={currentIndex}>
            {step === 'organization' && (
                <OrganizationStep progress={progress} countries={countries} busy={busy}
                    onNext={(d) => post('/onboarding/agency/organization', d, goNext)} />
            )}
            {step === 'services' && (
                <ServicesStep progress={progress} agencyServices={agencyServices} busy={busy}
                    onBack={goBack}
                    onNext={(chosen) => { setServicesOffered(chosen); post('/onboarding/agency/services', { services: chosen }, () => { setHistory((h) => [...h, step]); setStep(nextKey(step, { ...ctx, servicesOffered: chosen })); }); }} />
            )}
            {step === 'warehouses' && (
                <WarehousesStep cities={cities} busy={busy} onBack={goBack}
                    onSkip={() => post('/onboarding/agency/warehouses', { warehouses: [] }, goNext)}
                    onNext={(rows) => post('/onboarding/agency/warehouses', { warehouses: rows }, goNext)} />
            )}
            {step === 'client' && (
                <ClientStep businessTypes={businessTypes} countries={countries} busy={busy} onBack={goBack}
                    onSkip={() => { setClientAdded(false); setHistory((h) => [...h, step]); setStep('review'); }}
                    onNext={(d) => post('/onboarding/agency/client', d, () => { setClientAdded(true); setHistory((h) => [...h, step]); setStep(nextKey(step, { ...ctx, clientAdded: true })); })} />
            )}
            {step === 'client_warehouse' && (
                <ClientWarehouseStep warehouses={progress.warehouses ?? []} busy={busy} onBack={goBack}
                    onSkip={goNext}
                    onNext={(d) => post('/onboarding/agency/client/warehouse', d, goNext)} />
            )}
            {step === 'client_services' && (
                <ClientServicesStep progress={progress} agencyName={progress.organization?.name} busy={busy} onBack={goBack}
                    onNext={(d) => post('/onboarding/agency/client/services', { assignments: d }, goNext)} />
            )}
            {step === 'client_setup' && (
                <ClientSetupStep progress={progress} inventorySources={inventorySources} platforms={platforms} busy={busy} onBack={goBack}
                    onSkip={() => post('/onboarding/agency/client/setup', { inventory_source: null, sales_channels: [] }, goNext)}
                    onNext={(d) => post('/onboarding/agency/client/setup', d, goNext)} />
            )}
            {step === 'review' && (
                <ReviewStep progress={progress} clientAdded={clientAdded} businessTypes={businessTypes} inventorySources={inventorySources} platforms={platforms} busy={busy} onBack={goBack}
                    onOpen={() => post('/onboarding/agency/complete', {})} />
            )}
        </OnboardingShell>
    );
}

function OrganizationStep({ progress, countries, busy, onNext }) {
    const o = progress.organization ?? {};
    const [name, setName] = useState(o.name ?? '');
    const [country, setCountry] = useState(o.country ?? countries[0]?.code ?? '');
    const [currency, setCurrency] = useState(o.currency ?? countries[0]?.currency ?? '');
    const [phone, setPhone] = useState(o.phone ?? '');

    const onCountryChange = (code) => { setCountry(code); setCurrency(countries.find((c) => c.code === code)?.currency ?? currency); };
    const canContinue = name.trim().length > 0 && !! country && !! currency;

    return (
        <div className="mt-6 space-y-5">
            <Field label="Agency name" icon={Building2} placeholder="My Agency" value={name} onChange={setName} required />
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Select label="Country" icon={Globe} value={country} onChange={onCountryChange} options={countries.map((c) => ({ value: c.code, label: c.name }))} placeholder="Choose your country" required />
                <Field label="Currency" value={currency} onChange={(v) => setCurrency(v.toUpperCase().slice(0, 3))} required />
            </div>
            <Field label="Phone (optional)" icon={Phone} type="tel" value={phone} onChange={setPhone} />
            <WizardFooter onContinue={() => onNext({ name, country, currency, phone })} disabled={! canContinue} busy={busy} />
        </div>
    );
}

function ServicesStep({ progress, agencyServices, busy, onBack, onNext }) {
    const [chosen, setChosen] = useState(progress.services_offered ?? []);
    const toggle = (v) => setChosen((c) => (c.includes(v) ? c.filter((x) => x !== v) : [...c, v]));

    return (
        <div className="mt-6 space-y-4">
            <p className="text-sm text-slate-400">Pick everything your agency provides today — you can change this later.</p>
            <div className="space-y-2">
                {agencyServices.map((s) => {
                    const checked = chosen.includes(s.value);
                    return (
                        <label key={s.value} className={`flex items-center gap-3 px-4 py-3 rounded-xl border cursor-pointer transition ${checked ? 'border-indigo-500/40 bg-indigo-500/10' : 'border-[#2A2D3A] hover:border-[#3A3D4A] bg-[#0F1117]'}`}>
                            <input type="checkbox" checked={checked} onChange={() => toggle(s.value)} className="rounded text-indigo-600 focus:ring-indigo-500" />
                            <span className={`text-sm ${checked ? 'text-white font-medium' : 'text-slate-300'}`}>{s.label}</span>
                            {checked && <CheckCircle2 className="ml-auto w-4 h-4 text-indigo-400" />}
                        </label>
                    );
                })}
            </div>
            {chosen.includes('warehousing') && (
                <p className="text-[11px] text-slate-500 italic">Picking, packing and dispatch aren't separate toggles — they follow whichever warehouse physically holds the stock.</p>
            )}
            <WizardFooter onBack={onBack} onContinue={() => onNext(chosen)} busy={busy} />
        </div>
    );
}

function WarehousesStep({ cities, busy, onBack, onSkip, onNext }) {
    const [rows, setRows] = useState([{ name: '', city: '', service_city_ids: [] }]);
    const addRow = () => setRows((r) => [...r, { name: '', city: '', service_city_ids: [] }]);
    const updateRow = (i, patch) => setRows((r) => r.map((row, idx) => (idx === i ? { ...row, ...patch } : row)));

    return (
        <div className="mt-6 space-y-3">
            <p className="text-sm text-slate-400">Warehouses your agency owns and operates.</p>
            {rows.map((row, i) => (
                <div key={i} className="p-3 rounded-lg border border-[#2A2D3A] bg-[#0F1117] space-y-2">
                    <div className="flex gap-2">
                        <input value={row.name} onChange={(e) => updateRow(i, { name: e.target.value })} placeholder="Warehouse name"
                            className="flex-1 px-3 py-2 text-sm rounded-lg bg-[#1A1D27] border border-[#2A2D3A] text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500" />
                        <input value={row.city} onChange={(e) => updateRow(i, { city: e.target.value })} placeholder="City"
                            className="w-40 px-3 py-2 text-sm rounded-lg bg-[#1A1D27] border border-[#2A2D3A] text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500" />
                    </div>
                    {cities.length > 0 && (
                        <select multiple value={row.service_city_ids} onChange={(e) => updateRow(i, { service_city_ids: Array.from(e.target.selectedOptions, (o) => o.value) })}
                            className="w-full px-3 py-2 text-xs rounded-lg bg-[#1A1D27] border border-[#2A2D3A] text-slate-300" size={Math.min(4, cities.length)}>
                            {cities.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    )}
                </div>
            ))}
            <button type="button" onClick={addRow} className="text-sm text-indigo-400 hover:text-indigo-300">+ Add another warehouse</button>
            <WizardFooter onBack={onBack} onSkip={onSkip} onContinue={() => onNext(rows.filter((r) => r.name.trim()))} busy={busy} />
        </div>
    );
}

function ClientStep({ businessTypes, countries, busy, onBack, onSkip, onNext }) {
    const [clientName, setClientName] = useState('');
    const [brandName, setBrandName] = useState('');
    const [businessType, setBusinessType] = useState('');
    const [country, setCountry] = useState(countries[0]?.code ?? '');
    const [currency, setCurrency] = useState(countries[0]?.currency ?? '');

    const onCountryChange = (code) => { setCountry(code); setCurrency(countries.find((c) => c.code === code)?.currency ?? currency); };
    const canContinue = clientName.trim() && brandName.trim() && businessType && country && currency;

    return (
        <div className="mt-6 space-y-5">
            <p className="text-sm text-slate-400">Do you want to add your first client now?</p>
            <Field label="Client business name" icon={Users} value={clientName} onChange={setClientName} required />
            <Field label="Brand / store name" icon={StoreIcon} value={brandName} onChange={setBrandName} required />
            <Select label="Business type" value={businessType} onChange={setBusinessType} options={businessTypes} placeholder="Choose a type" required />
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Select label="Country" icon={Globe} value={country} onChange={onCountryChange} options={countries.map((c) => ({ value: c.code, label: c.name }))} placeholder="Choose country" required />
                <Field label="Currency" value={currency} onChange={(v) => setCurrency(v.toUpperCase().slice(0, 3))} required />
            </div>
            <WizardFooter onBack={onBack} onSkip={onSkip}
                onContinue={() => onNext({ client_name: clientName, brand_name: brandName, business_type: businessType, country, currency })}
                disabled={! canContinue} busy={busy} />
        </div>
    );
}

function ClientWarehouseStep({ warehouses, busy, onBack, onSkip, onNext }) {
    const [mode, setMode] = useState('assign_agency');
    const [warehouseId, setWarehouseId] = useState(warehouses[0]?.id ?? '');
    const [name, setName] = useState('');
    const [city, setCity] = useState('');

    return (
        <div className="mt-6 space-y-4">
            <label className={`flex items-center gap-3 px-4 py-3 rounded-xl border cursor-pointer transition ${mode === 'assign_agency' ? 'border-indigo-500/40 bg-indigo-500/10' : 'border-[#2A2D3A] bg-[#0F1117]'}`}>
                <input type="radio" checked={mode === 'assign_agency'} onChange={() => setMode('assign_agency')} className="text-indigo-600" />
                <Warehouse className="w-4 h-4 text-slate-500" />
                <span className="text-sm text-slate-200">Use an agency warehouse</span>
            </label>
            {mode === 'assign_agency' && (
                <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} className="w-full px-3 py-2 text-sm rounded-lg bg-[#0F1117] border border-[#2A2D3A] text-slate-200">
                    {warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                </select>
            )}

            <label className={`flex items-center gap-3 px-4 py-3 rounded-xl border cursor-pointer transition ${mode === 'client_owned' ? 'border-indigo-500/40 bg-indigo-500/10' : 'border-[#2A2D3A] bg-[#0F1117]'}`}>
                <input type="radio" checked={mode === 'client_owned'} onChange={() => setMode('client_owned')} className="text-indigo-600" />
                <Warehouse className="w-4 h-4 text-slate-500" />
                <span className="text-sm text-slate-200">Client manages its own warehouse</span>
            </label>
            {mode === 'client_owned' && (
                <div className="flex gap-2">
                    <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Warehouse name" className="flex-1 px-3 py-2 text-sm rounded-lg bg-[#0F1117] border border-[#2A2D3A] text-slate-200 placeholder:text-slate-500" />
                    <input value={city} onChange={(e) => setCity(e.target.value)} placeholder="City" className="w-40 px-3 py-2 text-sm rounded-lg bg-[#0F1117] border border-[#2A2D3A] text-slate-200 placeholder:text-slate-500" />
                </div>
            )}

            <WizardFooter onBack={onBack} onSkip={onSkip}
                onContinue={() => onNext(mode === 'assign_agency' ? { mode, warehouse_id: warehouseId } : { mode, name, city })}
                disabled={mode === 'assign_agency' ? ! warehouseId : ! name.trim()} busy={busy} />
        </div>
    );
}

function ClientServicesStep({ progress, agencyName, busy, onBack, onNext }) {
    const codes = [
        { value: 'confirmation', label: 'Confirmation' },
        { value: 'customer_support', label: 'Customer support' },
        { value: 'delivery', label: 'Delivery' },
    ];
    const [assignments, setAssignments] = useState(() => Object.fromEntries(codes.map((c) => [c.value, progress.client_services?.[c.value] ?? 'self'])));

    return (
        <div className="mt-6 space-y-4">
            <p className="text-sm text-slate-400">Picking, packing and dispatch aren't listed here — they're determined by which warehouse operates the client's stock.</p>
            {codes.map((c) => (
                <div key={c.value} className="flex items-center justify-between px-4 py-3 rounded-xl border border-[#2A2D3A] bg-[#0F1117]">
                    <span className="text-sm text-slate-200">{c.label}</span>
                    <div className="flex items-center gap-1 p-1 rounded-lg bg-[#1A1D27] border border-[#2A2D3A]">
                        {['self', 'agency'].map((op) => (
                            <button key={op} type="button" onClick={() => setAssignments((a) => ({ ...a, [c.value]: op }))}
                                className={`px-3 py-1 text-xs font-medium rounded-md transition ${assignments[c.value] === op ? 'bg-indigo-600 text-white' : 'text-slate-400'}`}>
                                {op === 'self' ? 'Client' : (agencyName || 'Agency')}
                            </button>
                        ))}
                    </div>
                </div>
            ))}
            <WizardFooter onBack={onBack} onContinue={() => onNext(assignments)} busy={busy} />
        </div>
    );
}

function ClientSetupStep({ progress, inventorySources, platforms, busy, onBack, onSkip, onNext }) {
    const [inventorySource, setInventorySource] = useState(progress.client_setup?.inventory_source ?? '');
    const [channels, setChannels] = useState(progress.client_setup?.sales_channels ?? []);
    const toggle = (v) => setChannels((c) => (c.includes(v) ? c.filter((x) => x !== v) : [...c, v]));

    return (
        <div className="mt-6 space-y-6">
            <div>
                <p className="text-sm text-slate-400 mb-3">How should this client initialize inventory?</p>
                <div className="space-y-2">
                    {inventorySources.map((o) => (
                        <label key={o.value} className={`flex items-center gap-3 px-4 py-2.5 rounded-xl border cursor-pointer transition ${inventorySource === o.value ? 'border-indigo-500/40 bg-indigo-500/10' : 'border-[#2A2D3A] bg-[#0F1117]'}`}>
                            <input type="radio" checked={inventorySource === o.value} onChange={() => setInventorySource(o.value)} className="text-indigo-600" />
                            <Package className="w-4 h-4 text-slate-500" />
                            <span className="text-sm text-slate-300">{o.label}</span>
                        </label>
                    ))}
                </div>
            </div>
            <div>
                <p className="text-sm text-slate-400 mb-3">Where does this client sell?</p>
                <div className="space-y-2">
                    {platforms.map((p) => {
                        const checked = channels.includes(p.value);
                        return (
                            <label key={p.value} className={`flex items-center gap-3 px-4 py-2.5 rounded-xl border cursor-pointer transition ${checked ? 'border-indigo-500/40 bg-indigo-500/10' : 'border-[#2A2D3A] bg-[#0F1117]'}`}>
                                <input type="checkbox" checked={checked} onChange={() => toggle(p.value)} className="rounded text-indigo-600" />
                                <span className="text-sm text-slate-300">{p.label}</span>
                            </label>
                        );
                    })}
                </div>
            </div>
            <WizardFooter onBack={onBack} onSkip={onSkip} onContinue={() => onNext({ inventory_source: inventorySource || null, sales_channels: channels })} busy={busy} />
        </div>
    );
}

function ReviewStep({ progress, clientAdded, businessTypes, inventorySources, platforms, busy, onBack, onOpen }) {
    const label = (list, value) => list.find((x) => x.value === value)?.label ?? value ?? '—';

    return (
        <div className="mt-6 space-y-4">
            <div className="bg-[#0F1117] border border-[#2A2D3A] rounded-xl p-5">
                <div className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Summary</div>
                <dl className="grid grid-cols-2 gap-y-3 text-sm">
                    <dt className="text-slate-500">Agency</dt>
                    <dd className="text-slate-200 font-medium text-right">{progress.organization?.name ?? '—'}</dd>
                    <dt className="text-slate-500">Services offered</dt>
                    <dd className="text-slate-200 text-right">{(progress.services_offered ?? []).join(', ') || 'None'}</dd>
                    <dt className="text-slate-500">Agency warehouses</dt>
                    <dd className="text-slate-200 text-right">{(progress.warehouses ?? []).length}</dd>
                    {clientAdded && (
                        <>
                            <dt className="text-slate-500">Client</dt>
                            <dd className="text-slate-200 text-right">{progress.client?.name ?? '—'}</dd>
                            <dt className="text-slate-500">Client brand</dt>
                            <dd className="text-slate-200 text-right">{progress.client_store?.name ?? '—'} ({label(businessTypes, progress.client_store?.type)})</dd>
                            <dt className="text-slate-500">Client inventory source</dt>
                            <dd className="text-slate-200 text-right">{progress.client_setup?.inventory_source ? label(inventorySources, progress.client_setup.inventory_source) : 'Not chosen'}</dd>
                            <dt className="text-slate-500">Client sales channels</dt>
                            <dd className="text-slate-200 text-right">{(progress.client_setup?.sales_channels ?? []).length === 0 ? 'None' : progress.client_setup.sales_channels.map((c) => label(platforms, c)).join(', ')}</dd>
                        </>
                    )}
                </dl>
            </div>

            <div className="bg-indigo-500/10 border border-indigo-500/30 rounded-xl p-5">
                <div className="flex items-center gap-2 text-indigo-300 text-sm font-semibold"><Sparkles className="w-4 h-4" /> Next steps</div>
                <ul className="mt-3 space-y-2 text-sm text-slate-300">
                    <li className="flex items-start gap-2"><CheckCircle2 className="w-4 h-4 text-indigo-400 mt-0.5 flex-shrink-0" />Add more clients from the agency workspace.</li>
                    <li className="flex items-start gap-2"><CheckCircle2 className="w-4 h-4 text-indigo-400 mt-0.5 flex-shrink-0" />Invite your team.</li>
                </ul>
            </div>

            <footer className="mt-8 pt-6 border-t border-[#2A2D3A] flex items-center justify-between">
                <button type="button" onClick={onBack} disabled={busy} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm rounded-lg border border-[#2A2D3A] text-slate-300 hover:bg-[#22252F] transition">Back</button>
                <button type="button" onClick={onOpen} disabled={busy} className="inline-flex items-center gap-1.5 px-5 py-2 text-sm font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-50 transition">
                    {busy ? 'Opening…' : 'Go to Agency Workspace'}
                </button>
            </footer>
        </div>
    );
}
