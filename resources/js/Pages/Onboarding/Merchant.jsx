import { useState, useMemo } from 'react';
import { router } from '@inertiajs/react';
import { Store, Globe, Phone, Building2, Warehouse, Package, ShoppingBag, CheckCircle2, Sparkles, Plus, Trash2 } from 'lucide-react';
import OnboardingShell from '@/Components/Onboarding/OnboardingShell';
import WizardFooter from '@/Components/Onboarding/WizardFooter';
import Field from '@/Components/Onboarding/Field';
import Select from '@/Components/Onboarding/Select';

const STEPS = ['Your organization', 'Brand / store', 'Where do you keep stock?', 'Inventory & sales channels', "You're all set!"];

function initialStep(progress) {
    if (! progress.organization) return 1;
    if (! progress.store) return 2;
    if (! progress.warehouse_mode) return 3;
    if (! progress.setup) return 4;
    return 5;
}

export default function Merchant({ progress, businessTypes = [], countries = [], platforms = [], inventorySources = [], cities = [] }) {
    const [step, setStep] = useState(() => initialStep(progress));
    const [busy, setBusy] = useState(false);

    const post = (url, data, onSuccess) => {
        setBusy(true);
        router.post(url, data, { preserveScroll: true, onSuccess: () => { onSuccess?.(); }, onFinish: () => setBusy(false) });
    };

    return (
        <OnboardingShell title="Set up your business" steps={STEPS} currentStep={step}>
            {step === 1 && <OrganizationStep progress={progress} countries={countries} busy={busy} onNext={(d) => post('/onboarding/merchant/organization', d, () => setStep(2))} />}
            {step === 2 && <StoreStep progress={progress} businessTypes={businessTypes} busy={busy} onBack={() => setStep(1)} onNext={(d) => post('/onboarding/merchant/store', d, () => setStep(3))} />}
            {step === 3 && <WarehouseStep progress={progress} cities={cities} busy={busy} onBack={() => setStep(2)} onNext={(d) => post('/onboarding/merchant/warehouses', d, () => setStep(4))} />}
            {step === 4 && <SetupStep progress={progress} inventorySources={inventorySources} platforms={platforms} busy={busy} onBack={() => setStep(3)} onNext={(d) => post('/onboarding/merchant/setup', d, () => setStep(5))} />}
            {step === 5 && <ReviewStep progress={progress} businessTypes={businessTypes} countries={countries} inventorySources={inventorySources} platforms={platforms} busy={busy} onBack={() => setStep(4)} onLaunch={() => post('/onboarding/merchant/complete', {})} />}
        </OnboardingShell>
    );
}

function OrganizationStep({ progress, countries, busy, onNext }) {
    const o = progress.organization ?? {};
    const [name, setName] = useState(o.name ?? '');
    const [country, setCountry] = useState(o.country ?? countries[0]?.code ?? '');
    const [currency, setCurrency] = useState(o.currency ?? countries[0]?.currency ?? '');
    const [phone, setPhone] = useState(o.phone ?? '');

    const onCountryChange = (code) => {
        setCountry(code);
        setCurrency(countries.find((c) => c.code === code)?.currency ?? currency);
    };

    const canContinue = name.trim().length > 0 && !! country && !! currency;

    return (
        <div className="mt-6 space-y-5">
            <Field label="Business name" icon={Building2} placeholder="My Company" value={name} onChange={setName} required />
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Select label="Country" icon={Globe} value={country} onChange={onCountryChange} options={countries.map((c) => ({ value: c.code, label: c.name }))} placeholder="Choose your country" required />
                <Field label="Currency" value={currency} onChange={(v) => setCurrency(v.toUpperCase().slice(0, 3))} required />
            </div>
            <Field label="Phone (optional)" icon={Phone} type="tel" placeholder="+1 555 555 5555" value={phone} onChange={setPhone} />

            <WizardFooter onContinue={() => onNext({ name, country, currency, phone })} disabled={! canContinue} busy={busy} />
        </div>
    );
}

function StoreStep({ progress, businessTypes, busy, onBack, onNext }) {
    const s = progress.store ?? {};
    const [name, setName] = useState(s.name ?? '');
    const [businessType, setBusinessType] = useState(s.type ?? '');

    const canContinue = name.trim().length > 0 && !! businessType;

    return (
        <div className="mt-6 space-y-5">
            <Field label="Brand / store name" icon={Store} placeholder="My Store" value={name} onChange={setName} required />
            <Select label="Business type" icon={ShoppingBag} value={businessType} onChange={setBusinessType} options={businessTypes} placeholder="Choose a type" required />

            <WizardFooter onBack={onBack} onContinue={() => onNext({ name, business_type: businessType })} disabled={! canContinue} busy={busy} />
        </div>
    );
}

