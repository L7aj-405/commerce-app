import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Truck, Plus, Trash2, X, Wallet, Landmark, MapPinned, Info } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import Button from '@/Components/Button';
import EmptyState from '@/Components/EmptyState';
import DataTable from '@/Components/DataTable';
import StatusBadge from '@/Components/StatusBadge';
import CitySearchSelect from '@/Components/Finance/CitySearchSelect';
import { formatDateOnly } from '@/Support/formatDate';

function money(amount) {
    return `${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} MAD`;
}

const FREQUENCIES = [
    { value: 'weekly', label: 'Weekly' },
    { value: 'biweekly', label: 'Every 2 weeks' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'daily', label: 'Daily / 24h', hint: 'Orders delivered during the day are expected to be paid within 24h or according to the payout delay.' },
    { value: 'instant', label: 'Instant / Same day', hint: 'Delivered orders are ready for reconciliation immediately. Cash is recorded only after bank transfer verification.' },
];

function isDailyOrInstant(frequency) {
    return frequency === 'daily' || frequency === 'instant';
}

export default function Index({ providers, accounts, can }) {
    const [configuring, setConfiguring] = useState(null); // { provider, tab } | null

    // Compact rows only — the settings/city-fee forms live behind
    // "Configure"/"City fees", never rendered for every provider at once.
    const columns = [
        { key: 'name', label: 'Provider', render: (p) => (
            <div className="flex items-center gap-2">
                <span className="font-medium text-content">{p.name}</span>
                {! p.settings && <span className="text-xs text-warning">Not configured</span>}
            </div>
        ) },
        { key: 'cod', label: 'COD', render: (p) => <StatusBadge status={(p.settings?.is_cod_enabled ?? true) ? 'connected' : 'disabled'} type="delivery_connection" label={(p.settings?.is_cod_enabled ?? true) ? 'Enabled' : 'Disabled'} /> },
        { key: 'default_fee', label: 'Default fee', align: 'right', render: (p) => p.settings?.default_delivery_fee != null ? money(p.settings.default_delivery_fee) : <span className="text-content-muted">—</span> },
        { key: 'payout', label: 'Payout', render: (p) => p.settings
            ? `${FREQUENCIES.find((f) => f.value === p.settings.payout_frequency)?.label ?? p.settings.payout_frequency}${p.settings.payout_delay_days ? ` · +${p.settings.payout_delay_days}d` : ''}`
            : <span className="text-content-muted">—</span> },
        { key: 'account', label: 'Bank account', render: (p) => p.settings?.bank_account?.name ?? <span className="text-content-muted">Not set</span> },
        { key: 'cities', label: 'City exceptions', align: 'right', render: (p) => (
            <button type="button" onClick={() => setConfiguring({ provider: p, tab: 'cities' })} className="text-content hover:text-primary hover:underline">
                {p.city_fee_count} {p.city_fee_count === 1 ? 'city' : 'cities'}
            </button>
        ) },
        { key: 'status', label: 'Status', render: (p) => <StatusBadge status={(p.settings?.is_active ?? false) ? 'connected' : 'not_connected'} type="integration_card" label={(p.settings?.is_active ?? false) ? 'Active' : 'Inactive'} /> },
        { key: 'actions', label: '', align: 'right', render: (p) => (
            <Button variant="secondary" onClick={() => setConfiguring({ provider: p, tab: 'cod' })}>Configure</Button>
        ) },
    ];

    return (
        <SaasLayout pageHeader={{
            title: 'Delivery providers',
            subtitle: 'Default fees, COD payout schedule and city exceptions — used to calculate expected carrier fees and payout periods.',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Finance', href: '/dashboard/finance' }, { label: 'Delivery providers' }],
        }}>
            {providers.length === 0 ? (
                <EmptyState icon={Truck} title="No external delivery providers" description="Connect Ozon or Sendit under Integrations first." />
            ) : (
                <DataTable columns={columns} data={providers} emptyIcon={Truck} />
            )}

            {configuring && (
                <ConfigureModal
                    provider={configuring.provider}
                    initialTab={configuring.tab}
                    accounts={accounts}
                    canManage={can.manage}
                    canCustomCity={can.custom_city}
                    onClose={() => setConfiguring(null)}
                />
            )}
        </SaasLayout>
    );
}

const TABS = [
    { key: 'cod', label: 'COD payout settings', icon: Wallet },
    { key: 'fees', label: 'Default fees', icon: Landmark },
    { key: 'cities', label: 'City fee exceptions', icon: MapPinned },
];

