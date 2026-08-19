import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';

export default function CashierLogin({ stores = [], setupStores = [], defaultStoreId = null, errors = {} }) {
    const [mode, setMode] = useState('signin'); // 'signin' | 'setup'

    const login = useForm({
        store_id: defaultStoreId ?? (stores[0]?.id ?? ''),
        pin_code: '',
    });

    const setup = useForm({
        store_id:              setupStores[0]?.id ?? '',
        email:                 '',
        password:              '',
        pin_code:              '',
        pin_code_confirmation: '',
    });

    const [showPin, setShowPin] = useState(false);
    const digitsOnly = (v) => v.replace(/\D/g, '').slice(0, 4);

    const submitLogin = (e) => {
        e.preventDefault();
        login.post('/pos/login', { preserveScroll: true, onError: () => login.reset('pin_code') });
    };

    const submitSetup = (e) => {
        e.preventDefault();
        setup.post('/pos/setup-pin', { preserveScroll: true, onError: () => setup.reset('pin_code', 'pin_code_confirmation') });
    };

    const loginErrors = { ...errors, ...login.errors };
    const setupErrors = { ...errors, ...setup.errors };

    const inputClass = 'mt-1 w-full px-3 py-2 rounded-lg border border-gray-700 bg-gray-900 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500';

    return (
        <>
            <Head title={mode === 'setup' ? 'POS — Set your PIN' : 'POS — Sign in'} />

            <div className="min-h-screen flex items-center justify-center bg-gray-900 px-4">
                <div className="w-full max-w-sm bg-gray-800 border border-gray-700 rounded-2xl shadow-xl p-8">
                    <div className="text-center mb-6">
                        <div className="inline-flex items-center justify-center w-12 h-12 rounded-full bg-indigo-600/20 text-indigo-400 mb-3">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="1.7">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 11c1.657 0 3-1.343 3-3S13.657 5 12 5 9 6.343 9 8s1.343 3 3 3zm0 0v4m-7 4h14a2 2 0 002-2v-1a4 4 0 00-4-4H9a4 4 0 00-4 4v1a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h1 className="text-xl font-bold text-white">{mode === 'setup' ? 'Set up your PIN' : 'Cashier sign in'}</h1>
                        <p className="text-sm text-gray-400 mt-1">
                            {mode === 'setup'
                                ? 'First time here? Confirm your account and choose a 4-digit PIN.'
                                : 'Enter your PIN to start a POS session.'}
                        </p>
                    </div>

                    {mode === 'signin' ? (
                        stores.length === 0 ? (
                            <div className="rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-300 text-sm p-3">
                                No cashier has set a PIN for any store yet. If you’re a cashier, use <span className="font-semibold">Set your PIN</span> below to get started.
                            </div>
                        ) : (
                            <form onSubmit={submitLogin} className="space-y-4">
                                {stores.length > 1 && (
                                    <label className="block">
                                        <span className="text-xs text-gray-400">Store</span>
                                        <select value={login.data.store_id} onChange={(e) => login.setData('store_id', e.target.value)} className={inputClass}>
                                            {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                        </select>
                                        {loginErrors.store_id && <p className="mt-1 text-xs text-red-400">{loginErrors.store_id}</p>}
                                    </label>
                                )}

                                <label className="block">
                                    <span className="text-xs text-gray-400">PIN</span>
                                    <div className="relative mt-1">
                                        <input
                                            type={showPin ? 'text' : 'password'}
                                            inputMode="numeric" maxLength={4} autoComplete="one-time-code" autoFocus
                                            value={login.data.pin_code}
                                            onChange={(e) => login.setData('pin_code', digitsOnly(e.target.value))}
                                            placeholder="••••"
                                            className="w-full px-3 py-2 pr-10 text-center text-lg tracking-[0.5em] rounded-lg border border-gray-700 bg-gray-900 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                        />
                                        <button type="button" onClick={() => setShowPin((v) => !v)} aria-label={showPin ? 'Hide PIN' : 'Show PIN'}
                                            className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-white">
                                            {showPin ? '🙈' : '👁'}
                                        </button>
                                    </div>
                                    {loginErrors.pin_code && <p className="mt-2 text-xs text-red-400">{loginErrors.pin_code}</p>}
                                </label>

                                <button type="submit" disabled={login.processing || login.data.pin_code.length !== 4}
                                    className="w-full py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition">
                                    {login.processing ? 'Verifying…' : 'Sign in'}
                                </button>
                            </form>
                        )
                    ) : (
                        <form onSubmit={submitSetup} className="space-y-4">
                            <label className="block">
                                <span className="text-xs text-gray-400">Store</span>
                                <select value={setup.data.store_id} onChange={(e) => setup.setData('store_id', e.target.value)} className={inputClass}>
                                    {setupStores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </select>
                                {setupErrors.store_id && <p className="mt-1 text-xs text-red-400">{setupErrors.store_id}</p>}
                            </label>

                            <label className="block">
                                <span className="text-xs text-gray-400">Email</span>
                                <input type="email" value={setup.data.email} onChange={(e) => setup.setData('email', e.target.value)}
                                    placeholder="you@example.com" className={inputClass} />
                                {setupErrors.email && <p className="mt-1 text-xs text-red-400">{setupErrors.email}</p>}
                            </label>

                            <label className="block">
                                <span className="text-xs text-gray-400">Account password</span>
                                <input type="password" value={setup.data.password} onChange={(e) => setup.setData('password', e.target.value)}
                                    placeholder="Your login password" className={inputClass} />
                                {setupErrors.password && <p className="mt-1 text-xs text-red-400">{setupErrors.password}</p>}
                            </label>

                            <div className="grid grid-cols-2 gap-3">
                                <label className="block">
                                    <span className="text-xs text-gray-400">Choose PIN</span>
                                    <input type="password" inputMode="numeric" maxLength={4} value={setup.data.pin_code}
                                        onChange={(e) => setup.setData('pin_code', digitsOnly(e.target.value))}
                                        placeholder="••••" className={`${inputClass} text-center tracking-[0.3em]`} />
                                </label>
                                <label className="block">
                                    <span className="text-xs text-gray-400">Confirm PIN</span>
                                    <input type="password" inputMode="numeric" maxLength={4} value={setup.data.pin_code_confirmation}
                                        onChange={(e) => setup.setData('pin_code_confirmation', digitsOnly(e.target.value))}
                                        placeholder="••••" className={`${inputClass} text-center tracking-[0.3em]`} />
                                </label>
                            </div>
                            {setupErrors.pin_code && <p className="text-xs text-red-400">{setupErrors.pin_code}</p>}

                            <button type="submit"
                                disabled={setup.processing || setup.data.pin_code.length !== 4 || setup.data.pin_code !== setup.data.pin_code_confirmation || ! setup.data.email}
                                className="w-full py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition">
                                {setup.processing ? 'Setting up…' : 'Set PIN & start'}
                            </button>
                        </form>
                    )}

                    <div className="mt-6 pt-4 border-t border-gray-700 text-center">
                        {mode === 'signin' ? (
                            <button type="button" onClick={() => setMode('setup')} className="text-sm text-indigo-400 hover:text-indigo-300">
                                First time here? Set your PIN →
                            </button>
                        ) : (
                            <button type="button" onClick={() => setMode('signin')} className="text-sm text-indigo-400 hover:text-indigo-300">
                                ← Back to sign in
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
