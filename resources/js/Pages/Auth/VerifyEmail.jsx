import { router, useForm } from '@inertiajs/react';
import { Loader2, MailCheck } from 'lucide-react';
import AuthLayout from '@/Layouts/AuthLayout';

/** Fortify's GET /email/verify — resend hits POST /email/verification-notification. */
export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    const resend = (e) => {
        e.preventDefault();
        post('/email/verification-notification');
    };

    const logout = (e) => {
        e.preventDefault();
        router.post('/logout');
    };

    return (
        <AuthLayout title="Verify your email" subtitle="One more step before you can get started.">
            <div className="mt-5 flex items-start gap-3">
                <div className="w-9 h-9 rounded-lg bg-indigo-500/15 text-indigo-400 flex items-center justify-center flex-shrink-0">
                    <MailCheck className="w-4 h-4" />
                </div>
                <p className="text-sm text-slate-400">
                    Thanks for signing up! Before getting started, could you verify your email address by
                    clicking the link we just emailed you? If you didn't get it, we'll gladly send another.
                </p>
            </div>

            {status === 'verification-link-sent' && (
                <p className="mt-4 text-sm font-medium text-emerald-400">
                    A new verification link has been sent to the email address you provided.
                </p>
            )}

            <div className="mt-6 flex items-center justify-between">
                <button
                    type="button"
                    onClick={resend}
                    disabled={processing}
                    className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-500 disabled:opacity-50 transition"
                >
                    {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Sending…</> : 'Resend verification email'}
                </button>

                <button type="button" onClick={logout} className="text-sm text-slate-400 hover:text-white">
                    Log out
                </button>
            </div>
        </AuthLayout>
    );
}
