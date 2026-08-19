import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Mail, AlertTriangle } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import SettingsNav from '@/Components/Settings/SettingsNav';
import Card from '@/Components/Card';
import Button from '@/Components/Button';

export default function Profile({ user, mustVerifyEmail, hasVerifiedEmail, status }) {
    const { data, setData, patch, processing, errors } = useForm({
        name: user.name ?? '',
        email: user.email ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        patch('/settings/profile');
    };

    const resendVerification = () => {
        router.post('/email/verification-notification');
    };

    return (
        <SaasLayout pageHeader={{
            title: 'Settings',
            subtitle: 'Manage your account',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Settings' }],
        }}>
            <SettingsNav current="profile" />

            <Card title="Profile" subtitle="Update your name and email address" className="max-w-2xl">
                {status === 'verification-link-sent' && (
                    <p className="mb-4 text-sm font-medium text-emerald-600 dark:text-emerald-400">
                        A new verification link has been sent to your email address.
                    </p>
                )}

                <form onSubmit={submit} className="space-y-5">
                    <Field label="Name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} required />

                    <div>
                        <Field label="Email" type="email" value={data.email} onChange={(v) => setData('email', v)} error={errors.email} required />

                        {mustVerifyEmail && ! hasVerifiedEmail && (
                            <div className="mt-2 flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400">
                                <Mail className="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
                                <span>
                                    Your email address is unverified.{' '}
                                    <button type="button" onClick={resendVerification} className="underline hover:no-underline">
                                        Click here to re-send the verification email.
                                    </button>
                                </span>
                            </div>
                        )}
                    </div>

                    <Button type="submit" loading={processing}>Save</Button>
                </form>
            </Card>

            <DeleteAccountCard />
        </SaasLayout>
    );
}

function DeleteAccountCard() {
    const [confirming, setConfirming] = useState(false);
    const { data, setData, delete: destroy, processing, errors, reset } = useForm({ password: '' });

    const submit = (e) => {
        e.preventDefault();
        destroy('/settings/profile', {
            onError: () => {},
            onFinish: () => reset('password'),
        });
    };

    return (
        <Card title="Delete account" subtitle="Delete your account and all of its resources" className="max-w-2xl mt-6 border-red-500/30">
            {! confirming ? (
                <Button variant="danger" onClick={() => setConfirming(true)}>Delete account</Button>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    <div className="flex items-start gap-2 text-xs text-red-600 dark:text-red-400">
                        <AlertTriangle className="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
                        <span>Once your account is deleted, all of its resources and data will be permanently deleted. Enter your password to confirm.</span>
                    </div>
                    <Field label="Password" type="password" value={data.password} onChange={(v) => setData('password', v)} error={errors.password} required />
                    <div className="flex items-center gap-2">
                        <Button type="submit" variant="danger" loading={processing}>Delete account</Button>
                        <Button type="button" variant="secondary" onClick={() => { setConfirming(false); reset('password'); }}>Cancel</Button>
                    </div>
                </form>
            )}
        </Card>
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
