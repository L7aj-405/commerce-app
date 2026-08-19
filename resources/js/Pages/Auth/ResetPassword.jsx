import { useState, useRef, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Loader2, Eye, EyeOff, Lock } from 'lucide-react';
import AuthLayout from '@/Layouts/AuthLayout';

/** Fortify's GET /reset-password/{token}, POST /reset-password (NewPasswordController). */
export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token ?? '',
        email: email ?? '',
        password: '',
        password_confirmation: '',
    });

    const [showPw, setShowPw] = useState(false);
    const passwordRef = useRef(null);

    useEffect(() => { passwordRef.current?.focus(); }, []);

    const submit = (e) => {
        e.preventDefault();
        post('/reset-password', {
            onError: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Reset your password" subtitle="Choose a new password for your account.">
            <div className="mt-5 flex items-start gap-3">
                <div className="w-9 h-9 rounded-lg bg-indigo-500/15 text-indigo-400 flex items-center justify-center flex-shrink-0">
                    <Lock className="w-4 h-4" />
                </div>
            </div>

            <form onSubmit={submit} className="mt-3 space-y-4">
                <div>
                    <label htmlFor="email" className="block text-xs font-medium text-slate-400 mb-1">Email</label>
                    <input
                        id="email"
                        type="email"
                        autoComplete="username"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className={`w-full px-3 py-2 rounded-lg bg-[#0F1117] border ${
                            errors.email ? 'border-red-500/60' : 'border-[#2A2D3A]'
                        } text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`}
                    />
                    {errors.email && <p className="mt-1 text-xs text-red-400">{errors.email}</p>}
                </div>

                <div>
                    <label htmlFor="password" className="block text-xs font-medium text-slate-400 mb-1">New password</label>
                    <div className="relative">
                        <input
                            ref={passwordRef}
                            id="password"
                            type={showPw ? 'text' : 'password'}
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className={`w-full px-3 py-2 pr-10 rounded-lg bg-[#0F1117] border ${
                                errors.password ? 'border-red-500/60' : 'border-[#2A2D3A]'
                            } text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`}
                        />
                        <button
                            type="button"
                            onClick={() => setShowPw((v) => ! v)}
                            className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-white"
                            aria-label={showPw ? 'Hide password' : 'Show password'}
                        >
                            {showPw ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                        </button>
                    </div>
                    {errors.password && <p className="mt-1 text-xs text-red-400">{errors.password}</p>}
                </div>

                <div>
                    <label htmlFor="password_confirmation" className="block text-xs font-medium text-slate-400 mb-1">Confirm new password</label>
                    <input
                        id="password_confirmation"
                        type={showPw ? 'text' : 'password'}
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        className={`w-full px-3 py-2 rounded-lg bg-[#0F1117] border ${
                            errors.password_confirmation ? 'border-red-500/60' : 'border-[#2A2D3A]'
                        } text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`}
                    />
                    {errors.password_confirmation && <p className="mt-1 text-xs text-red-400">{errors.password_confirmation}</p>}
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-500 disabled:opacity-50 transition"
                >
                    {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Resetting…</> : 'Reset password'}
                </button>
            </form>
        </AuthLayout>
    );
}
