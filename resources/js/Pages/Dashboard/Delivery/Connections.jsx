import { useMemo, useState } from 'react';
import { Link, useForm, router } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, Save, Loader2, RefreshCw, ShieldCheck, MapPin, PowerOff, Wand2, X, Clock } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatusBadge from '@/Components/StatusBadge';

// City sync is its own status, deliberately independent of the connection's
// auth status (connected/error/disabled) — a bad /cities response must never
// read as "the connection is down".
function citySync(connection) {
    const status = connection?.last_city_sync_error
        ? 'sync_failed'
        : connection?.last_city_sync_at
            ? 'synced'
            : 'not_synced';
    const label = status === 'synced'
        ? `Synced ${connection?.last_city_sync_count ?? 0} cities`
        : status === 'sync_failed'
            ? 'Sync failed'
            : 'Not synced';

    return { status, label };
}

export default function Connections({ connection, mapped_cities: mappedCities, ozon_cities: ozonCities, suggestions }) {
    const { data, setData, post, processing, errors } = useForm({
        name: connection?.name ?? 'Ozon Express',
        customer_id: connection?.customer_id ?? '',
        api_key: '',
        default_parcel_stock: connection?.settings?.default_parcel_stock ?? '1',
        default_parcel_nature: connection?.settings?.default_parcel_nature ?? '',
        default_note: connection?.settings?.default_note ?? '',
        default_parcel_open: connection?.settings?.default_parcel_open ?? '1',
        default_fragile: connection?.settings?.default_fragile === true || connection?.settings?.default_fragile === '1',
        default_replace: connection?.settings?.default_replace === true || connection?.settings?.default_replace === '1',
    });

    const [busy, setBusy] = useState(null);
    const [feedback, setFeedback] = useState(null);

    const { status: citySyncStatus, label: citySyncLabel } = citySync(connection);

    const submit = (e) => {
        e.preventDefault();
        post('/dashboard/delivery-connections/ozon');
    };

    const run = (key, url, payload = {}) => {
        setBusy(key);
        setFeedback(null);
        return axios.post(url, payload)
            .then((res) => {
                setFeedback({ tone: 'success', message: res.data?.message ?? 'Done.' });
                // ozon_cities/suggestions feed the mapping table below —
                // without them here, a successful sync or map-all leaves it
                // showing the stale list.
                router.reload({ only: ['connection', 'mapped_cities', 'ozon_cities', 'suggestions'] });

                return res.data;
            })
            .catch((err) => {
                setFeedback({ tone: 'error', message: err.response?.data?.message ?? 'Request failed.' });
                // A failed sync/map-all still updates connection.last_city_sync_error server-side.
                router.reload({ only: ['connection'] });

                throw err;
            })
            .finally(() => setBusy(null));
    };

    return (
        <SaasLayout pageHeader={{
            title: 'Delivery providers',
            subtitle: 'Connect external carriers to ship packed orders automatically',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Integrations', href: '/dashboard/integrations?tab=delivery' },
                { label: 'Ozon Express' },
            ],
            actions: (
                <Link href="/dashboard/integrations?tab=delivery" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back to Integrations
                </Link>
            ),
        }}>
            {feedback && (
                <div className={`mb-4 px-4 py-2.5 rounded-[var(--radius-button)] border text-sm ${feedback.tone === 'success'
                    ? 'bg-success-soft border-success/30 text-success'
                    : 'bg-danger-soft border-danger/30 text-danger'}`}>
                    {feedback.message}
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
                <div className="bg-surface-2 border border-line rounded-[var(--radius-card)] p-6 space-y-5">
                    <div className="flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-content">Ozon Express</h2>
                        <div className="flex items-center gap-1.5">
                            {connection && <StatusBadge status={connection.status} type="delivery_connection" />}
                            {connection && <StatusBadge status={citySyncStatus} type="city_sync" label={citySyncLabel} />}
                        </div>
                    </div>

                    {connection?.last_error && (
                        <p className="text-xs text-danger">Connection: {connection.last_error}</p>
                    )}
                    {connection?.last_city_sync_error && (
                        <p className="text-xs text-warning">{connection.last_city_sync_error}</p>
                    )}

                    <form onSubmit={submit} className="space-y-4">
                        <Field label="Connection name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} required />
                        <Field label="Customer ID" value={data.customer_id} onChange={(v) => setData('customer_id', v)} error={errors.customer_id} required />
                        <Field label="API key" type="password" value={data.api_key} onChange={(v) => setData('api_key', v)}
                            error={errors.api_key} placeholder={connection?.has_credentials ? '••••••••  (leave blank to keep)' : ''} required={!connection?.has_credentials} />

                        <div className="grid grid-cols-2 gap-4">
                            <Select label="Default parcel stock" value={data.default_parcel_stock} onChange={(v) => setData('default_parcel_stock', v)}
                                error={errors.default_parcel_stock}
                                options={[
                                    { value: '1', label: 'Stock parcel — 1' },
                                    { value: '0', label: 'Ramassage — 0' },
                                ]}
                                hint="Stock parcels require product details (SKU + quantity) — sent automatically from the order's line items. Ramassage does not." />
                            <Select label="Default parcel open" value={data.default_parcel_open} onChange={(v) => setData('default_parcel_open', v)}
                                error={errors.default_parcel_open}
                                options={[
                                    { value: '1', label: 'Ouvrir le colis — 1' },
                                    { value: '2', label: 'Ne pas ouvrir le colis — 2' },
                                ]} />
                        </div>
                        <Field label="Default parcel nature" value={data.default_parcel_nature} onChange={(v) => setData('default_parcel_nature', v)} />
                        <Field label="Default note" value={data.default_note} onChange={(v) => setData('default_note', v)} />

                        <div className="flex items-center gap-5 text-sm text-content-muted">
                            <Checkbox label="Fragile" checked={data.default_fragile} onChange={(v) => setData('default_fragile', v)} />
                            <Checkbox label="Replaceable" checked={data.default_replace} onChange={(v) => setData('default_replace', v)} />
                        </div>

                        <button type="submit" disabled={processing} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-50">
                            {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving…</> : <><Save className="w-4 h-4" /> {connection ? 'Update credentials' : 'Connect'}</>}
                        </button>
                    </form>

                    {connection && (
                        <div className="flex items-center gap-2 pt-2 border-t border-line">
                            <button type="button" disabled={busy !== null}
                                onClick={() => run('test', `/dashboard/delivery-connections/${connection.id}/test`)}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-[var(--radius-button)] bg-surface-3 border border-line text-content hover:bg-surface-1 disabled:opacity-50">
                                {busy === 'test' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <ShieldCheck className="w-3.5 h-3.5" />} Test connection
                            </button>
                            <button type="button" disabled={busy !== null}
                                onClick={() => run('sync-cities', `/dashboard/delivery-connections/${connection.id}/sync-cities`)}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-[var(--radius-button)] bg-surface-3 border border-line text-content hover:bg-surface-1 disabled:opacity-50">
                                {busy === 'sync-cities' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />} Sync cities
                            </button>
                            {connection.status !== 'disabled' && (
                                <button type="button" disabled={busy !== null}
                                    onClick={() => {
                                        if (!confirm('Disconnect Ozon Express? Orders can\'t be sent to it until you test the connection again.')) return;
                                        run('disconnect', `/dashboard/delivery-connections/${connection.id}/disconnect`);
                                    }}
                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-[var(--radius-button)] bg-surface border border-danger/40 text-danger hover:bg-danger-soft disabled:opacity-50">
                                    {busy === 'disconnect' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <PowerOff className="w-3.5 h-3.5" />} Disconnect
                                </button>
                            )}
                            {connection.last_tested_at && (
                                <span className="text-xs text-content-muted">Last tested {new Date(connection.last_tested_at).toLocaleString()}</span>
                            )}
                        </div>
                    )}
                </div>

                {connection && (
                    <CityMapping
                        connection={connection}
                        mappedCities={mappedCities}
                        ozonCities={ozonCities}
                        suggestions={suggestions}
                        citySyncLabel={citySyncLabel}
                        citySyncStatus={citySyncStatus}
                        run={run}
                    />
                )}
            </div>
        </SaasLayout>
    );
}

const MATCH_BADGE = {
    mapped: { status: 'mapped', label: 'Already mapped' },
    exact: { status: 'exact', label: 'Exact match' },
    suggested: { status: 'suggested', label: 'Suggested' },
    needs_review: { status: 'needs_review', label: 'Needs review' },
    no_match: { status: 'no_match', label: 'No match' },
};

/** Derives the 5-way UI status from a mapped row and/or a raw suggestion object. */
function rowMatch(mapped, suggestion) {
    if (mapped) return MATCH_BADGE.mapped;

    switch (suggestion?.match_type) {
        case 'exact': return MATCH_BADGE.exact;
        case 'alias': return MATCH_BADGE.suggested;
        case 'fuzzy': return suggestion.can_auto_map ? MATCH_BADGE.suggested : MATCH_BADGE.needs_review;
        case 'ambiguous': return MATCH_BADGE.needs_review;
        default: return MATCH_BADGE.no_match;
    }
}

function CityMapping({ connection, mappedCities, ozonCities, suggestions, citySyncLabel, citySyncStatus, run }) {
    const [selection, setSelection] = useState({});
    const [rowBusy, setRowBusy] = useState(null);

    const rows = useMemo(() => {
        const mappedByCityId = new Map((mappedCities ?? []).map((m) => [m.city_id, m]));
        const suggestionByCityId = new Map((suggestions ?? []).map((s) => [s.internal_city_id, s]));
        const cityIds = new Set([...mappedByCityId.keys(), ...suggestionByCityId.keys()]);

        return Array.from(cityIds)
            .map((cityId) => {
                const mapped = mappedByCityId.get(cityId) ?? null;
                const suggestion = suggestionByCityId.get(cityId) ?? null;

                return {
                    cityId,
                    cityName: mapped?.city_name ?? suggestion?.internal_city_name ?? cityId,
                    mapped,
                    suggestion,
                    match: rowMatch(mapped, suggestion),
                };
            })
            .sort((a, b) => a.cityName.localeCompare(b.cityName));
    }, [mappedCities, suggestions]);

    const valueFor = (row) => selection[row.cityId]
        ?? row.mapped?.provider_city_id
        ?? row.suggestion?.suggested_provider_city_id
        ?? '';

    const mapRow = (row) => {
        const providerCityId = valueFor(row);
        if (!providerCityId) return;
        setRowBusy(row.cityId);
        axios.post(`/dashboard/delivery-connections/${connection.id}/cities/map`, {
            city_id: row.cityId,
            delivery_provider_city_id: providerCityId,
        })
            .then(() => router.reload({ only: ['mapped_cities', 'suggestions'] }))
            .finally(() => setRowBusy(null));
    };

    const clearRow = (row) => {
        setRowBusy(row.cityId);
        axios.post(`/dashboard/delivery-connections/${connection.id}/cities/clear-mapping`, { city_id: row.cityId })
            .then(() => router.reload({ only: ['mapped_cities', 'suggestions'] }))
            .finally(() => setRowBusy(null));
    };

    const mapAllSuggested = () => run('map-all-suggested', `/dashboard/delivery-connections/${connection.id}/cities/map-all-suggested`);
    const refreshSuggestions = () => router.reload({ only: ['ozon_cities', 'mapped_cities', 'suggestions'] });

    const noOzonCitiesSynced = (ozonCities ?? []).length === 0;

    return (
        <div className="bg-surface-2 border border-line rounded-[var(--radius-card)] p-6 space-y-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <MapPin className="w-4 h-4 text-content-muted" />
                        <h2 className="text-sm font-semibold text-content">City mapping</h2>
                    </div>
                    <p className="mt-1 text-xs text-content-muted">
                        Sync Ozon cities first, then map your store cities to Ozon city IDs.
                    </p>
                </div>
                <div className="text-right shrink-0">
                    <StatusBadge status={citySyncStatus} type="city_sync" label={citySyncLabel} />
                    {connection?.last_city_sync_at && (
                        <p className="mt-1 flex items-center justify-end gap-1 text-[11px] text-content-muted">
                            <Clock className="w-3 h-3" /> {new Date(connection.last_city_sync_at).toLocaleString()}
                        </p>
                    )}
                </div>
            </div>

            <div className="flex items-center gap-2">
                <button type="button" disabled={rowBusy !== null}
                    onClick={mapAllSuggested}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-50">
                    <Wand2 className="w-3.5 h-3.5" /> Map all suggested
                </button>
                <button type="button" disabled={rowBusy !== null}
                    onClick={refreshSuggestions}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-[var(--radius-button)] bg-surface-3 border border-line text-content hover:bg-surface-1 disabled:opacity-50">
                    <RefreshCw className="w-3.5 h-3.5" /> Refresh suggestions
                </button>
            </div>

            {noOzonCitiesSynced ? (
                <p className="text-sm text-content-muted py-4 text-center">
                    No Ozon cities synced yet. Click <strong>Sync cities</strong> first.
                </p>
            ) : rows.length === 0 ? (
                <p className="text-sm text-content-muted">All active cities are mapped.</p>
            ) : (
                <div className="space-y-2 max-h-96 overflow-y-auto">
                    {rows.map((row) => (
                        <div key={row.cityId} className="flex flex-wrap items-center gap-2 py-1">
                            <span className="text-sm text-content w-28 truncate shrink-0" title={row.cityName}>{row.cityName}</span>

                            <select
                                className="flex-1 min-w-[8rem] px-2 py-1.5 text-sm rounded-[var(--radius-button)] bg-surface-3 border border-line text-content"
                                value={valueFor(row)}
                                onChange={(e) => setSelection((s) => ({ ...s, [row.cityId]: e.target.value }))}
                            >
                                <option value="" disabled>Ozon city…</option>
                                {(ozonCities ?? []).map((oc) => (
                                    <option key={oc.id} value={oc.id}>{oc.city_name}</option>
                                ))}
                            </select>

                            <StatusBadge status={row.match.status} type="city_match" label={row.match.label} />

                            {row.suggestion?.confidence > 0 && !row.mapped && row.match.status !== 'exact' && (
                                <span className="text-[11px] text-content-muted tabular-nums">{Math.round(row.suggestion.confidence)}%</span>
                            )}

                            <button type="button" disabled={rowBusy === row.cityId || !valueFor(row)}
                                onClick={() => mapRow(row)}
                                className="px-3 py-1.5 text-xs font-medium rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-50">
                                {rowBusy === row.cityId ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : 'Map'}
                            </button>

                            {row.mapped && (
                                <button type="button" disabled={rowBusy === row.cityId}
                                    onClick={() => clearRow(row)}
                                    aria-label={`Clear mapping for ${row.cityName}`}
                                    className="p-1.5 rounded-[var(--radius-button)] text-content-muted hover:text-danger hover:bg-danger-soft disabled:opacity-50">
                                    <X className="w-3.5 h-3.5" />
                                </button>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function Field({ label, type = 'text', value, onChange, error, required, placeholder, hint }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label} {required && <span className="text-danger">*</span>}</label>
            <input type={type} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder}
                className={`w-full px-3 py-2 rounded-[var(--radius-button)] bg-surface-3 border ${error ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`} />
            {hint && !error && <p className="mt-1 text-xs text-content-muted">{hint}</p>}
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}

function Select({ label, value, onChange, error, options, hint }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label}</label>
            <select value={value} onChange={(e) => onChange(e.target.value)}
                className={`w-full px-3 py-2 rounded-[var(--radius-button)] bg-surface-3 border ${error ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`}>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            {hint && !error && <p className="mt-1 text-xs text-content-muted">{hint}</p>}
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}

function Checkbox({ label, checked, onChange }) {
    return (
        <label className="inline-flex items-center gap-1.5">
            <input type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} className="rounded border-line" />
            {label}
        </label>
    );
}
