import { Head } from '@inertiajs/react';
import { CircleDot } from 'lucide-react';

/**
 * Shared shell for the secondary auth screens (verify email, two-factor
 * challenge, confirm password) — same dark theme and card style as
 * Pages/Auth/Register.jsx and Pages/Onboarding/*, just without the marketing
 * side panel since these are utility screens, not the signup page.
 */
export default function AuthLayout({ title, subtitle, children }) {
    return (
        <>
            <Head title={title} />

            <div className="min-h-screen flex items-center justify-center bg-[#0F1117] text-slate-200 font-sans p-6">
                <div className="w-full max-w-md">
                    <div className="flex items-center justify-center gap-2 mb-8">
                        <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center">
                            <CircleDot className="w-4 h-4 text-white" />
                        </div>
                        <span className="font-bold text-white">SaaS Commerce</span>
                    </div>

                    <div className="bg-[#1A1D27] border border-[#2A2D3A] rounded-2xl p-8">
                        <h1 className="text-xl font-bold text-white tracking-tight">{title}</h1>
                        {subtitle && <p className="mt-1 text-sm text-slate-400">{subtitle}</p>}

                        {children}
                    </div>
                </div>
            </div>
        </>
    );
}