function ConfigureModal({ provider, initialTab, accounts, canManage, canCustomCity, onClose }) {
    const [tab, setTab] = useState(initialTab ?? 'cod');
    const settings = provider.settings;

    const { data, setData, patch, processing, errors } = useForm({
        default_delivery_fee: settings?.default_delivery_fee ?? '',
        default_return_fee: settings?.default_return_fee ?? '0',
        default_refusal_fee: settings?.default_refusal_fee ?? '0',
        cod_fee_fixed: settings?.cod_fee_fixed ?? '0',
        cod_fee_percent: settings?.cod_fee_percent ?? '0',
        payout_frequency: settings?.payout_frequency ?? 'weekly',
        period_anchor_date: settings?.period_anchor_date ?? '',
        payout_delay_days: settings?.payout_delay_days ?? 0,
        default_bank_account_id: settings?.default_bank_account_id ?? '',
        is_cod_enabled: settings?.is_cod_enabled ?? true,
        is_active: settings?.is_active ?? true,
    });

    const submit = (e) => {
        e.preventDefault();
        patch(`/dashboard/finance/delivery-providers/${provider.id}`, { preserveScroll: true });
    };

    return (
        <Modal title={`Configure ${provider.name}`} onClose={onClose} wide>
            <div className="flex flex-wrap gap-1.5 mb-5 border-b border-line pb-3">
                {TABS.map((t) => (
                    <button
                        key={t.key}
                        type="button"
                        onClick={() => setTab(t.key)}
                        className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg transition ${tab === t.key ? 'bg-primary text-primary-contrast' : 'text-content-muted hover:bg-surface-3 hover:text-content'}`}
                    >
                        <t.icon className="w-3.5 h-3.5" /> {t.label}
                        {t.key === 'cities' && provider.city_fee_count > 0 && <span className="opacity-70">({provider.city_fee_count})</span>}
                    </button>
                ))}
            </div>

            {(tab === 'cod' || tab === 'fees') && (
                <form onSubmit={submit} className="space-y-5">
                    {tab === 'cod' && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <Field
                                    label="Payout frequency"
                                    required
                                    error={errors.payout_frequency}
                                    hint={FREQUENCIES.find((f) => f.value === data.payout_frequency)?.hint}
                                >
                                    <select disabled={! canManage} value={data.payout_frequency} onChange={(e) => setData('payout_frequency', e.target.value)} className={inputClass(errors.payout_frequency)}>
                                        {FREQUENCIES.map((f) => <option key={f.value} value={f.value}>{f.label}</option>)}
                                    </select>
                                </Field>
                                <Field
                                    label="Period anchor date"
                                    hint={isDailyOrInstant(data.payout_frequency) ? 'Not used for Daily/Instant — periods always align to calendar days.' : 'First day of any past payout period'}
                                >
                                    <input type="date" disabled={! canManage || isDailyOrInstant(data.payout_frequency)} value={data.period_anchor_date} onChange={(e) => setData('period_anchor_date', e.target.value)} className={inputClass()} />
                                </Field>
                                <Field label="Payout delay (days)">
                                    <input type="number" min="0" max="60" disabled={! canManage} value={data.payout_delay_days} onChange={(e) => setData('payout_delay_days', e.target.value)} className={inputClass()} />
                                </Field>
                            </div>

                            <Field label="Bank account (reconciliation default)">
                                <select disabled={! canManage} value={data.default_bank_account_id} onChange={(e) => setData('default_bank_account_id', e.target.value)} className={inputClass(errors.default_bank_account_id)}>
                                    <option value="">Not set</option>
                                    {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                                </select>
                                {errors.default_bank_account_id && <p className="mt-1 text-xs text-danger">{errors.default_bank_account_id}</p>}
                            </Field>

                            <div className="flex items-center gap-6">
                                <label className="flex items-center gap-2 text-sm text-content">
                                    <input type="checkbox" disabled={! canManage} checked={data.is_cod_enabled} onChange={(e) => setData('is_cod_enabled', e.target.checked)} className="w-4 h-4 rounded border-line" />
                                    COD enabled
                                </label>
                                <label className="flex items-center gap-2 text-sm text-content">
                                    <input type="checkbox" disabled={! canManage} checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} className="w-4 h-4 rounded border-line" />
                                    Active
                                </label>
                            </div>
                        </div>
                    )}

                    {tab === 'fees' && (
                        <div className="space-y-4">
                            <p className="flex items-start gap-1.5 text-xs text-content-muted">
                                <Info className="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
                                Default fees apply to all cities unless a city exception exists (see the "City fee exceptions" tab).
                            </p>
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <Field label="Default delivery fee" required error={errors.default_delivery_fee}>
                                    <input type="number" step="0.01" min="0" disabled={! canManage} value={data.default_delivery_fee} onChange={(e) => setData('default_delivery_fee', e.target.value)} className={inputClass(errors.default_delivery_fee)} />
                                </Field>
                                <Field label="Default return fee">
                                    <input type="number" step="0.01" min="0" disabled={! canManage} value={data.default_return_fee} onChange={(e) => setData('default_return_fee', e.target.value)} className={inputClass()} />
                                </Field>
                                <Field label="Default refusal fee">
                                    <input type="number" step="0.01" min="0" disabled={! canManage} value={data.default_refusal_fee} onChange={(e) => setData('default_refusal_fee', e.target.value)} className={inputClass()} />
                                </Field>
                            </div>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <Field label="COD fee — fixed">
                                    <input type="number" step="0.01" min="0" disabled={! canManage} value={data.cod_fee_fixed} onChange={(e) => setData('cod_fee_fixed', e.target.value)} className={inputClass()} />
                                </Field>
                                <Field label="COD fee — percent">
                                    <input type="number" step="0.01" min="0" max="100" disabled={! canManage} value={data.cod_fee_percent} onChange={(e) => setData('cod_fee_percent', e.target.value)} className={inputClass()} />
                                </Field>
                            </div>
                        </div>
                    )}

                    {canManage && (
                        <div className="flex justify-end gap-2 pt-2 border-t border-line">
                            <Button type="button" variant="secondary" onClick={onClose}>Close</Button>
                            <Button type="submit" loading={processing}>Save settings</Button>
                        </div>
                    )}
                </form>
            )}

            {tab === 'cities' && <CityFeesTab provider={provider} canManage={canManage} canCustomCity={canCustomCity} onClose={onClose} />}
        </Modal>
    );
}

function CityFeesTab({ provider, canManage, canCustomCity, onClose }) {
    const [adding, setAdding] = useState(false);
    const defaultFee = provider.settings?.default_delivery_fee;

    const columns = [
        { key: 'city_name', label: 'City', render: (f) => f.city_name ?? '—' },
        { key: 'delivery_fee', label: 'Delivery', align: 'right', render: (f) => money(f.delivery_fee) },
        { key: 'return_fee', label: 'Return', align: 'right', render: (f) => money(f.return_fee) },
        { key: 'refusal_fee', label: 'Refusal', align: 'right', render: (f) => money(f.refusal_fee) },
        { key: 'cod', label: 'COD fee', align: 'right', render: (f) => Number(f.cod_fee_fixed) > 0 || Number(f.cod_fee_percent) > 0 ? `${money(f.cod_fee_fixed)} + ${f.cod_fee_percent}%` : '—' },
        { key: 'window', label: 'Effective', render: (f) => (f.starts_at || f.ends_at) ? `${f.starts_at ? formatDateOnly(f.starts_at) : '…'} → ${f.ends_at ? formatDateOnly(f.ends_at) : '…'}` : 'Always' },
        { key: 'status', label: 'Active', render: (f) => <StatusBadge status={f.is_active ? 'connected' : 'disabled'} type="delivery_connection" label={f.is_active ? 'Active' : 'Inactive'} /> },
        ...(canManage ? [{ key: 'actions', label: '', align: 'right', render: (f) => f.is_active && <DeactivateButton provider={provider} cityFee={f} /> }] : []),
    ];

    return (
        <div>
            <p className="flex items-start gap-1.5 text-xs text-content-muted mb-4">
                <Info className="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
                City fees are exceptions only — the provider default{defaultFee != null ? ` (${money(defaultFee)})` : ''} applies unless a city is added here.
            </p>

            <div className="flex items-center justify-between mb-3">
                <h4 className="text-sm font-semibold text-content">City exceptions</h4>
                {canManage && <Button variant="secondary" icon={Plus} onClick={() => setAdding((v) => ! v)}>{adding ? 'Cancel' : 'Add city fee'}</Button>}
            </div>

            {adding && <AddCityFeeForm provider={provider} canCustomCity={canCustomCity} onDone={() => setAdding(false)} />}

            {provider.city_fees.length === 0 ? (
                <EmptyState icon={MapPinned} title="No city exceptions yet" description="The provider default fee will be used for all cities." />
            ) : (
                <DataTable columns={columns} data={provider.city_fees} emptyIcon={MapPinned} />
            )}
        </div>
    );
}

function AddCityFeeForm({ provider, canCustomCity, onDone }) {
    const [useCustom, setUseCustom] = useState(false);
    const cityOptionValueKey = provider.city_options.source === 'provider_city' ? 'provider_city_id' : 'city_id';

    const { data, setData, post, processing, errors, reset } = useForm({
        city_id: '', provider_city_id: '', custom_city_name: '',
        delivery_fee: '', return_fee: '0', refusal_fee: '0',
        cod_fee_fixed: '0', cod_fee_percent: '0', starts_at: '', ends_at: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(`/dashboard/finance/delivery-providers/${provider.id}/city-fees`, {
            preserveScroll: true,
            onSuccess: () => { reset(); onDone(); },
        });
    };

    return (
        <form onSubmit={submit} className="mb-4 p-4 rounded-lg bg-surface-3 border border-line space-y-3">
            {provider.city_options.options.length === 0 && ! canCustomCity ? (
                <p className="text-sm text-warning">No synced or canonical cities available yet — ask an owner/admin to add a custom city, or sync cities under Integrations first.</p>
            ) : (
                <div className="grid grid-cols-2 gap-3">
                    <div className="col-span-2 sm:col-span-1">
                        <label className="block text-sm font-medium text-content-muted mb-1">
                            City <span className="text-danger">*</span>
                            <span className="ml-1 text-xs font-normal text-content-muted">({provider.city_options.source === 'provider_city' ? `${provider.name}'s synced cities` : 'internal city list'})</span>
                        </label>
                        {! useCustom ? (
                            <CitySearchSelect
                                options={provider.city_options.options}
                                value={data[cityOptionValueKey]}
                                onChange={(v) => setData(cityOptionValueKey, v)}
                                error={errors.city_id || errors.provider_city_id}
                            />
                        ) : (
                            <input value={data.custom_city_name} onChange={(e) => setData('custom_city_name', e.target.value)} placeholder="Type the city name" className={inputClass(errors.custom_city_name)} />
                        )}
                        {canCustomCity && (
                            <button type="button" onClick={() => { setUseCustom((v) => ! v); setData({ ...data, city_id: '', provider_city_id: '', custom_city_name: '' }); }} className="mt-1 text-xs text-content-muted hover:text-content hover:underline">
                                {useCustom ? 'Use the searchable city list instead' : "City not listed? Use a custom name (admin only)"}
                            </button>
                        )}
                    </div>
                    <Field label="Delivery fee" required error={errors.delivery_fee}>
                        <input type="number" step="0.01" min="0" value={data.delivery_fee} onChange={(e) => setData('delivery_fee', e.target.value)} className={inputClass(errors.delivery_fee)} />
                    </Field>
                </div>
            )}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <Field label="Return fee">
                    <input type="number" step="0.01" min="0" value={data.return_fee} onChange={(e) => setData('return_fee', e.target.value)} className={inputClass()} />
                </Field>
                <Field label="Refusal fee">
                    <input type="number" step="0.01" min="0" value={data.refusal_fee} onChange={(e) => setData('refusal_fee', e.target.value)} className={inputClass()} />
                </Field>
                <Field label="COD fee fixed">
                    <input type="number" step="0.01" min="0" value={data.cod_fee_fixed} onChange={(e) => setData('cod_fee_fixed', e.target.value)} className={inputClass()} />
                </Field>
                <Field label="COD fee %">
                    <input type="number" step="0.01" min="0" max="100" value={data.cod_fee_percent} onChange={(e) => setData('cod_fee_percent', e.target.value)} className={inputClass()} />
                </Field>
            </div>
            <div className="grid grid-cols-2 gap-3">
                <Field label="Starts (optional)">
                    <input type="date" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} className={inputClass()} />
                </Field>
                <Field label="Ends (optional)">
                    <input type="date" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} className={inputClass()} />
                </Field>
            </div>
            <Button type="submit" loading={processing}>Add city fee</Button>
        </form>
    );
}

