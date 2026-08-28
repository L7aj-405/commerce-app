import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save, CheckCircle2, Loader2, ExternalLink, AlertTriangle } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

export default function WhatsApp({ connection }) {
    const { data, setData, post, processing, errors } = useForm({
        access_token:   '',
        shop_domain:    connection?.shop_domain ?? '',  // phone_number_id stored here
        webhook_secret: '',
    });

    const connected = connection?.status === 'active';
    const submit = (e) => { e.preventDefault(); post('/dashboard/integrations/whatsapp'); };

    return (
        <SaasLayout pageHeader={{
            title: 'WhatsApp Business',
            subtitle: 'Send order confirmations via Meta WhatsApp Cloud API',
            breadcrumbs: [
                { label: 'Dashboard',    href: '/dashboard' },
                { label: 'Integrations', href: '/dashboard/integrations' },
                { label: 'WhatsApp' },
            ],
            actions: (
                <Link href="/dashboard/integrations" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            {connected && (
                <div className="mb-4 px-4 py-3 rounded-[var(--radius-button)] bg-success-soft border border-success/30 text-success text-sm flex items-center gap-2">
                    <CheckCircle2 className="w-4 h-4" /> Connected · phone_number_id: <code className="font-mono">{connection.shop_domain}</code>
                </div>
            )}

            <div className="mb-4 px-4 py-3 rounded-[var(--radius-button)] bg-warning-soft border border-warning/30 text-warning text-sm flex items-start gap-2">
                <AlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0" />
                <div>
                    <div className="font-medium">Manual setup</div>
                    <div className="text-xs text-warning/80 mt-0.5">
                        The full Meta OAuth onboarding wizard is available at the legacy <Link href="/stores" className="underline">stores</Link> area (Connect WhatsApp button on a store).
                        Use the form below if you already have your own Meta app credentials.
                    </div>
                </div>
            </div>

            <form onSubmit={submit} className="bg-surface-2 border border-line rounded-[var(--radius-card)] p-6 max-w-2xl space-y-5">
                <p className="text-xs text-content-muted">
                    Get these values from <a href="https://developers.facebook.com/apps" target="_blank" rel="noreferrer" className="text-primary hover:text-primary-strong inline-flex items-center gap-0.5">Meta for Developers <ExternalLink className="w-3 h-3" /></a> → WhatsApp → API Setup.
                </p>

                <Field label="Phone number ID" value={data.shop_domain}    onChange={(v) => setData('shop_domain', v)}    error={errors.shop_domain}    placeholder="123456789012345" required />
                <Field label="Access token" type="password" value={data.access_token}   onChange={(v) => setData('access_token', v)}   error={errors.access_token}   placeholder="EAAB…" required />
                <Field label="Webhook verify token (optional)" value={data.webhook_secret} onChange={(v) => setData('webhook_secret', v)} error={errors.webhook_secret} placeholder="any-random-string" />

                <button type="submit" disabled={processing} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-50">
                    {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving…</> : <><Save className="w-4 h-4" /> {connected ? 'Update credentials' : 'Connect'}</>}
                </button>
            </form>
        </SaasLayout>
    );
}

function Field({ label, type = 'text', value, onChange, error, required, placeholder }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label} {required && <span className="text-danger">*</span>}</label>
            <input type={type} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder}
                className={`w-full px-3 py-2 rounded-[var(--radius-button)] bg-surface-3 border ${error ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`} />
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}
