// Tinted status chips. Background tint works in both modes; text darkens in
// light mode for contrast. Keyed by a single color name per status.
const COLORS = {
    slate:   'bg-slate-500/15 text-slate-600 dark:text-slate-300',
    blue:    'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    emerald: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    red:     'bg-red-500/15 text-red-700 dark:text-red-300',
    amber:   'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    indigo:  'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
    cyan:    'bg-cyan-500/15 text-cyan-700 dark:text-cyan-300',
};

const DOTS = {
    slate: 'bg-slate-400', blue: 'bg-blue-400', emerald: 'bg-emerald-400',
    red: 'bg-red-400', amber: 'bg-amber-400', indigo: 'bg-indigo-400', cyan: 'bg-cyan-400',
};

const TYPE_MAPS = {
    invoice:  { draft: 'slate', sent: 'blue', paid: 'emerald', overdue: 'red', cancelled: 'slate' },
    payment:  { unpaid: 'red', partial: 'amber', paid: 'emerald' },
    delivery: { pending: 'amber', preparing: 'indigo', ready: 'cyan', shipped: 'blue', delivered: 'emerald', cancelled: 'slate' },
    order:    { completed: 'emerald', pending_delivery: 'amber', cancelled: 'red' },
    // Mirrors App\Enums\FulfillmentStatus. Returns states share the orange/amber
    // end of the scale so the reverse flow reads as one group. waiting_for_stock/
    // ready_for_picking/picking/packing (Step 6/7) share the fulfillment
    // in-progress vocabulary — indigo while warehouse work is happening, amber
    // while blocked on stock.
    fulfillment: {
        pending: 'amber', confirmed: 'blue', in_progress: 'indigo',
        waiting_for_stock: 'amber', ready_for_picking: 'indigo',
        picking: 'indigo', packing: 'indigo', dispatched: 'blue',
        ready_for_delivery: 'cyan', delivered: 'blue',
        completed: 'emerald', cancelled: 'slate',
        returned: 'red', under_inspection: 'amber', return_completed: 'slate',
    },
    // OrderReturn::STATUS_*
    return:   { awaiting_inspection: 'amber', inspecting: 'blue', closed: 'slate' },
    condition: { resellable: 'emerald', damaged: 'red', missing: 'slate' },
    session:  { open: 'emerald', closed: 'slate' },
    stock:    { ok: 'emerald', low: 'amber', critical: 'red' },
};

export default function StatusBadge({ status, type = 'invoice', label }) {
    const color = TYPE_MAPS[type]?.[status] ?? 'slate';
    const display = label ?? (status ? status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ') : '—');

    return (
        <span className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium ${COLORS[color]}`}>
            <span className={`w-1.5 h-1.5 rounded-full ${DOTS[color]}`} />
            {display}
        </span>
    );
}
