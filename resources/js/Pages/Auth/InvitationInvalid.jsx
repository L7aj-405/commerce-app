import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CircleDot } from 'lucide-react';

const MESSAGES = {
    expired:    { title: 'This invitation has expired',   body: 'Invitations are valid for 72 hours. Ask your store admin to send a new one.' },
    not_found:  { title: 'Invitation not found',          body: 'This link is invalid or has already been used. Double-check the URL or contact your store admin.' },
};

export default function InvitationInvalid({ reason = 'expired' }) {
    const msg = MESSAGES[reason] ?? MESSAGES.not_found;

    return (
        <>
            <Head title="Invitation invalid" />

            <div className="min-h-screen flex items-center justify-center bg-[#0F1117] text-slate-200 font-sans p-4">
                <div className="w-full max-w-md bg-[#1A1D27] border border-[#2A2D3A] rounded-2xl p-8 text-center">
                    <div className="flex items-center justify-center gap-2 mb-6">
                        <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center">
                            <CircleDot className="w-4 h-4 text-white" />
                        </div>
                        <span className="font-bold text-white text-sm">SaaS Commerce</span>
                    </div>

                    <div className="inline-flex items-center justify-center w-14 h-14 rounded-full bg-amber-500/15 text-amber-400 mb-4">
                        <AlertTriangle className="w-6 h-6" />
                    </div>

                    <h1 className="text-lg font-bold text-white">{msg.title}</h1>
                    <p className="mt-2 text-sm text-slate-400 leading-relaxed">{msg.body}</p>

                    <div className="mt-6 flex flex-col gap-2">
                        <Link
                            href="/login"
                            className="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500 transition"
                        >
                            Go to sign in
                        </Link>
                        <Link
                            href="/"
                            className="text-xs text-slate-400 hover:text-white"
                        >
                            Back to home
                        </Link>
                    </div>
                </div>
            </div>
        </>
    );
}
