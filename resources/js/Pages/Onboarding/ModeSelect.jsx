import { router } from '@inertiajs/react';
import { Store, Building2, ChevronRight } from 'lucide-react';
import OnboardingShell from '@/Components/Onboarding/OnboardingShell';

const ICONS = { merchant: Store, agency: Building2 };
const HINTS = {
    merchant: 'Set up your own store, warehouse and sales channels.',
    agency: 'Onboard and operate on behalf of multiple client businesses.',
};

/**
 * The literal first onboarding question — "How will you use the platform?"
 * No form, no backend call: picking a card just navigates into that flow.
 */
export default function ModeSelect({ accountModes = [] }) {
    return (
        <OnboardingShell title="Set up your workspace">
            <p className="mt-2 text-sm text-slate-400">How will you use the platform?</p>

            <div className="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                {accountModes.map((mode) => {
                    const Icon = ICONS[mode.value] ?? Store;

                    return (
                        <button
                            key={mode.value}
                            type="button"
                            onClick={() => router.get(`/onboarding/${mode.value}`)}
                            className="group text-left p-5 rounded-xl border border-[#2A2D3A] bg-[#0F1117] hover:border-indigo-500/50 hover:bg-indigo-500/5 transition"
                        >
                            <div className="w-10 h-10 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center">
                                <Icon className="w-5 h-5 text-indigo-400" />
                            </div>
                            <h3 className="mt-3 text-base font-semibold text-white">{mode.label}</h3>
                            <p className="mt-1 text-sm text-slate-400">{HINTS[mode.value]}</p>
                            <div className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-indigo-400 group-hover:gap-1.5 transition-all">
                                Continue <ChevronRight className="w-4 h-4" />
                            </div>
                        </button>
                    );
                })}
            </div>
        </OnboardingShell>
    );
}
