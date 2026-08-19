import { ChevronRight, ChevronLeft, Loader2 } from 'lucide-react';

/** Back / Skip / Continue row shared by every onboarding step. */
export default function WizardFooter({ onBack, onSkip, onContinue, continueLabel = 'Continue', busy = false, disabled = false, finalStep = false }) {
    return (
        <footer className="mt-8 pt-6 border-t border-[#2A2D3A] flex items-center justify-between">
            {onBack ? (
                <button
                    type="button"
                    onClick={onBack}
                    disabled={busy}
                    className="inline-flex items-center gap-1.5 px-4 py-2 text-sm rounded-lg border border-[#2A2D3A] text-slate-300 hover:bg-[#22252F] transition"
                >
                    <ChevronLeft className="w-4 h-4" />
                    Back
                </button>
            ) : <span />}

            <div className="flex items-center gap-2">
                {onSkip && (
                    <button type="button" onClick={onSkip} disabled={busy} className="px-4 py-2 text-sm text-slate-400 hover:text-white">
                        Skip for now
                    </button>
                )}

                <button
                    type="button"
                    onClick={onContinue}
                    disabled={busy || disabled}
                    className={`inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg text-white disabled:opacity-50 disabled:cursor-not-allowed transition ${
                        finalStep ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-indigo-600 hover:bg-indigo-500'
                    }`}
                >
                    {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : null}
                    {continueLabel}
                    {! busy && ! finalStep && <ChevronRight className="w-4 h-4" />}
                </button>
            </div>
        </footer>
    );
}
