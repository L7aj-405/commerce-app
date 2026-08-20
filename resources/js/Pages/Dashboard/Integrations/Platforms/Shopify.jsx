import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, Save, CheckCircle2, Loader2, Sparkles, KeyRound, Webhook, Copy, Check, RefreshCw } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import Card from '@/Components/Card';
import StatusBadge from '@/Components/StatusBadge';

const EVENT_LABELS = {
    'orders/create': 'Orders created',
    'orders/updated': 'Orders updated',
    'products/create': 'Products created',
    'products/update': 'Products updated',
};

export default function Shopify({ connection, webhookUrl, webhookEvents = [] }) {
    const isWebhook = connection?.connection_method === 'webhook';
    const [method, setMethod] = useState(isWebhook ? 'webhook' : 'admin_client_credentials');

    return (
        <SaasLayout pageHeader={{
            title: 'Shopify',
            subtitle: 'Connect a Shopify storefront',
            breadcrumbs: [
                { label: 'Dashboard',    href: '/dashboard' },
                { label: 'Integrations', href: '/dashboard/integrations' },
                { label: 'Shopify' },
            ],
            actions: (
                <Link href="/dashboard/integrations" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            <div className="max-w-2xl space-y-6">
                {connection && (
                    <div className={`px-4 py-3 rounded-lg border text-sm flex items-center gap-2 ${
                        connection.status === 'active'
                            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-700 dark:text-emerald-300'
                            : 'bg-amber-500/10 border-amber-500/30 text-amber-700 dark:text-amber-300'
                    }`}>
                        <CheckCircle2 className="w-4 h-4" />
                        {connection.status === 'active'
                            ? <>Connected to <strong className="mx-1">{connection.shop_domain}</strong></>
                            : <>Pending verification for <strong className="mx-1">{connection.shop_domain}</strong></>}
                    </div>
                )}

                {/* 1. Method selection */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <MethodCard
                        icon={Sparkles}
                        title="Shopify App"
                        badge="Coming soon"
                        badgeTone="slate"
                        description="Install our app — Shopify handles authorization automatically. Recommended once available."
                        disabled
                        active={false}
                    />
                    <MethodCard
                        icon={KeyRound}
                        title="Admin API via Client Credentials"
                        badge="Dev Dashboard app credentials"
                        badgeTone="amber"
                        description="Enter your Dev Dashboard app's Client ID and Secret — tokens are generated automatically."
                        active={method === 'admin_client_credentials'}
                        onClick={() => setMethod('admin_client_credentials')}
                    />
                    <MethodCard
                        icon={Webhook}
                        title="Webhooks"
                        badge="Recommended for now"
                        badgeTone="emerald"
                        description="No app required — paste generated URLs into Shopify's webhook settings."
                        active={method === 'webhook'}
                        onClick={() => setMethod('webhook')}
                    />
                </div>

                {method === 'admin_client_credentials' ? (
                    <AdminClientCredentialsForm connection={connection} />
                ) : (
                    <WebhookForm connection={connection} webhookUrl={webhookUrl} webhookEvents={webhookEvents} />
                )}
            </div>
        </SaasLayout>
    );
}

function MethodCard({ icon: Icon, title, badge, badgeTone, description, active, disabled, onClick }) {
    const toneClass = {
        slate: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
        amber: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
        emerald: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    }[badgeTone] ?? 'bg-slate-500/15 text-slate-600 dark:text-slate-300';

    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className={`text-left p-4 rounded-xl border-2 transition ${
                disabled
                    ? 'border-line bg-surface-2 opacity-60 cursor-not-allowed'
                    : active
                        ? 'border-indigo-500 bg-indigo-500/5'
                        : 'border-line bg-surface-2 hover:border-line'
            }`}
        >
            <Icon className={`w-5 h-5 mb-2 ${active ? 'text-indigo-600 dark:text-indigo-400' : 'text-content-muted'}`} />
            <p className="text-sm font-semibold text-content">{title}</p>
            <span className={`inline-block mt-1 px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide ${toneClass}`}>{badge}</span>
            <p className="mt-2 text-xs text-content-muted">{description}</p>
        </button>
    );
}

function AdminClientCredentialsForm({ connection }) {
    const isThisMethod = connection?.connection_method === 'admin_client_credentials';
    const { data, setData, post, processing, errors } = useForm({
        connection_method: 'admin_client_credentials',
        shop_domain: isThisMethod ? (connection?.shop_domain ?? '') : '',
        client_id: isThisMethod ? (connection?.client_id ?? '') : '',
        client_secret: '',
    });

    const submit = (e) => { e.preventDefault(); post('/dashboard/integrations/shopify'); };

    return (
        <div className="space-y-6">
            <Card title="Admin API via Client Credentials" subtitle="Dev Dashboard app credentials — no pasted access token needed">
                <form onSubmit={submit} className="space-y-5">
                    <p className="text-xs text-content-muted">
                        Use the Client ID and Secret from your Shopify Dev Dashboard app. We will generate short-lived Admin API tokens automatically when syncing.
                        Enter Client ID and Client Secret — the platform will generate the access token automatically.
                    </p>

                    <Field label="Shop domain" value={data.shop_domain} onChange={(v) => setData('shop_domain', v)} error={errors.shop_domain} placeholder="your-store.myshopify.com" required />
                    <Field label="Client ID" value={data.client_id} onChange={(v) => setData('client_id', v)} error={errors.client_id} required />

                    <div>
                        <label className="block text-sm font-medium text-content-muted mb-1">
                            Client Secret {connection?.has_client_secret && <span className="text-xs text-content-muted">(secret saved — leave blank to keep it)</span>}
                        </label>
                        <input
                            type="password"
                            value={data.client_secret}
                            onChange={(e) => setData('client_secret', e.target.value)}
                            placeholder={connection?.has_client_secret ? '••••••••' : 'From your Dev Dashboard app'}
                            className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${errors.client_secret ? 'border-red-500' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-indigo-500`}
                        />
                        {errors.client_secret && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.client_secret}</p>}
                        <p className="mt-1 text-xs text-content-muted">
                            This is different from the webhook signing secret used to verify incoming Shopify webhooks.
                        </p>
                    </div>

                    <button type="submit" disabled={processing} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50">
                        {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving…</> : <><Save className="w-4 h-4" /> Save</>}
                    </button>
                </form>
            </Card>

            {isThisMethod && <DiagnosticsPanel connection={connection} />}
        </div>
    );
}

const DIAGNOSTICS_STATUS_META = {
    connected: { label: 'Connected', badgeStatus: 'synced', tone: 'text-emerald-600 dark:text-emerald-400' },
    partially_configured: { label: 'Partially configured', badgeStatus: 'pending', tone: 'text-amber-600 dark:text-amber-400' },
    failed: { label: 'Failed', badgeStatus: 'failed', tone: 'text-red-600 dark:text-red-400' },
    untested: { label: 'Untested', badgeStatus: 'pending', tone: 'text-content-muted' },
};

const CAPABILITY_BADGE_META = {
    passed: { label: 'Passed', cls: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' },
    failed: { label: 'Failed', cls: 'bg-red-500/15 text-red-700 dark:text-red-300' },
    configured: { label: 'Configured', cls: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300' },
    not_configured: { label: 'Not configured', cls: 'bg-slate-500/15 text-content-muted' },
    not_tested: { label: 'Not tested', cls: 'bg-slate-500/15 text-content-muted' },
    skipped: { label: 'Skipped', cls: 'bg-slate-500/15 text-content-muted' },
};

/**
 * Real-API-truth diagnostics — replaces the old generic "Test connection"
 * for this method. That check hard-failed the whole connection whenever the
 * token's self-reported `scope` string didn't literally contain
 * "read_products", even when GET /products.json actually returned 200 (the
 * reported bug: products import worked, but the panel still said Failed).
 * This panel shows exactly one status (never a green badge next to a red
 * error) and a per-capability breakdown driven only by real endpoint results
 * for reads, and by reported scopes (never mutated) for writes.
 */
function DiagnosticsPanel({ connection }) {
    const [running, setRunning] = useState(false);
    const [diagnostics, setDiagnostics] = useState(connection?.diagnostics ?? null);

    const runDiagnostics = () => {
        setRunning(true);
        axios.post('/dashboard/integrations/shopify/diagnostics')
            .then((res) => setDiagnostics(res.data))
            .catch((err) => setDiagnostics(err.response?.data?.capabilities ? err.response.data : null))
            .finally(() => setRunning(false));
    };

    const status = diagnostics?.status ?? 'untested';
    const meta = DIAGNOSTICS_STATUS_META[status] ?? DIAGNOSTICS_STATUS_META.untested;

    return (
        <Card title="Capability diagnostics">
            <p className="text-xs text-content-muted mb-3">
                Diagnostics checks what this Shopify connection can actually do. Read capabilities are tested with safe API requests.
                Write capabilities are not mutated during diagnostics; they are reported based on configured scopes when available.
            </p>

            <div className="flex items-center justify-between flex-wrap gap-2 mb-3">
                <div className="flex items-center gap-2">
                    <StatusBadge type="sync" status={meta.badgeStatus} label={meta.label} />
                    <span className={`text-xs ${meta.tone}`}>
                        {diagnostics?.last_checked_at
                            ? `Checked ${new Date(diagnostics.last_checked_at).toLocaleString()}`
                            : 'Not run yet.'}
                    </span>
                </div>
                <button
                    type="button"
                    onClick={runDiagnostics}
                    disabled={running}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10 disabled:opacity-50"
                >
                    {running ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <CheckCircle2 className="w-3.5 h-3.5" />}
                    Run diagnostics
                </button>
            </div>

            {diagnostics?.capabilities?.length > 0 ? (
                <div className="border border-line rounded-lg overflow-hidden overflow-x-auto">
                    <table className="w-full text-xs">
                        <thead className="bg-surface-3 text-content-muted">
                            <tr>
                                <th className="text-left p-2 font-medium">Capability</th>
                                <th className="text-left p-2 font-medium">Status</th>
                                <th className="text-left p-2 font-medium">Message</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {diagnostics.capabilities.map((c) => {
                                const badge = CAPABILITY_BADGE_META[c.status] ?? CAPABILITY_BADGE_META.not_tested;
                                return (
                                    <tr key={c.key}>
                                        <td className="p-2 text-content whitespace-nowrap">{c.label}</td>
                                        <td className="p-2">
                                            <span className={`inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold ${badge.cls}`}>{badge.label}</span>
                                        </td>
                                        <td className="p-2 text-content-muted">{c.message}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            ) : (
                <p className="text-xs text-content-muted">Run diagnostics to see what this connection can do.</p>
            )}
        </Card>
    );
}

function WebhookForm({ connection, webhookUrl, webhookEvents }) {
    const isWebhook = connection?.connection_method === 'webhook';
    const { data, setData, post, processing, errors } = useForm({
        connection_method: 'webhook',
        shop_domain: isWebhook ? (connection?.shop_domain ?? '') : '',
        webhook_secret: '',
        events: isWebhook ? (connection?.webhook_events ?? []) : ['orders/create', 'orders/updated', 'products/create', 'products/update'],
    });
    const [copied, setCopied] = useState(false);

    const toggleEvent = (key) => {
        setData('events', data.events.includes(key) ? data.events.filter((e) => e !== key) : [...data.events, key]);
    };

    const submit = (e) => { e.preventDefault(); post('/dashboard/integrations/shopify'); };

    const copyUrl = async () => {
        if (!webhookUrl) return;
        try {
            await navigator.clipboard.writeText(webhookUrl);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch { /* clipboard unavailable — user can still select the text manually */ }
    };

    return (
        <div className="space-y-6">
            {/* 2/3. Webhook setup form */}
            <Card title="Webhook setup" subtitle="No developer app required">
                <form onSubmit={submit} className="space-y-5">
                    <Field label="Shop domain" value={data.shop_domain} onChange={(v) => setData('shop_domain', v)} error={errors.shop_domain} placeholder="your-store.myshopify.com" required />

                    <div>
                        <label className="block text-sm font-medium text-content-muted mb-1">
                            Webhook signing secret {connection?.has_webhook_secret && <span className="text-xs text-content-muted">(already set — leave blank to keep it)</span>}
                        </label>
                        <input
                            type="password"
                            value={data.webhook_secret}
                            onChange={(e) => setData('webhook_secret', e.target.value)}
                            placeholder={connection?.has_webhook_secret ? '••••••••' : 'From Shopify webhook settings'}
                            className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${errors.webhook_secret ? 'border-red-500' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-indigo-500`}
                        />
                        {errors.webhook_secret && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.webhook_secret}</p>}
                    </div>

                    {/* 5. Event checklist */}
                    <div>
                        <label className="block text-sm font-medium text-content-muted mb-2">Events</label>
                        <div className="grid grid-cols-2 gap-2">
                            {webhookEvents.map((key) => (
                                <label key={key} className="flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-3 border border-line text-sm text-content cursor-pointer">
                                    <input type="checkbox" checked={data.events.includes(key)} onChange={() => toggleEvent(key)} className="rounded border-line" />
                                    {EVENT_LABELS[key] ?? key}
                                </label>
                            ))}
                        </div>
                        {errors.events && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.events}</p>}
                    </div>

                    <button type="submit" disabled={processing} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50">
                        {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving…</> : <><Save className="w-4 h-4" /> Save webhook setup</>}
                    </button>
                </form>
            </Card>

            {/* 4. Generated webhook URL */}
            <Card title="Webhook URL" subtitle="Paste this into every webhook you add in Shopify">
                {webhookUrl ? (
                    <div className="flex items-center gap-2">
                        <input readOnly value={webhookUrl} className="flex-1 px-3 py-2 rounded-lg bg-surface-3 border border-line text-content text-sm font-mono" />
                        <button type="button" onClick={copyUrl} className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10">
                            {copied ? <Check className="w-4 h-4 text-emerald-500" /> : <Copy className="w-4 h-4" />}
                            {copied ? 'Copied' : 'Copy'}
                        </button>
                    </div>
                ) : (
                    <p className="text-xs text-content-muted">Save the shop domain above first — the URL is generated once the connection exists.</p>
                )}
            </Card>

            {/* Instructions */}
            <Card title="How to set it up">
                <ol className="list-decimal list-inside space-y-1.5 text-sm text-content-muted">
                    <li>Open Shopify admin</li>
                    <li>Go to Settings → Notifications (or your custom app's Webhooks area)</li>
                    <li>Add a webhook for each event above</li>
                    <li>Paste the generated URL</li>
                    <li>Save</li>
                    <li>Send a test webhook from Shopify</li>
                    <li>Return here and check the status below</li>
                </ol>
            </Card>

            {/* 6/7. Test connection / last webhook status */}
            <Card title="Verification status">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <StatusBadge type="sync" status={connection?.webhook_status ?? 'pending'} />
                        <span className="text-xs text-content-muted">
                            {connection?.last_webhook_at
                                ? `Last webhook received ${new Date(connection.last_webhook_at).toLocaleString()}`
                                : 'Not verified yet — no webhook received.'}
                        </span>
                    </div>
                    <button
                        type="button"
                        onClick={() => router.reload({ only: ['connection'] })}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10"
                    >
                        <RefreshCw className="w-3.5 h-3.5" /> Refresh status
                    </button>
                </div>
            </Card>
        </div>
    );
}

function Field({ label, type = 'text', value, onChange, error, required, placeholder }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label} {required && <span className="text-red-600 dark:text-red-400">*</span>}</label>
            <input type={type} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-red-500' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-indigo-500`} />
            {error && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}
