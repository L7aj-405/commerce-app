import { useMemo, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { CheckCircle2, Eye, EyeOff, Loader2, Store, Monitor, Plug, CircleDot } from 'lucide-react';

const FEATURES = [
    { icon: Store,   label: 'Multi-store management',                       text: 'Run several brands from a single dashboard.' },
    { icon: Monitor, label: 'Built-in POS system',                          text: 'Ring up sales in-store, print receipts, sync inventory.' },
    { icon: Plug,    label: 'Sync with Shopify, WooCommerce & YouCan',      text: 'Two-way sync for products, orders, and stock.' },
];

function strengthOf(pw) {
    if (! pw) return 0;
    let score = 0;
    if (pw.length >= 8)               score += 1;
    if (/[A-Z]/.test(pw))             score += 1;
    if (/[a-z]/.test(pw))             score += 1;
    if (/[0-9]/.test(pw))             score += 1;
    if (/[^A-Za-z0-9]/.test(pw))      score += 1;
    if (pw.length >= 12)              score += 1;
    return Math.min(score, 5);
}

const STRENGTH_LABELS = ['', 'Weak', 'Weak', 'Fair', 'Good', 'Strong'];
const STRENGTH_COLORS = ['bg-slate-700', 'bg-red-500', 'bg-red-500', 'bg-amber-500', 'bg-emerald-500', 'bg-emerald-500'];

export default function Register() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        terms: false,
    });

    const [showPw, setShowPw]                 = useState(false);
    const [showPwConfirm, setShowPwConfirm]   = useState(false);

    const strength = useMemo(() => strengthOf(data.password), [data.password]);

    const submit = (e) => {
        e.preventDefault();
        post('/register');
    };

    return (
        <>
            <Head title="Create your account" />

            <div className="min-h-screen flex bg-[#0F1117] text-slate-200 font-sans">
                {/* LEFT — brand panel */}
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
                            Manage your store,
                            <br />
                            <span className="text-indigo-400">everywhere.</span>
                        </h2>
                        <p className="mt-3 text-slate-400 max-w-sm">
                            One dashboard for in-store sales, online orders, and everything in between.
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

                    <figure className="relative mt-10 rounded-xl bg-[#1A1D27] border border-[#2A2D3A] p-5">
                        <blockquote className="text-sm text-slate-300 leading-relaxed">
                            "We replaced three apps with SaaS Commerce. Our cashiers learned it in an afternoon."
                        </blockquote>
                        <figcaption className="mt-3 flex items-center gap-2">
                            <div className="w-7 h-7 rounded-full bg-gradient-to-br from-amber-400 to-amber-700 text-white text-[10px] font-bold flex items-center justify-center">
                                NA
                            </div>
                            <div className="text-xs">
                                <div className="font-semibold text-white">Nadia A.</div>
                                <div className="text-slate-500">Founder, Bloom Atelier</div>
                            </div>
                        </figcaption>
                    </figure>
                </aside>

                {/* RIGHT — form */}
                <main className="flex-1 flex items-center justify-center p-6 lg:p-12">
                    <div className="w-full max-w-md">
                        <div className="lg:hidden flex items-center gap-2 mb-8">
                            <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center">
                                <CircleDot className="w-4 h-4 text-white" />
                            </div>
                            <span className="font-bold text-white">SaaS Commerce</span>
                        </div>

                        <h1 className="text-2xl font-bold text-white tracking-tight">Create your account</h1>
                        <p className="mt-1 text-sm text-slate-400">
                            Start your 14-day free trial. No credit card required.
                        </p>

                        <form onSubmit={submit} className="mt-6 space-y-4">
                            <Field
                                label="Full name"
                                id="name"
                                value={data.name}
                                onChange={(v) => setData('name', v)}
                                error={errors.name}
                                autoComplete="name"
                                autoFocus
                            />
                            <Field
                                label="Email"
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(v) => setData('email', v)}
                                error={errors.email}
                                autoComplete="email"
                            />

                            <Field
                                label="Password"
                                id="password"
                                type={showPw ? 'text' : 'password'}
                                value={data.password}
                                onChange={(v) => setData('password', v)}
                                error={errors.password}
                                autoComplete="new-password"
                                trailing={
                                    <button
                                        type="button"
                                        onClick={() => setShowPw((v) => !v)}
                                        className="p-1 text-slate-400 hover:text-white"
                                        aria-label={showPw ? 'Hide password' : 'Show password'}
                                    >
                                        {showPw ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                }
                            />

                            {data.password && (
                                <div>
                                    <div className="flex items-center gap-1 mb-1">
                                        {[1, 2, 3, 4, 5].map((i) => (
                                            <div
                                                key={i}
                                                className={`h-1 flex-1 rounded-full transition ${
                                                    i <= strength ? STRENGTH_COLORS[strength] : 'bg-slate-700/60'
                                                }`}
                                            />
                                        ))}
                                    </div>
                                    <p className="text-[11px] text-slate-500">
                                        Password strength: <span className="text-slate-400">{STRENGTH_LABELS[strength] || '—'}</span>
                                    </p>
                                </div>
                            )}

                            <Field
                                label="Confirm password"
                                id="password_confirmation"
                                type={showPwConfirm ? 'text' : 'password'}
                                value={data.password_confirmation}
                                onChange={(v) => setData('password_confirmation', v)}
                                error={errors.password_confirmation}
                                autoComplete="new-password"
                                trailing={
                                    <button
                                        type="button"
                                        onClick={() => setShowPwConfirm((v) => !v)}
                                        className="p-1 text-slate-400 hover:text-white"
                                        aria-label={showPwConfirm ? 'Hide password' : 'Show password'}
                                    >
                                        {showPwConfirm ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
                                }
                            />

                            <label className="flex items-start gap-2 text-xs text-slate-400 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={data.terms}
                                    onChange={(e) => setData('terms', e.target.checked)}
                                    className="mt-0.5 rounded bg-[#1A1D27] border-[#2A2D3A] text-indigo-600 focus:ring-indigo-500"
                                />
                                <span>
                                    I agree to the{' '}
                                    <a href="/terms" className="text-indigo-400 hover:text-indigo-300">Terms of Service</a>
                                    {' '}and{' '}
                                    <a href="/privacy" className="text-indigo-400 hover:text-indigo-300">Privacy Policy</a>.
                                </span>
                            </label>
                            {errors.terms && <p className="text-xs text-red-400">{errors.terms}</p>}

                            <button
                                type="submit"
                                disabled={processing || ! data.terms}
                                className="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                            >
                                {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Creating…</> : 'Create account'}
                            </button>
                        </form>

                        <div className="relative my-6">
                            <div className="absolute inset-0 flex items-center"><div className="w-full border-t border-[#2A2D3A]" /></div>
                            <div className="relative flex justify-center"><span className="px-3 bg-[#0F1117] text-[11px] uppercase tracking-wider text-slate-500">or continue with</span></div>
                        </div>

                        <button
                            type="button"
                            disabled
                            title="Coming soon"
                            className="w-full flex items-center justify-center gap-2 py-2.5 rounded-lg bg-[#1A1D27] border border-[#2A2D3A] text-slate-300 hover:bg-[#22252F] disabled:opacity-60 transition"
                        >
                            <svg className="w-4 h-4" viewBox="0 0 24 24"><path fill="#EA4335" d="M12 11v3.4h4.8c-.2 1.2-1.4 3.6-4.8 3.6-2.9 0-5.3-2.4-5.3-5.3S9.1 7.4 12 7.4c1.7 0 2.8.7 3.4 1.3l2.3-2.2C16.3 5.2 14.4 4.3 12 4.3 7.7 4.3 4.3 7.7 4.3 12s3.4 7.7 7.7 7.7c4.4 0 7.4-3.1 7.4-7.5 0-.5-.1-.9-.1-1.2H12z"/></svg>
                            Continue with Google
                        </button>

                        <p className="mt-6 text-center text-xs text-slate-400">
                            Already have an account?{' '}
                            <Link href="/login" className="text-indigo-400 hover:text-indigo-300 font-medium">
                                Sign in
                            </Link>
                        </p>
                    </div>
                </main>
            </div>
        </>
    );
}

function Field({ label, id, type = 'text', value, onChange, error, autoComplete, autoFocus, trailing }) {
    return (
        <div>
            <label htmlFor={id} className="block text-xs font-medium text-slate-400 mb-1">{label}</label>
            <div className="relative">
                <input
                    id={id}
                    name={id}
                    type={type}
                    autoComplete={autoComplete}
                    autoFocus={autoFocus}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className={`w-full px-3 py-2 ${trailing ? 'pr-10' : ''} rounded-lg bg-[#1A1D27] border ${
                        error ? 'border-red-500/60' : 'border-[#2A2D3A]'
                    } text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`}
                />
                {trailing && <div className="absolute right-2 top-1/2 -translate-y-1/2">{trailing}</div>}
            </div>
            {error && <p className="mt-1 text-xs text-red-400">{error}</p>}
        </div>
    );
}
