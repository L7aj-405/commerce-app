import { useRef, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Loader2, Lock } from 'lucide-react';
import AuthLayout from '@/Layouts/AuthLayout';

/** Fortify's GET/POST /user/confirm-password. */
export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });
    const ref = useRef(null);

    useEffect(() => { ref.current?.focus(); }, []);

    const submit = (e) => {
        e.preventDefault();
        post('/user/confirm-password', { onError: () => reset('password') });
    };

    return (
        <AuthLayout title="Confirm your password" subtitle="This is a secure area — please confirm your password before continuing.">
            <div className="mt-5 flex items-start gap-3">
                <div className="w-9 h-9 rounded-lg bg-indigo-500/15 text-indigo-400 flex items-center justify-center flex-shrink-0">
                    <Lock className="w-4 h-4" />
                </div>
            </div>

            <form onSubmit={submit} className="mt-3 space-y-4">
                <div>
                    <label htmlFor="password" className="block text-xs font-medium text-slate-400 mb-1">Password</label>
                    <input
                        ref={ref}
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className={`w-full px-3 py-2 rounded-lg bg-[#0F1117] border ${
                            errors.password ? 'border-red-500/60' : 'border-[#2A2D3A]'
                        } text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`}
                    />
                    {errors.password && <p className="mt-1 text-xs text-red-400">{errors.password}</p>}
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    className="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-500 disabled:opacity-50 transition"
                >
                    {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Confirming…</> : 'Confirm'}
                </button>
            </form>
        </AuthLayout>
    );
}
