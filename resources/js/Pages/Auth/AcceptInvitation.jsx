import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { CircleDot, Eye, EyeOff, Loader2, Mail, ShieldCheck } from 'lucide-react';

const describeRole = (invitation) => {
    const permissions = invitation.permissions ?? [];
    const has = (permission) => permissions.includes('*') || permissions.includes(permission);

    if (has('pos.access') && permissions.filter((p) => p !== 'pos.access').length === 0) {
        return 'You will get access to the POS terminal for this store.';
    }

    if (has('orders.deliver') && ! has('orders.view')) {
        return 'You will work from the delivery queue assigned to you.';
    }

    return 'Your access is defined by the permissions assigned to this store role.';
};

export default function AcceptInvitation({ invitation, store, existingUser }) {
    const { data, setData, post, processing, errors } = useForm({
        name:                  '',
        password:              '',
        password_confirmation: '',
    });

    const [showPw, setShowPw] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        post(`/invitation/${invitation.token}`);
    };

    const Icon = ShieldCheck;
    const roleDescription = describeRole(invitation);

    return (
        <>
            <Head title={`Join ${store?.name ?? 'a team'}`} />

            <div className="min-h-screen flex items-center justify-center bg-[#0F1117] text-slate-200 font-sans p-4">
                <div className="w-full max-w-md bg-[#1A1D27] border border-[#2A2D3A] rounded-2xl shadow-xl p-8">
                    <div className="flex items-center gap-2 mb-6">
                        <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center">
                            <CircleDot className="w-4 h-4 text-white" />
                        </div>
                        <span className="font-bold text-white text-sm">SaaS Commerce</span>
                    </div>

                    <h1 className="text-xl font-bold text-white tracking-tight">
                        You've been invited to join <span className="text-indigo-400">{store?.name}</span>
                    </h1>

                    <div className="mt-4 p-3 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex items-start gap-3">
                        <div className="w-9 h-9 rounded-lg bg-indigo-500/20 text-indigo-300 flex items-center justify-center flex-shrink-0">
                            <Icon className="w-4 h-4" />
                        </div>
                        <div className="min-w-0 text-xs">
                            <div className="font-semibold text-indigo-200">Role: {invitation.role_name ?? 'Team member'}</div>
                            <p className="text-slate-400 mt-0.5">{roleDescription}</p>
                        </div>
                    </div>

                    <div className="mt-4 flex items-center gap-2 text-sm text-slate-400">
                        <Mail className="w-4 h-4 text-slate-500" />
                        <span className="truncate">{invitation.email}</span>
                    </div>

                    <form onSubmit={submit} className="mt-6 space-y-4">
                        {existingUser ? (
                            <div className="p-3 rounded-lg bg-[#0F1117] border border-[#2A2D3A] text-xs text-slate-400">
                                We found an existing account for this email. Click confirm and you'll be signed in and added to the team.
                            </div>
                        ) : (
                            <>
                                <div>
                                    <label className="block text-xs font-medium text-slate-400 mb-1">Full name</label>
                                    <input
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        autoFocus
                                        className={`w-full px-3 py-2 rounded-lg bg-[#0F1117] border ${errors.name ? 'border-red-500/60' : 'border-[#2A2D3A]'} text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`}
                                    />
                                    {errors.name && <p className="mt-1 text-xs text-red-400">{errors.name}</p>}
                                </div>

                                <div>
                                    <label className="block text-xs font-medium text-slate-400 mb-1">Password</label>
                                    <div className="relative">
                                        <input
                                            type={showPw ? 'text' : 'password'}
                                            value={data.password}
                                            onChange={(e) => setData('password', e.target.value)}
                                            autoComplete="new-password"
                                            className={`w-full px-3 py-2 pr-10 rounded-lg bg-[#0F1117] border ${errors.password ? 'border-red-500/60' : 'border-[#2A2D3A]'} text-slate-200 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`}
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowPw((v) => !v)}
                                            className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-white"
                                            aria-label={showPw ? 'Hide password' : 'Show password'}
                                        >
                                            {showPw ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                        </button>
                                    </div>
                                    {errors.password && <p className="mt-1 text-xs text-red-400">{errors.password}</p>}
                                </div>

                                <div>
                                    <label className="block text-xs font-medium text-slate-400 mb-1">Confirm password</label>
                                    <input
                                        type={showPw ? 'text' : 'password'}
                                        value={data.password_confirmation}
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                        autoComplete="new-password"
                                        className="w-full px-3 py-2 rounded-lg bg-[#0F1117] border border-[#2A2D3A] text-slate-200 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                    />
                                </div>
                            </>
                        )}

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full inline-flex items-center justify-center gap-2 py-2.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-500 disabled:opacity-50 transition"
                        >
                            {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Joining…</> : (existingUser ? 'Confirm & sign in' : 'Create account & join')}
                        </button>
                    </form>
                </div>
            </div>
        </>
    );
}
