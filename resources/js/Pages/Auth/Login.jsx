import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { CircleDot, Eye, EyeOff, Loader2, Store, Monitor, Plug } from 'lucide-react';

const FEATURES = [
    { icon: Store,   label: 'Multi-store management',                  text: 'Run several brands from a single dashboard.' },
    { icon: Monitor, label: 'Built-in POS system',                     text: 'Ring up sales in-store, print receipts, sync inventory.' },
    { icon: Plug,    label: 'Sync with Shopify, WooCommerce & YouCan', text: 'Two-way sync for products, orders, and stock.' },
];

/**
 * Full-page login — Fortify's own POST /login (AuthenticatedSessionController),
 * same request shape LoginModal.jsx already posts successfully. On success
 * Fortify's LoginResponse (bound in AppServiceProvider) decides where to
 * redirect (dashboard / onboarding / admin / pos), or to /two-factor-challenge
 * if the account has 2FA enabled.
 */
export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const [showPw, setShowPw] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        post('/login', { onError: () => setData('password', '') });
    };

    return (
        <>
            <Head title="Sign in" />

            <div className="min-h-screen flex bg-[#0F1117] text-slate-200 font-sans">
                <aside className="hidden lg:flex flex-col w-2/5 bg-[#0F1117] border-r border-[#2A2D3A] p-10 relative overflow-hidden">
                    <div className="absolute inset-0 bg-gradient-to-br from-indigo-500/10 via-transparent to-transparent pointer-events-none" />

                    <header className="relative flex items-center gap-2">
                        <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center">
                            <CircleDot className="w-4 h-4 text-white" />
                        </div>
                        <span className="font-bold text-white">SaaS Commerce</span>
                    </header>

                    <div className="relative flex-1 flex flex-col justify-center">
                        <h2 className="text-3xl font-bold text-white leading-tight">
                            Welcome back.
                        </h2>
                        <p className="mt-3 text-slate-400 max-w-sm">
                            Sign in to manage your stores, orders and inventory.
                        </p>

                        <ul className="mt-10 space-y-5">
                            {FEATURES.map((f) => (
                                <li key={f.label} className="flex items-start gap-3">
                                    <div className="flex-shrink-0 w-9 h-9 rounded-lg bg-indigo-500/15 text-indigo-400 flex items-center justify-center">
                                        <f.icon className="w-4 h-4" />
                                    </div>
                                    <div>
                                        <div className="text-sm font-semibold text-white">{f.label}</div>
                                        <div className="text-xs text-slate-400 mt-0.5">{f.text}</div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                </aside>

                <main className="flex-1 flex items-center justify-center p-6 lg:p-12">
                    <div className="w-full max-w-md">
                        <div className="lg:hidden flex items-center gap-2 mb-8">
                            <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center">
                                <CircleDot className="w-4 h-4 text-white" />
                            </div>
                            <span className="font-bold text-white">SaaS Commerce</span>
                        </div>

                        <h1 className="text-2xl font-bold text-white tracking-tight">Sign in</h1>
                        <p className="mt-1 text-sm text-slate-400">Welcome back — enter your details below.</p>

                        {status && (
                            <p className="mt-4 text-sm font-medium text-emerald-400">{status}</p>
                        )}

                        <form onSubmit={submit} className="mt-6 space-y-4">
                            <Field
                                label="Email"
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(v) => setData('email', v)}
                                error={errors.email}
                                autoComplete="username"
                                autoFocus
                            />

                            <div>
                                <div className="flex items-center justify-between mb-1">
                                    <label htmlFor="password" className="block text-xs font-medium text-slate-400">Password</label>
                                    {canResetPassword && (
                                        <Link href="/forgot-password" className="text-[11px] text-indigo-400 hover:text-indigo-300">
                                            Forgot password?
                                        </Link>
                                    )}
                                </div>
                                <div className="relative">
                                    <input
                                        id="password"
                                        name="password"
                                        type={showPw ? 'text' : 'password'}
                                        autoComplete="current-password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        className={`w-full px-3 py-2 pr-10 rounded-lg bg-[#1A1D27] border ${
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

                            <label className="flex items-center gap-2 text-xs text-slate-400 cursor-pointer select-none">
                                <input
                                    type="checkbox"
                                    checked={data.remember}
                                    onChange={(e) => setData('remember', e.target.checked)}
                                    className="rounded bg-[#1A1D27] border-[#2A2D3A] text-indigo-600 focus:ring-indigo-500"
                                />
                                Remember me
                            </label>

                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                            >
                                {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Signing in…</> : 'Sign in'}
                            </button>
                        </form>

                        <p className="mt-6 text-center text-xs text-slate-400">
                            Don&apos;t have an account?{' '}
                            <Link href="/register" className="text-indigo-400 hover:text-indigo-300 font-medium">
                                Start for free
                            </Link>
                        </p>
                    </div>
                </main>
            </div>
        </>
    );
}

function Field({ label, id, type = 'text', value, onChange, error, autoComplete, autoFocus }) {
    return (
        <div>
            <label htmlFor={id} className="block text-xs font-medium text-slate-400 mb-1">{label}</label>
            <input
                id={id}
                name={id}
                type={type}
                autoComplete={autoComplete}
                autoFocus={autoFocus}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={`w-full px-3 py-2 rounded-lg bg-[#1A1D27] border ${
                    error ? 'border-red-500/60' : 'border-[#2A2D3A]'
                } text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`}
            />
            {error && <p className="mt-1 text-xs text-red-400">{error}</p>}
        </div>
    );
}
