import { useForm } from '@inertiajs/react';
import { ShieldOff, Clock } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import SettingsNav from '@/Components/Settings/SettingsNav';
import Card from '@/Components/Card';
import Button from '@/Components/Button';

export default function Security({ canManageTwoFactor, twoFactorEnabled }) {
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '', password: '', password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        put('/settings/security/password', {
            onError: () => reset('current_password', 'password', 'password_confirmation'),
            onSuccess: () => reset(),
        });
    };

    return (
        <SaasLayout pageHeader={{
            title: 'Settings',
            subtitle: 'Manage your account',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Settings' }],
        }}>
            <SettingsNav current="security" />

            <Card title="Update password" subtitle="Ensure your account is using a long, random password to stay secure" className="max-w-2xl">
                <form onSubmit={submit} className="space-y-5">
                    <Field label="Current password" type="password" value={data.current_password} onChange={(v) => setData('current_password', v)} error={errors.current_password} required />
                    <Field label="New password" type="password" value={data.password} onChange={(v) => setData('password', v)} error={errors.password} required />
                    <Field label="Confirm password" type="password" value={data.password_confirmation} onChange={(v) => setData('password_confirmation', v)} error={errors.password_confirmation} required />

                    <Button type="submit" loading={processing}>Save</Button>
                </form>
            </Card>

            {canManageTwoFactor && (
                <Card title="Two-factor authentication" subtitle="Manage your two-factor authentication settings" className="max-w-2xl mt-6">
                    <div className="flex items-start gap-3 p-4 rounded-lg bg-surface-3 border border-line">
                        <ShieldOff className="w-5 h-5 text-content-muted flex-shrink-0 mt-0.5" />
                        <div>
                            <p className="text-sm font-medium text-content">Not available yet</p>
                            <p className="mt-0.5 text-xs text-content-muted">
                                {twoFactorEnabled
                                    ? 'Your account has a 2FA secret on file, but setup/management isn\'t wired up in this dashboard yet.'
                                    : 'Two-factor authentication management is coming to this dashboard soon.'}
                            </p>
                        </div>
                    </div>
                </Card>
            )}

            <Card title="Browser sessions" subtitle="Manage and log out your active sessions on other browsers and devices" className="max-w-2xl mt-6">
                <div className="flex items-start gap-3 p-4 rounded-lg bg-surface-3 border border-line">
                    <Clock className="w-5 h-5 text-content-muted flex-shrink-0 mt-0.5" />
                    <div>
                        <p className="text-sm font-medium text-content">Not available yet</p>
                        <p className="mt-0.5 text-xs text-content-muted">Logging out other browser sessions isn't implemented in this app yet.</p>
                    </div>
                </div>
            </Card>
        </SaasLayout>
    );
}

function Field({ label, type = 'text', value, onChange, error, required }) {
    return (
        <div>
            <label className="block text-xs font-medium text-content-muted mb-1">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            <input
                type={type}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-red-500/60' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-indigo-500/40`}
            />
            {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
        </div>
    );
}
