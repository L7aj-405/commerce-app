import { useMemo, useState } from 'react';
import { Link, useForm, router } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, Save, Loader2, RefreshCw, ShieldCheck, MapPin, PowerOff, Wand2, X, Clock, Tag, ExternalLink, Info, AlertTriangle } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatusBadge from '@/Components/StatusBadge';

function citySync(connection) {
    const status = connection?.last_city_sync_error
        ? 'sync_failed'
        : connection?.last_city_sync_at
            ? 'synced'
            : 'not_synced';
    const label = status === 'synced'
        ? `Synced ${connection?.last_city_sync_count ?? 0} districts`
        : status === 'sync_failed'
            ? 'Sync failed'
            : 'Not synced';

    return { status, label };
}

export default function SenditConnections({
    connection, mapped_cities: mappedCities, sendit_districts: senditDistricts, pickup_districts: pickupDistricts,
    suggestions, sendit_missing_major_cities: missingMajorCities = [], sendit_distinct_cities_count: distinctCitiesCount = 0,
}) {
    const { data, setData, post, processing, errors } = useForm({
        name: connection?.name ?? 'Sendit',
        public_key: connection?.public_key ?? '',
        secret_key: '',
        default_pickup_district_id: connection?.settings?.default_pickup_district_id ?? '',
        allow_open: connection?.settings?.allow_open === true || connection?.settings?.allow_open === '1',
        allow_try: connection?.settings?.allow_try === true || connection?.settings?.allow_try === '1',
        packaging_id: connection?.settings?.packaging_id ?? '',
        option_exchange: connection?.settings?.option_exchange === true || connection?.settings?.option_exchange === '1',
        default_comment: connection?.settings?.default_comment ?? '',
    });

    const [busy, setBusy] = useState(null);
    const [feedback, setFeedback] = useState(null);
    const [labelCodes, setLabelCodes] = useState('');
    const [labelUrl, setLabelUrl] = useState(null);

    const { status: citySyncStatus, label: citySyncLabel } = citySync(connection);

    const submit = (e) => {
        e.preventDefault();
        post('/dashboard/delivery-connections/sendit');
    };

    const run = (key, url, payload = {}) => {
        setBusy(key);
        setFeedback(null);
        return axios.post(url, payload)
            .then((res) => {
                setFeedback({ tone: 'success', message: res.data?.message ?? 'Done.' });
                router.reload({ only: ['connection', 'mapped_cities', 'sendit_districts', 'pickup_districts', 'suggestions', 'sendit_missing_major_cities', 'sendit_distinct_cities_count'] });

                return res.data;
            })
            .catch((err) => {
                setFeedback({ tone: 'error', message: err.response?.data?.message ?? 'Request failed.' });
                router.reload({ only: ['connection'] });

                throw err;
            })
            .finally(() => setBusy(null));
    };

    const fetchLabels = () => {
        const codes = labelCodes.split(',').map((c) => c.trim()).filter(Boolean);
        if (codes.length === 0) return;

        setBusy('labels');
        setLabelUrl(null);
        axios.post('/dashboard/delivery-connections/sendit/labels', { codes, print_format: 1 })
            .then((res) => {
                setLabelUrl(res.data?.file_url ?? null);
                setFeedback({ tone: res.data?.file_url ? 'success' : 'error', message: res.data?.file_url ? 'Labels ready.' : 'Sendit did not return a label file.' });
            })
            .catch((err) => setFeedback({ tone: 'error', message: err.response?.data?.message ?? 'Could not fetch labels.' }))
            .finally(() => setBusy(null));
    };

    const webhookUrl = connection ? `${window.location.origin}/api/webhooks/sendit/${connection.id}` : null;

    return (
        <SaasLayout pageHeader={{
            title: 'Sendit',
            subtitle: 'Connect Sendit to send packed orders automatically',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Integrations', href: '/dashboard/integrations?tab=delivery' },
                { label: 'Sendit' },
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
                        <h2 className="text-sm font-semibold text-content">Sendit</h2>
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
                        <Field label="Public key" value={data.public_key} onChange={(v) => setData('public_key', v)} error={errors.public_key} required />
                        <Field label="Secret key" type="password" value={data.secret_key} onChange={(v) => setData('secret_key', v)}
                            error={errors.secret_key} placeholder={connection?.has_credentials ? '••••••••  (leave blank to keep)' : ''} required={!connection?.has_credentials} />

                        <Select label="Default pickup district" value={data.default_pickup_district_id} onChange={(v) => setData('default_pickup_district_id', v)}
                            error={errors.default_pickup_district_id}
                            options={[{ value: '', label: pickupDistricts?.length ? 'Choose…' : 'Sync districts first' },
                                ...(pickupDistricts ?? []).map((d) => ({ value: d.provider_city_id, label: d.city_name }))]}
                            hint="Required before sending any order to Sendit. Sync districts below to populate this list." />

                        <div className="grid grid-cols-2 gap-4">
                            <Checkbox label="Allow open" checked={data.allow_open} onChange={(v) => setData('allow_open', v)} />
                            <Checkbox label="Allow try" checked={data.allow_try} onChange={(v) => setData('allow_try', v)} />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Packaging ID" value={data.packaging_id} onChange={(v) => setData('packaging_id', v)} />
                            <Checkbox label="Option exchange" checked={data.option_exchange} onChange={(v) => setData('option_exchange', v)} />
                        </div>
                        <Field label="Default comment" value={data.default_comment} onChange={(v) => setData('default_comment', v)} />

                        <button type="submit" disabled={processing} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-50">
                            {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving…</> : <><Save className="w-4 h-4" /> {connection ? 'Update credentials' : 'Connect'}</>}
                        </button>
                    </form>

                    {connection && (
                        <div className="flex flex-wrap items-center gap-2 pt-2 border-t border-line">
                            <button type="button" disabled={busy !== null}
                                onClick={() => run('test', '/dashboard/delivery-connections/sendit/test')}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-[var(--radius-button)] bg-surface-3 border border-line text-content hover:bg-surface-1 disabled:opacity-50">
                                {busy === 'test' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <ShieldCheck className="w-3.5 h-3.5" />} Test connection
                            </button>
                            <button type="button" disabled={busy !== null}
                                onClick={() => run('sync-districts', '/dashboard/delivery-connections/sendit/sync-districts')}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-[var(--radius-button)] bg-surface-3 border border-line text-content hover:bg-surface-1 disabled:opacity-50">
                                {busy === 'sync-districts' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />} Sync districts
                            </button>
                            {connection.status !== 'disabled' && (
                                <button type="button" disabled={busy !== null}
                                    onClick={() => {
                                        if (!confirm('Disconnect Sendit? Orders can\'t be sent to it until you test the connection again.')) return;
                                        run('disconnect', '/dashboard/delivery-connections/sendit/disconnect');
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

                    {connection && <LabelsCard busy={busy} codes={labelCodes} onCodes={setLabelCodes} onFetch={fetchLabels} fileUrl={labelUrl} />}

                    {connection && <WebhookCard webhookUrl={webhookUrl} />}
                </div>

                {connection && (
                    <DistrictMapping
                        connection={connection}
                        mappedCities={mappedCities}
                        senditDistricts={senditDistricts}
                        suggestions={suggestions}
                        citySyncLabel={citySyncLabel}
                        citySyncStatus={citySyncStatus}
                        run={run}
                        missingMajorCities={missingMajorCities}
                        distinctCitiesCount={distinctCitiesCount}
                    />
                )}
            </div>
        </SaasLayout>
    );
}

function LabelsCard({ busy, codes, onCodes, onFetch, fileUrl }) {
    return (
        <div className="pt-3 border-t border-line space-y-2">
            <div className="flex items-center gap-2">
                <Tag className="w-4 h-4 text-content-muted" />
                <h3 className="text-sm font-semibold text-content">Labels</h3>
            </div>
            <p className="text-xs text-content-muted">Print labels for one or more delivery codes, comma-separated.</p>
            <div className="flex gap-2">
                <input value={codes} onChange={(e) => onCodes(e.target.value)} placeholder="CODE1, CODE2…"
                    className="flex-1 px-3 py-2 text-sm rounded-[var(--radius-button)] bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary" />
                <button type="button" disabled={busy !== null || !codes.trim()} onClick={onFetch}
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-50">
                    {busy === 'labels' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Tag className="w-3.5 h-3.5" />} Get labels
                </button>
            </div>
            {fileUrl && (
                <a href={fileUrl} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                    Open label file <ExternalLink className="w-3 h-3" />
                </a>
            )}
        </div>
    );
}

function WebhookCard({ webhookUrl }) {
    return (
        <div className="pt-3 border-t border-line space-y-2">
            <div className="flex items-center gap-2">
                <Info className="w-4 h-4 text-content-muted" />
                <h3 className="text-sm font-semibold text-content">Webhook setup</h3>
            </div>
            <p className="text-xs text-content-muted">Paste this URL into your Sendit dashboard for the <code className="font-mono">delivery.status.update</code> event:</p>
            <code className="block px-3 py-2 rounded-[var(--radius-button)] bg-surface-3 border border-line text-xs font-mono text-content break-all">{webhookUrl}</code>
            <p className="text-xs text-content-muted">
                Sendit signs each webhook with <code className="font-mono">X-Sendit-Signature</code> — an HMAC-SHA256 of the raw request body, keyed with this connection&apos;s secret key. Requests with a missing or invalid signature are rejected before any data is read.
            </p>
        </div>
    );
}

const MATCH_BADGE = {
    mapped: { status: 'mapped', label: 'Already mapped' },
    exact: { status: 'exact', label: 'Exact match' },
    suggested: { status: 'suggested', label: 'Suggested' },
    needs_review: { status: 'needs_review', label: 'Needs review' },
    no_match: { status: 'no_match', label: 'No match' },
};

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

function DistrictMapping({ connection, mappedCities, senditDistricts, suggestions, citySyncLabel, citySyncStatus, run, missingMajorCities, distinctCitiesCount }) {
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
        axios.post('/dashboard/delivery-connections/sendit/cities/map', {
            city_id: row.cityId,
            delivery_provider_city_id: providerCityId,
        })
            .then(() => router.reload({ only: ['mapped_cities', 'suggestions'] }))
            .finally(() => setRowBusy(null));
    };

    const clearRow = (row) => {
        setRowBusy(row.cityId);
        axios.post('/dashboard/delivery-connections/sendit/cities/clear-mapping', { city_id: row.cityId })
            .then(() => router.reload({ only: ['mapped_cities', 'suggestions'] }))
            .finally(() => setRowBusy(null));
    };

    const mapAllSuggested = () => run('map-all-suggested', '/dashboard/delivery-connections/sendit/cities/map-all-suggested');
    const refreshSuggestions = () => router.reload({ only: ['sendit_districts', 'mapped_cities', 'suggestions', 'sendit_missing_major_cities', 'sendit_distinct_cities_count'] });

    const noDistrictsSynced = (senditDistricts ?? []).length === 0;

    return (
        <div className="bg-surface-2 border border-line rounded-[var(--radius-card)] p-6 space-y-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <MapPin className="w-4 h-4 text-content-muted" />
                        <h2 className="text-sm font-semibold text-content">District mapping</h2>
                    </div>
                    <p className="mt-1 text-xs text-content-muted">
                        Sync Sendit districts first, then map your store cities to Sendit district IDs.
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

            {! noDistrictsSynced && (
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-content-muted">
                    <span>{senditDistricts.length} district{senditDistricts.length === 1 ? '' : 's'} · <strong className="text-content">{distinctCitiesCount}</strong> distinct cit{distinctCitiesCount === 1 ? 'y' : 'ies'}</span>
                    {connection?.last_city_sync_page_count != null && (
                        <span>{connection.last_city_sync_page_count} page{connection.last_city_sync_page_count === 1 ? '' : 's'} fetched</span>
                    )}
                    {connection?.last_city_sync_pickup_district_id && (
                        <span>Pickup district used: <span className="font-mono">{connection.last_city_sync_pickup_district_id}</span></span>
                    )}
                </div>
            )}

            {missingMajorCities?.length > 0 && (
                <div className="flex items-start gap-2 px-3 py-2.5 rounded-[var(--radius-button)] bg-warning-soft border border-warning/30 text-warning text-xs">
                    <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                    <div>
                        <p>Some cities may be missing. Re-sync all districts.</p>
                        <p className="mt-0.5 text-warning/80">Not found in the synced districts: {missingMajorCities.join(', ')}.</p>
                    </div>
                </div>
            )}

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

            {noDistrictsSynced ? (
                <p className="text-sm text-content-muted py-4 text-center">
                    No Sendit districts synced yet. Click <strong>Sync districts</strong> first.
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
                                <option value="" disabled>Sendit district…</option>
                                {(senditDistricts ?? []).map((sd) => (
                                    <option key={sd.id} value={sd.id}>
                                        {sd.district_name ? `${sd.city_name} — ${sd.district_name}` : sd.city_name}
                                    </option>
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
        <label className="inline-flex items-center gap-1.5 text-sm text-content-muted">
            <input type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} className="rounded border-line" />
            {label}
        </label>
    );
}
