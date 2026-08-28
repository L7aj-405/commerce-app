import { BarChart3, ArrowUpRight } from 'lucide-react';
import SoftCard from './SoftCard';
import EmptyMetricState from './EmptyMetricState';

export default function MiniChartCard({ title, subtitle, value, data = [], className = '' }) {
    const max = Math.max(...data.map((point) => Number(point.value) || 0), 0);

    return (
        <SoftCard className={`overflow-hidden p-6 ${className}`}>
            <header className="flex items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[#f1f3ef] text-[#343934]">
                        <BarChart3 className="h-5 w-5" strokeWidth={1.8} />
                    </span>
                    <div>
                        <h2 className="text-sm font-semibold text-[#252925]">{title}</h2>
                        {subtitle && <p className="mt-0.5 text-xs text-[#92978f]">{subtitle}</p>}
                    </div>
                </div>
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-[#f5f6f3] text-[#767c75]">
                    <ArrowUpRight className="h-4 w-4" />
                </span>
            </header>

            {value && <p className="mt-7 text-3xl font-semibold tracking-[-0.04em] text-[#222622]">{value}</p>}

            {data.length === 0 ? (
                <EmptyMetricState
                    title="Trend data not available yet"
                    description="The current dashboard response provides totals but no dated series. No placeholder chart is shown."
                />
            ) : (
                <div className="mt-8 flex h-52 items-end gap-4" aria-label={`${title} chart`}>
                    {data.map((point) => (
                        <div key={point.label} className="flex h-full flex-1 flex-col items-center justify-end gap-2">
                            <div
                                className="w-full max-w-14 rounded-full bg-[#a9d4ba] transition-all duration-500 hover:bg-[#118858]"
                                style={{ height: `${max > 0 ? Math.max((Number(point.value) / max) * 100, 5) : 5}%` }}
                                title={`${point.label}: ${point.value}`}
                            />
                            <span className="text-[10px] font-medium text-[#92978f]">{point.label}</span>
                        </div>
                    ))}
                </div>
            )}
        </SoftCard>
    );
}
