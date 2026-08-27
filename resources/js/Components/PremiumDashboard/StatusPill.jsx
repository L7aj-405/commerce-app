const TONES = {
    pending: 'bg-amber-50 text-amber-700',
    waiting_for_stock: 'bg-amber-50 text-amber-700',
    confirmed: 'bg-sky-50 text-sky-700',
    in_progress: 'bg-sky-50 text-sky-700',
    ready_for_picking: 'bg-violet-50 text-violet-700',
    picking: 'bg-violet-50 text-violet-700',
    packing: 'bg-violet-50 text-violet-700',
    dispatched: 'bg-cyan-50 text-cyan-700',
    ready_for_delivery: 'bg-cyan-50 text-cyan-700',
    delivered: 'bg-emerald-50 text-emerald-700',
    completed: 'bg-emerald-50 text-emerald-700',
    paid: 'bg-emerald-50 text-emerald-700',
    cancelled: 'bg-rose-50 text-rose-700',
    returned: 'bg-rose-50 text-rose-700',
};

export default function StatusPill({ status, label }) {
    const text = label ?? String(status ?? 'Unknown').replaceAll('_', ' ');

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold capitalize ${TONES[status] ?? 'bg-[#f0f1ed] text-[#6f756e]'}`}>
            <span className="h-1.5 w-1.5 rounded-full bg-current opacity-70" />
            {text}
        </span>
    );
}
