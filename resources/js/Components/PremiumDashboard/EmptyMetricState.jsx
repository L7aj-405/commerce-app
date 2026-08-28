import { BarChart3 } from 'lucide-react';

export default function EmptyMetricState({
    icon: Icon = BarChart3,
    title = 'No data yet',
    description = 'This metric will appear when enough activity is available.',
    compact = false,
}) {
    return (
        <div className={`flex flex-col items-center justify-center px-5 text-center ${compact ? 'min-h-32 py-5' : 'min-h-52 py-8'}`}>
            <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#edf6f0] text-[#15845b]">
                <Icon className="h-5 w-5" strokeWidth={1.8} />
            </span>
            <h3 className="mt-3 text-sm font-semibold text-[#242824]">{title}</h3>
            <p className="mt-1 max-w-xs text-xs leading-5 text-[#858b84]">{description}</p>
        </div>
    );
}
