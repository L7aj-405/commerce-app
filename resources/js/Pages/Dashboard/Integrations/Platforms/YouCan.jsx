import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save, CheckCircle2, Loader2 } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

export default function YouCan({ connection }) {
    const { data, setData, post, processing, errors } = useForm({
        api_url:      connection?.api_url ?? 'https://api.youcan.shop',
        access_token: '',
    });

    const connected = connection?.status === 'active';
    const submit = (e) => { e.preventDefault(); post('/dashboard/integrations/youcan'); };

    return (
        <SaasLayout pageHeader={{
            title: 'YouCan',
            subtitle: 'Connect a YouCan Shop',
            breadcrumbs: [
                { label: 'Dashboard',    href: '/dashboard' },
                { label: 'Integrations', href: '/dashboard/integrations' },
                { label: 'YouCan' },
            ],
            actions: (
                <Link href="/dashboard/integrations" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            {connected && (
                <div className="mb-4 px-4 py-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-200 text-sm flex items-center gap-2">
                    <CheckCircle2 className="w-4 h-4" /> Connected
                </div>
            )}

            <form onSubmit={submit} className="bg-surface-2 border border-line rounded-xl p-6 max-w-2xl space-y-5">
                <p className="text-xs text-content-muted">Generate an API token from your YouCan dashboard.</p>

                <Field label="API URL" type="url" value={data.api_url} onChange={(v) => setData('api_url', v)} error={errors.api_url} />
                <Field label="Access Token" type="password" value={data.access_token} onChange={(v) => setData('access_token', v)} error={errors.access_token} placeholder="yct_…" required />

                <button type="submit" disabled={processing} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50">
                    {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving…</> : <><Save className="w-4 h-4" /> {connected ? 'Update credentials' : 'Connect'}</>}
                </button>
            </form>
        </SaasLayout>
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
