import { useState, useRef, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Loader2, ShieldCheck } from 'lucide-react';
import AuthLayout from '@/Layouts/AuthLayout';

/** Fortify's GET/POST /two-factor-challenge. */
export default function TwoFactorChallenge() {
    const [useRecovery, setUseRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        recovery_code: '',
    });

    const codeRef = useRef(null);
    useEffect(() => { codeRef.current?.focus(); }, [useRecovery]);

    const toggle = () => {
        reset();
        setUseRecovery((v) => ! v);
    };

    const submit = (e) => {
        e.preventDefault();
        post('/two-factor-challenge');
    };

    return (
        <AuthLayout title="Two-factor authentication" subtitle="Confirm access to your account to continue.">
            <div className="mt-5 flex items-start gap-3">
                <div className="w-9 h-9 rounded-lg bg-indigo-500/15 text-indigo-400 flex items-center justify-center flex-shrink-0">
                    <ShieldCheck className="w-4 h-4" />
                </div>
                <p className="text-sm text-slate-400">
                    {useRecovery
                        ? 'Enter one of your recovery codes.'
                        : 'Enter the 6-digit code from your authenticator app.'}
                </p>
            </div>

            <form onSubmit={submit} className="mt-5 space-y-4">
                {useRecovery ? (
                    <div>
                        <label htmlFor="recovery_code" className="block text-xs font-medium text-slate-400 mb-1">Recovery code</label>
                        <input
                            ref={codeRef}
                            id="recovery_code"
                            type="text"
                            autoComplete="one-time-code"
                            value={data.recovery_code}
                            onChange={(e) => setData('recovery_code', e.target.value)}
                            className={`w-full px-3 py-2 rounded-lg bg-[#0F1117] border ${
                                errors.recovery_code ? 'border-red-500/60' : 'border-[#2A2D3A]'
                            } text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`}
                        />
                        {errors.recovery_code && <p className="mt-1 text-xs text-red-400">{errors.recovery_code}</p>}
                    </div>
                ) : (
                    <div>
                        <label htmlFor="code" className="block text-xs font-medium text-slate-400 mb-1">Authentication code</label>
                        <input
                            ref={codeRef}
                            id="code"
                            type="text"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                            className={`w-full px-3 py-2 rounded-lg bg-[#0F1117] border tracking-[0.3em] text-center ${
                                errors.code ? 'border-red-500/60' : 'border-[#2A2D3A]'
                            } text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`}
                        />
                        {errors.code && <p className="mt-1 text-xs text-red-400">{errors.code}</p>}
                    </div>
                )}

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-500 disabled:opacity-50 transition"
                >
                    {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Verifying…</> : 'Verify'}
                </button>
            </form>

            <button type="button" onClick={toggle} className="mt-4 text-sm text-slate-400 hover:text-white">
                {useRecovery ? 'Use an authentication code instead' : 'Use a recovery code instead'}
            </button>
        </AuthLayout>
    );
}
