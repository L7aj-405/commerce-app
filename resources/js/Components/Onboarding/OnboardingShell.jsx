import { Head } from '@inertiajs/react';
import { CircleDot, CheckCircle2 } from 'lucide-react';

/**
 * Shared page chrome for every onboarding screen — header, step circles,
 * progress bar and the card that wraps the active step's content. Extracted
 * from the original single-page Wizard so the mode-select, merchant and
 * agency flows all look like one product instead of three.
 */
export default function OnboardingShell({ title, subtitle, steps, currentStep, children }) {
    const progress = steps ? (currentStep / steps.length) * 100 : 0;

    return (
        <>
            <Head title={title} />

            <div className="min-h-screen bg-[#0F1117] text-slate-200 font-sans">
                <header className="border-b border-[#2A2D3A] px-6 py-4">
                    <div className="max-w-3xl mx-auto flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <div className="w-7 h-7 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center">
                                <CircleDot className="w-4 h-4 text-white" />
                            </div>
                            <span className="font-bold text-white text-sm">SaaS Commerce</span>
                        </div>
                        {steps && <div className="text-xs text-slate-500">Step {currentStep} of {steps.length}</div>}
                    </div>
                </header>

                <div className="max-w-3xl mx-auto px-6 pt-8 pb-16">
                    {steps && (
                        <div className="mb-8">
                            <div className="flex items-center justify-between mb-2">
                                {steps.map((s, i) => {
                                    const num     = i + 1;
                                    const done    = num < currentStep;
                                    const current = num === currentStep;
                                    return (
                                        <div key={s} className="flex-1 flex items-center">
                                            <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition ${
                                                done    ? 'bg-emerald-500 text-white' :
                                                current ? 'bg-indigo-600 text-white ring-4 ring-indigo-500/20' :
                                                          'bg-[#1A1D27] border border-[#2A2D3A] text-slate-500'
                                            }`}>
                                                {done ? <CheckCircle2 className="w-4 h-4" /> : num}
                                            </div>
                                            {num < steps.length && (
                                                <div className={`flex-1 h-0.5 mx-2 ${done ? 'bg-emerald-500' : 'bg-[#2A2D3A]'}`} />
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                            <div className="h-1.5 bg-[#1A1D27] border border-[#2A2D3A] rounded-full overflow-hidden">
                                <div className="h-full bg-gradient-to-r from-indigo-500 to-indigo-400 transition-all duration-300" style={{ width: `${progress}%` }} />
                            </div>
                        </div>
                    )}

                    <div className="bg-[#1A1D27] border border-[#2A2D3A] rounded-2xl p-8">
                        <h1 className="text-2xl font-bold text-white tracking-tight">{steps ? steps[currentStep - 1] : title}</h1>
                        {subtitle && <p className="mt-1 text-sm text-slate-400">{subtitle}</p>}

                        {children}
                    </div>
                </div>
            </div>
        </>
    );
}
