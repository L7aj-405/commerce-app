import { useEffect, useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { CircleDot, Eye, EyeOff, Loader2, X } from 'lucide-react';

/**
 * Login modal backed by Inertia's useForm.
 *
 * Posts to Fortify's POST /login as an Inertia request:
 *  - On failure, Fortify throws ValidationException → Laravel redirects back
 *    to the current page (/) with session errors. Inertia shares those as the
 *    `errors` bag, so `errors.email` / `errors.password` populate here.
 *  - `preserveState` / `preserveScroll` keep the modal open (and scroll position)
 *    on that redirect-back, so validation errors render in-place instead of the
 *    modal disappearing.
 *  - On success, Fortify's LoginResponse 302-redirects to /dashboard (or /admin,
 *    /pos, /onboarding). Inertia follows it as a full visit, which unmounts this
 *    modal and refreshes session state — no manual close needed.
 */
export default function LoginModal({ open, onClose }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const [showPw, setShowPw] = useState(false);
    const emailRef = useRef(null);

    // Reset the form whenever the modal is closed so it opens clean next time.
    useEffect(() => {
        if (! open) {
            reset();
            clearErrors();
            setShowPw(false);
        }
    }, [open]);

    // Escape-to-close + lock body scroll while the modal is open.
    useEffect(() => {
        if (! open) return;

        const onKey = (e) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);

        const prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        // Focus the email field once mounted.
        emailRef.current?.focus();

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = prevOverflow;
        };
    }, [open, onClose]);

    if (! open) return null;

    const submit = (e) => {
        e.preventDefault();
        post('/login', {
            preserveState: true,
            preserveScroll: true,
            // Never keep the plaintext password around after a failed attempt.
            onError: () => setData('password', ''),
        });
    };

    const inputClass = (hasError) =>
        `w-full px-3 py-2 rounded-lg bg-surface border ${
            hasError ? 'border-red-500/60' : 'border-line'
        } text-content placeholder:text-content-muted focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`;

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
            onMouseDown={(e) => {
                // Close only when the backdrop itself is clicked, not the panel.
                if (e.target === e.currentTarget) onClose();
            }}
            role="dialog"
            aria-modal="true"
            aria-labelledby="login-modal-title"
        >
            <div className="w-full max-w-md rounded-2xl bg-surface-2 border border-line shadow-2xl overflow-hidden">
                {/* Header */}
                <div className="flex items-center justify-between px-6 pt-6">
                    <div className="flex items-center gap-2">
                        <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center">
                            <CircleDot className="w-4 h-4 text-white" />
                        </div>
                        <span className="font-bold text-content">SaaS Commerce</span>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-1.5 -mr-1.5 text-content-muted hover:text-content rounded-lg hover:bg-content/5 transition"
                        aria-label="Close"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                <div className="px-6 pb-6 pt-4">
                    <h2 id="login-modal-title" className="text-xl font-bold text-content tracking-tight">
                        Welcome back
                    </h2>
                    <p className="mt-1 text-sm text-content-muted">Sign in to your account to continue.</p>

                    <form onSubmit={submit} className="mt-6 space-y-4">
                        {/* Email */}
                        <div>
                            <label htmlFor="login-email" className="block text-xs font-medium text-content-muted mb-1">
                                Email
                            </label>
                            <input
                                ref={emailRef}
                                id="login-email"
                                name="email"
                                type="email"
                                autoComplete="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                className={inputClass(errors.email)}
                            />
                            {errors.email && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.email}</p>}
                        </div>

                        {/* Password */}
                        <div>
                            <div className="flex items-center justify-between mb-1">
                                <label htmlFor="login-password" className="block text-xs font-medium text-content-muted">
                                    Password
                                </label>
                                <Link
                                    href="/forgot-password"
                                    className="text-[11px] text-indigo-600 dark:text-indigo-400 hover:text-indigo-500"
                                    onClick={onClose}
                                >
                                    Forgot password?
                                </Link>
                            </div>
                            <div className="relative">
                                <input
                                    id="login-password"
                                    name="password"
                                    type={showPw ? 'text' : 'password'}
                                    autoComplete="current-password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    className={`${inputClass(errors.password)} pr-10`}
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPw((v) => !v)}
                                    className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-content-muted hover:text-content"
                                    aria-label={showPw ? 'Hide password' : 'Show password'}
                                >
                                    {showPw ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                </button>
                            </div>
                            {errors.password && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.password}</p>}
                        </div>

                        {/* Remember me */}
                        <label className="flex items-center gap-2 text-xs text-content-muted cursor-pointer select-none">
                            <input
                                type="checkbox"
                                checked={data.remember}
                                onChange={(e) => setData('remember', e.target.checked)}
                                className="rounded bg-surface border-line text-indigo-600 focus:ring-indigo-500"
                            />
                            Remember me on this device
                        </label>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                        >
                            {processing ? (
                                <>
                                    <Loader2 className="w-4 h-4 animate-spin" /> Signing in…
                                </>
                            ) : (
                                'Sign in'
                            )}
                        </button>
                    </form>

                    <p className="mt-6 text-center text-xs text-content-muted">
                        Don&apos;t have an account?{' '}
                        <Link href="/register" className="text-indigo-600 dark:text-indigo-400 hover:text-indigo-500 font-medium" onClick={onClose}>
                            Start for free
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    );
}
