import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { CircleDot, ArrowRight, Check, Store, Monitor, Plug, ShieldCheck, BarChart3, Layers } from 'lucide-react';
import LoginModal from '@/Components/LoginModal';
import ThemeToggle from '@/Components/ThemeToggle';

const FEATURES = [
    { icon: Store,     title: 'Multi-store SaaS',     text: 'Run several brands and locations from one clean dashboard.' },
    { icon: Monitor,   title: 'Modern POS',           text: 'Ring up sales, print thermal receipts, hold cashier shifts.' },
    { icon: Plug,      title: 'Platform sync',        text: 'Two-way sync with WooCommerce, Shopify, and YouCan.' },
    { icon: Layers,    title: 'Real-time stock',      text: 'Stock movements logged across every channel, every time.' },
    { icon: BarChart3, title: 'Analytics that work',  text: 'Daily revenue, top SKUs, and cashier performance built in.' },
    { icon: ShieldCheck, title: 'Audit-ready',        text: 'Every sale, refund, and adjustment timestamped and traceable.' },
];

const STEPS = [
    { n: 1, title: 'Create your account', text: 'No credit card. Set up in under a minute.' },
    { n: 2, title: 'Configure your store', text: 'Pick a business type, country, and where you sell.' },
    { n: 3, title: 'Start ringing up sales', text: 'Onboard your team and open your first POS session.' },
];

