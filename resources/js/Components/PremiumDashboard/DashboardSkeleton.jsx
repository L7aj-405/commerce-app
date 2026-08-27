export default function DashboardSkeleton() {
    return (
        <div className="space-y-6" aria-label="Dashboard loading">
            <div className="flex items-center justify-between gap-4">
                <div className="space-y-3">
                    <div className="skeleton-shimmer h-9 w-72 rounded-full" />
                    <div className="skeleton-shimmer h-4 w-48 rounded-full" />
                </div>
                <div className="skeleton-shimmer h-11 w-36 rounded-full" />
            </div>
            <div className="grid gap-5 xl:grid-cols-[280px_minmax(0,1fr)_300px]">
                <div className="skeleton-shimmer h-[360px] rounded-[28px]" />
                <div className="skeleton-shimmer h-[360px] rounded-[28px]" />
                <div className="skeleton-shimmer h-[360px] rounded-[28px]" />
            </div>
            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_300px]">
                <div className="skeleton-shimmer h-[360px] rounded-[28px]" />
                <div className="space-y-5">
                    <div className="skeleton-shimmer h-40 rounded-[28px]" />
                    <div className="skeleton-shimmer h-40 rounded-[28px]" />
                </div>
            </div>
        </div>
    );
}