function WarehouseStep({ progress, cities, busy, onBack, onNext }) {
    const [mode, setMode] = useState(progress.warehouse_mode ?? 'default');
    const [rows, setRows] = useState([{ name: '', city: '', service_city_ids: [] }]);

    const addRow = () => setRows((r) => [...r, { name: '', city: '', service_city_ids: [] }]);
    const removeRow = (i) => setRows((r) => r.filter((_, idx) => idx !== i));
    const updateRow = (i, patch) => setRows((r) => r.map((row, idx) => (idx === i ? { ...row, ...patch } : row)));

    const submit = () => onNext({ mode, warehouses: mode === 'multiple' ? rows : undefined });

    return (
        <div className="mt-6 space-y-4">
            <p className="text-sm text-slate-400">Where do you keep your stock?</p>

            {[
                { value: 'default', label: 'I use one default warehouse' },
                { value: 'multiple', label: 'I have multiple warehouses' },
                { value: 'none', label: 'I do not manage stock yet' },
            ].map((opt) => (
                <label key={opt.value} className={`flex items-center gap-3 px-4 py-3 rounded-xl border cursor-pointer transition ${mode === opt.value ? 'border-indigo-500/40 bg-indigo-500/10' : 'border-[#2A2D3A] hover:border-[#3A3D4A] bg-[#0F1117]'}`}>
                    <input type="radio" checked={mode === opt.value} onChange={() => setMode(opt.value)} className="text-indigo-600 focus:ring-indigo-500" />
                    <Warehouse className="w-4 h-4 text-slate-500" />
                    <span className={`text-sm ${mode === opt.value ? 'text-white font-medium' : 'text-slate-300'}`}>{opt.label}</span>
                </label>
            ))}

            {mode === 'multiple' && (
                <div className="mt-4 space-y-3">
                    {rows.map((row, i) => (
                        <div key={i} className="p-3 rounded-lg border border-[#2A2D3A] bg-[#0F1117] space-y-2">
                            <div className="flex gap-2">
                                <input
                                    value={row.name}
                                    onChange={(e) => updateRow(i, { name: e.target.value })}
                                    placeholder="Warehouse name"
                                    className="flex-1 px-3 py-2 text-sm rounded-lg bg-[#1A1D27] border border-[#2A2D3A] text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500"
                                />
                                <input
                                    value={row.city}
                                    onChange={(e) => updateRow(i, { city: e.target.value })}
                                    placeholder="City"
                                    className="w-40 px-3 py-2 text-sm rounded-lg bg-[#1A1D27] border border-[#2A2D3A] text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500"
                                />
                                {rows.length > 1 && (
                                    <button type="button" onClick={() => removeRow(i)} className="px-2 text-slate-500 hover:text-red-400">
                                        <Trash2 className="w-4 h-4" />
                                    </button>
                                )}
                            </div>
                            {cities.length > 0 && (
                                <select
                                    multiple
                                    value={row.service_city_ids}
                                    onChange={(e) => updateRow(i, { service_city_ids: Array.from(e.target.selectedOptions, (o) => o.value) })}
                                    className="w-full px-3 py-2 text-xs rounded-lg bg-[#1A1D27] border border-[#2A2D3A] text-slate-300"
                                    size={Math.min(4, cities.length)}
                                >
                                    {cities.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                            )}
                        </div>
                    ))}
                    <button type="button" onClick={addRow} className="inline-flex items-center gap-1.5 text-sm text-indigo-400 hover:text-indigo-300">
                        <Plus className="w-4 h-4" /> Add another warehouse
                    </button>
                </div>
            )}

            <WizardFooter onBack={onBack} onSkip={() => onNext({ mode: 'none' })} onContinue={submit} busy={busy} />
        </div>
    );
}

function SetupStep({ progress, inventorySources, platforms, busy, onBack, onNext }) {
    const [inventorySource, setInventorySource] = useState(progress.setup?.inventory_source ?? '');
    const [channels, setChannels] = useState(progress.setup?.sales_channels ?? []);

    const toggle = (value) => setChannels((c) => (c.includes(value) ? c.filter((v) => v !== value) : [...c, value]));

    return (
        <div className="mt-6 space-y-6">
            <div>
                <p className="text-sm text-slate-400 mb-3">How do you want to initialize inventory?</p>
                <div className="space-y-2">
                    {inventorySources.map((o) => (
                        <label key={o.value} className={`flex items-center gap-3 px-4 py-2.5 rounded-xl border cursor-pointer transition ${inventorySource === o.value ? 'border-indigo-500/40 bg-indigo-500/10' : 'border-[#2A2D3A] hover:border-[#3A3D4A] bg-[#0F1117]'}`}>
                            <input type="radio" checked={inventorySource === o.value} onChange={() => setInventorySource(o.value)} className="text-indigo-600 focus:ring-indigo-500" />
                            <Package className="w-4 h-4 text-slate-500" />
                            <span className={`text-sm ${inventorySource === o.value ? 'text-white font-medium' : 'text-slate-300'}`}>{o.label}</span>
                        </label>
                    ))}
                </div>
                {inventorySource && inventorySource !== 'empty' && inventorySource !== 'csv' && (
                    <p className="mt-2 text-[11px] text-slate-500 italic">We'll get this connected from your dashboard next — not required to finish setup.</p>
                )}
            </div>

            <div>
                <p className="text-sm text-slate-400 mb-3">Where do you sell?</p>
                <div className="space-y-2">
                    {platforms.map((p) => {
                        const checked = channels.includes(p.value);
                        return (
                            <label key={p.value} className={`flex items-center gap-3 px-4 py-2.5 rounded-xl border cursor-pointer transition ${checked ? 'border-indigo-500/40 bg-indigo-500/10' : 'border-[#2A2D3A] hover:border-[#3A3D4A] bg-[#0F1117]'}`}>
                                <input type="checkbox" checked={checked} onChange={() => toggle(p.value)} className="rounded text-indigo-600 focus:ring-indigo-500" />
                                <span className={`text-sm ${checked ? 'text-white font-medium' : 'text-slate-300'}`}>{p.label}</span>
                                {checked && <CheckCircle2 className="ml-auto w-4 h-4 text-indigo-400" />}
                            </label>
                        );
                    })}
                </div>
                <p className="mt-2 text-[11px] text-slate-500 italic">Don't worry — you can connect any of these later from Integrations.</p>
            </div>

            <WizardFooter onBack={onBack} onSkip={() => onNext({ inventory_source: null, sales_channels: [] })} onContinue={() => onNext({ inventory_source: inventorySource || null, sales_channels: channels })} busy={busy} />
        </div>
    );
}

function ReviewStep({ progress, businessTypes, countries, inventorySources, platforms, busy, onBack, onLaunch }) {
    const label = (list, value) => list.find((x) => x.value === value)?.label ?? value ?? '—';
    const countryName = countries.find((c) => c.code === progress.organization?.country)?.name ?? progress.organization?.country;

    return (
        <div className="mt-6 space-y-4">
            <div className="bg-[#0F1117] border border-[#2A2D3A] rounded-xl p-5">
                <div className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Summary</div>
                <dl className="grid grid-cols-2 gap-y-3 text-sm">
                    <dt className="text-slate-500">Organization</dt>
                    <dd className="text-slate-200 font-medium text-right">{progress.organization?.name ?? '—'}</dd>
                    <dt className="text-slate-500">Country / currency</dt>
                    <dd className="text-slate-200 text-right">{countryName ?? '—'} · {progress.organization?.currency ?? '—'}</dd>
                    <dt className="text-slate-500">Brand / store</dt>
                    <dd className="text-slate-200 text-right">{progress.store?.name ?? '—'} ({label(businessTypes, progress.store?.type)})</dd>
                    <dt className="text-slate-500">Warehouses</dt>
                    <dd className="text-slate-200 text-right capitalize">{progress.warehouse_mode ?? '—'}</dd>
                    <dt className="text-slate-500">Inventory source</dt>
                    <dd className="text-slate-200 text-right">{progress.setup?.inventory_source ? label(inventorySources, progress.setup.inventory_source) : 'Not chosen'}</dd>
                    <dt className="text-slate-500">Sales channels</dt>
                    <dd className="text-slate-200 text-right">{(progress.setup?.sales_channels ?? []).length === 0 ? 'None selected' : progress.setup.sales_channels.map((c) => label(platforms, c)).join(', ')}</dd>
                </dl>
            </div>

            <div className="bg-indigo-500/10 border border-indigo-500/30 rounded-xl p-5">
                <div className="flex items-center gap-2 text-indigo-300 text-sm font-semibold">
                    <Sparkles className="w-4 h-4" /> Next steps
                </div>
                <ul className="mt-3 space-y-2 text-sm text-slate-300">
                    <li className="flex items-start gap-2"><CheckCircle2 className="w-4 h-4 text-indigo-400 mt-0.5 flex-shrink-0" />Add your first products.</li>
                    <li className="flex items-start gap-2"><CheckCircle2 className="w-4 h-4 text-indigo-400 mt-0.5 flex-shrink-0" />Connect your storefronts to sync stock & orders.</li>
                    <li className="flex items-start gap-2"><CheckCircle2 className="w-4 h-4 text-indigo-400 mt-0.5 flex-shrink-0" />Invite your team.</li>
                </ul>
            </div>

            <WizardFooter onBack={onBack} onContinue={onLaunch} continueLabel={busy ? 'Launching…' : 'Go to dashboard'} busy={busy} finalStep />
        </div>
    );
}