function DeactivateButton({ provider, cityFee }) {
    const { delete: destroy, processing } = useForm({});
    return (
        <button
            type="button"
            disabled={processing}
            onClick={() => { if (confirm(`Deactivate the city fee for ${cityFee.city_name}?`)) destroy(`/dashboard/finance/delivery-providers/${provider.id}/city-fees/${cityFee.id}`, { preserveScroll: true }); }}
            className="p-1.5 rounded-lg text-content-muted hover:text-danger hover:bg-danger-soft"
            aria-label="Deactivate"
            title="Deactivate"
        >
            <Trash2 className="w-3.5 h-3.5" />
        </button>
    );
}

function Modal({ title, onClose, wide, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 overflow-y-auto py-8">
            <div className={`w-full ${wide ? 'max-w-2xl' : 'max-w-md'} rounded-xl bg-surface-2 border border-line p-6 shadow-xl`}>
                <div className="flex items-center justify-between mb-4">
                    <h3 className="text-base font-semibold text-content">{title}</h3>
                    <button type="button" onClick={onClose} className="text-content-muted hover:text-content"><X className="w-4 h-4" /></button>
                </div>
                {children}
            </div>
        </div>
    );
}

function inputClass(error) {
    return `w-full px-3 py-2 rounded-lg bg-surface-2 border ${error ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-60`;
}

function Field({ label, required, error, hint, children }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label} {required && <span className="text-danger">*</span>}</label>
            {children}
            {hint && ! error && <p className="mt-1 text-xs text-content-muted">{hint}</p>}
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}
