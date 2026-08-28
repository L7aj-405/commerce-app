import { useEffect, useState } from 'react';
import { CreditCard, Radio } from 'lucide-react';
import SoftCard from './SoftCard';

export default function PremiumMetricCard({
    label,
    value,
    helper,
    secondaryLabel,
    secondaryValue,
    currency,
    icon: Icon = CreditCard,
}) {
    const animatedValue = useCountUp(value);

    return (
        <div className="space-y-4">
            <SoftCard className="group relative min-h-[245px] overflow-hidden bg-[#118858] p-6 text-white shadow-[0_28px_60px_-34px_rgba(17,136,88,.75)] transition-transform duration-300 hover:-translate-y-1">
                <div className="pointer-events-none absolute -right-14 -top-16 h-44 w-44 rounded-full border border-white/10" />
                <div className="pointer-events-none absolute -right-5 -top-7 h-32 w-32 rounded-full border border-white/10" />
                <div className="relative flex items-start justify-between">
                    <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-white/12 ring-1 ring-white/15">
                        <Icon className="h-5 w-5" strokeWidth={1.8} />
                    </span>
                    <Radio className="h-5 w-5 rotate-90 text-white/75" strokeWidth={1.8} />
                </div>
                <div className="relative mt-10">
                    <p className="text-xs font-medium text-white/70">{label}</p>
                    <p className="mt-2 text-[2rem] font-semibold tracking-[-0.045em] tabular-nums">
                        {currency && <span className="mr-2 text-base font-medium text-white/70">{currency}</span>}
                        {animatedValue}
                    </p>
                    {helper && <p className="mt-2 text-xs text-white/65">{helper}</p>}
                </div>
                <div className="absolute inset-x-6 bottom-5 flex items-center justify-between text-[11px] text-white/65">
                    <span>Current store metric</span>
                    <span className="font-mono tracking-[0.18em]">•••• {new Date().getFullYear()}</span>
                </div>
            </SoftCard>

            <SoftCard className="flex items-center justify-between gap-4 p-5">
                <div>
                    <p className="text-xs font-medium text-[#8a9089]">{secondaryLabel}</p>
                    <p className="mt-1 text-xl font-semibold tracking-tight text-[#252925] tabular-nums">{secondaryValue}</p>
                </div>
                <span className="rounded-full bg-[#e8f5ed] px-3 py-1 text-[11px] font-semibold text-[#118858]">Current</span>
            </SoftCard>
        </div>
    );
}

function useCountUp(value) {
    const target = Number(value) || 0;
    const [display, setDisplay] = useState(target);

    useEffect(() => {
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
            setDisplay(target);
            return undefined;
        }

        const startedAt = performance.now();
        let frame;
        const tick = (now) => {
            const progress = Math.min((now - startedAt) / 700, 1);
            setDisplay(target * (1 - Math.pow(1 - progress, 3)));
            if (progress < 1) frame = requestAnimationFrame(tick);
        };
        frame = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(frame);
    }, [target]);

    return display.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
