import { useRef, useEffect } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Loader2, KeyRound } from 'lucide-react';
import AuthLayout from '@/Layouts/AuthLayout';

/** Fortify's GET/POST /forgot-password (PasswordResetLinkController). */
export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });
    const ref = useRef(null);

    useEffect(() => { ref.current?.focus(); }, []);

    const submit = (e) => {
        e.preventDefault();
        post('/forgot-password');
    };

    return (
        <AuthLayout title="Forgot your password?" subtitle="No problem — we'll email you a reset link.">
            <div className="mt-5 flex items-start gap-3">
                <div className="w-9 h-9 rounded-lg bg-indigo-500/15 text-indigo-400 flex items-center justify-center flex-shrink-0">
                    <KeyRound className="w-4 h-4" />
                </div>
                <p className="text-sm text-slate-400">
                    Enter the email address on your account and we'll send you a link to reset your password.
                </p>
            </div>

            {status && (
                <p className="mt-4 text-sm font-medium text-emerald-400">{status}</p>
            )}

            <form onSubmit={submit} className="mt-5 space-y-4">
                <div>
                    <label htmlFor="email" className="block text-xs font-medium text-slate-400 mb-1">Email</label>
                    <input
                        ref={ref}
                        id="email"
                        type="email"
                        autoComplete="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className={`w-full px-3 py-2 rounded-lg bg-[#0F1117] border ${
                            errors.email ? 'border-red-500/60' : 'border-[#2A2D3A]'
                        } text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`}
                    />
                    {errors.email && <p className="mt-1 text-xs text-red-400">{errors.email}</p>}
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-500 disabled:opacity-50 transition"
                >
                    {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Sending…</> : 'Email password reset link'}
                </button>
            </form>

            <p className="mt-6 text-center text-xs text-slate-400">
                Remembered it after all?{' '}
                <Link href="/login" className="text-indigo-400 hover:text-indigo-300 font-medium">Back to sign in</Link>
            </p>
        </AuthLayout>
    );
}