export default function Welcome() {
    const { auth } = usePage().props;
    const [loginOpen, setLoginOpen] = useState(false);

    return (
        <>
            <Head title="SaaS Commerce — modern POS & multi-store retail" />

            <LoginModal open={loginOpen} onClose={() => setLoginOpen(false)} />

            <div className="min-h-screen bg-surface text-content font-sans">
                <header className="sticky top-0 z-20 bg-surface/80 backdrop-blur border-b border-line">
                    <div className="max-w-6xl mx-auto px-6 h-16 flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center">
                                <CircleDot className="w-4 h-4 text-white" />
                            </div>
                            <span className="font-bold text-content tracking-tight">SaaS Commerce</span>
                        </div>

                        <nav className="flex items-center gap-1.5">
                            <ThemeToggle />
                            {auth?.user ? (
                                <Link
                                    href="/dashboard"
                                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                                >
                                    Go to dashboard
                                    <ArrowRight className="w-4 h-4" />
                                </Link>
                            ) : (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => setLoginOpen(true)}
                                        className="px-3 py-1.5 text-sm font-medium text-content-muted hover:text-content rounded-lg transition"
                                    >
                                        Sign in
                                    </button>
                                    <Link
                                        href="/register"
                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                                    >
                                        Start for free
                                    </Link>
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                <section className="relative overflow-hidden">
                    <div className="absolute inset-0 bg-gradient-to-br from-indigo-500/10 via-transparent to-transparent pointer-events-none" />
                    <div className="relative max-w-5xl mx-auto px-6 py-24 text-center">
                        <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-indigo-500/15 border border-indigo-500/30 text-indigo-700 dark:text-indigo-300 text-[11px] font-medium uppercase tracking-wider">
                            <span className="w-1.5 h-1.5 rounded-full bg-indigo-400 animate-pulse" />
                            New: real-time inventory sync
                        </span>

                        <h1 className="mt-5 text-4xl md:text-6xl font-bold text-content tracking-tight leading-tight">
                            Manage your store,
                            <span className="block text-transparent bg-clip-text bg-gradient-to-r from-indigo-600 to-indigo-400 dark:from-indigo-400 dark:to-indigo-200">everywhere.</span>
                        </h1>

                        <p className="mt-5 max-w-2xl mx-auto text-base text-content-muted leading-relaxed">
                            A modern POS and multi-store dashboard. Sync products with WooCommerce, Shopify, and YouCan.
                            Ring up sales in-store. Track stock across every channel.
                        </p>

                        <div className="mt-8 flex flex-wrap justify-center gap-3">
                            <Link
                                href="/register"
                                className="inline-flex items-center gap-1.5 px-5 py-3 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition shadow-sm"
                            >
                                Start for free
                                <ArrowRight className="w-4 h-4" />
                            </Link>
                            <button
                                type="button"
                                onClick={() => setLoginOpen(true)}
                                className="inline-flex items-center gap-1.5 px-5 py-3 text-sm font-semibold rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content transition"
                            >
                                Sign in
                            </button>
                        </div>

                        <p className="mt-4 text-[11px] text-content-muted">14-day free trial · no credit card · cancel anytime</p>
                    </div>
                </section>

                <section className="max-w-6xl mx-auto px-6 py-16 border-t border-line">
                    <div className="max-w-2xl">
                        <div className="text-[11px] font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">Built for retail</div>
                        <h2 className="mt-2 text-3xl font-bold text-content tracking-tight">Everything you need to sell.</h2>
                        <p className="mt-2 text-content-muted">Six modules, one product, no plug-ins to glue together.</p>
                    </div>

                    <div className="mt-10 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        {FEATURES.map((f) => (
                            <div key={f.title} className="bg-surface-2 border border-line rounded-xl p-6 shadow-sm dark:shadow-none hover:border-content-muted/40 transition">
                                <div className="flex items-center justify-center w-10 h-10 rounded-lg bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 mb-4">
                                    <f.icon className="w-5 h-5" />
                                </div>
                                <h3 className="text-base font-semibold text-content">{f.title}</h3>
                                <p className="mt-1.5 text-sm text-content-muted leading-relaxed">{f.text}</p>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="max-w-6xl mx-auto px-6 py-16 border-t border-line">
                    <div className="max-w-2xl">
                        <div className="text-[11px] font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">How it works</div>
                        <h2 className="mt-2 text-3xl font-bold text-content tracking-tight">Up and running in minutes.</h2>
                    </div>

                    <div className="mt-10 grid grid-cols-1 md:grid-cols-3 gap-6">
                        {STEPS.map((s) => (
                            <div key={s.n} className="relative bg-surface-2 border border-line rounded-xl p-6 shadow-sm dark:shadow-none">
                                <div className="absolute -top-3 left-6 w-7 h-7 rounded-full bg-indigo-600 text-white text-xs font-bold flex items-center justify-center">
                                    {s.n}
                                </div>
                                <h3 className="mt-2 text-base font-semibold text-content">{s.title}</h3>
                                <p className="mt-1.5 text-sm text-content-muted leading-relaxed">{s.text}</p>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="max-w-4xl mx-auto px-6 py-20 text-center border-t border-line">
                    <h2 className="text-3xl md:text-4xl font-bold text-content tracking-tight">Ready to start?</h2>
                    <p className="mt-3 text-content-muted">Set up your first store in under a minute. We'll guide you through it.</p>
                    <Link
                        href="/register"
                        className="mt-7 inline-flex items-center gap-1.5 px-6 py-3 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition shadow-sm"
                    >
                        Create your account
                        <ArrowRight className="w-4 h-4" />
                    </Link>
                    <div className="mt-5 flex flex-wrap justify-center items-center gap-x-6 gap-y-2 text-[11px] text-content-muted">
                        <span className="inline-flex items-center gap-1"><Check className="w-3 h-3 text-emerald-600 dark:text-emerald-400" /> 14-day trial</span>
                        <span className="inline-flex items-center gap-1"><Check className="w-3 h-3 text-emerald-600 dark:text-emerald-400" /> No credit card</span>
                        <span className="inline-flex items-center gap-1"><Check className="w-3 h-3 text-emerald-600 dark:text-emerald-400" /> Cancel anytime</span>
                    </div>
                </section>

                <footer className="border-t border-line py-8 text-center text-xs text-content-muted">
                    © {new Date().getFullYear()} SaaS Commerce. Built with Laravel + Inertia.
                </footer>
            </div>
        </>
    );
}
