// Tinted status chips. Background tint works in both modes; text darkens in
// light mode for contrast. Keyed by a single color name per status.
// emerald/red/amber are the semantic success/warning/danger states — routed
// through those tokens so they harmonize with the brand color instead of a
// raw Tailwind hue, while staying semantically fixed (they never become the
// brand primary itself). slate/blue/indigo/cyan are purely informational
// categories, unrelated to brand or semantic meaning — left as-is.
const COLORS = {
    slate:   'bg-slate-500/15 text-slate-600 dark:text-slate-300',
    blue:    'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    emerald: 'bg-success-soft text-success',
    red:     'bg-danger-soft text-danger',
    amber:   'bg-warning-soft text-warning',
    indigo:  'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
    cyan:    'bg-cyan-500/15 text-cyan-700 dark:text-cyan-300',
};

const DOTS = {
    slate: 'bg-slate-400', blue: 'bg-blue-400', emerald: 'bg-success',
    red: 'bg-danger', amber: 'bg-warning', indigo: 'bg-indigo-400', cyan: 'bg-cyan-400',
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
    // ProductChannelListing/ProductVariantChannelListing.sync_status, and
    // PlatformConnection.webhook_status (pending/verified/failed)
    sync:     { pending: 'amber', synced: 'emerald', error: 'red', verified: 'emerald', failed: 'red' },
    // Connection Profile auth section — deliberately distinct from `sync`
    // above (auth ≠ sync status, never share a status vocabulary).
    auth:     { connected: 'emerald', needs_setup: 'amber', error: 'red' },
    connection: { active: 'emerald', pending: 'amber', disconnected: 'slate', failed: 'red' },
    // DeliveryConnection.status (Ozon Express etc.)
    delivery_connection: { connected: 'emerald', error: 'red', disabled: 'slate' },
    // City sync is its own status, independent of delivery_connection above.
    city_sync: { synced: 'emerald', sync_failed: 'red', not_synced: 'slate' },
    // Per-row city-mapping suggestion status (DeliveryCityMappingSuggestionService).
    city_match: { mapped: 'emerald', exact: 'emerald', suggested: 'blue', needs_review: 'amber', no_match: 'slate' },
    // Integrations Center provider cards (commerce/delivery/tools) — a
    // single vocabulary shared by every category so a card's badge never
    // has to know which category it's in.
    integration_card: {
        connected: 'emerald', not_connected: 'slate', error: 'red',
        needs_attention: 'amber', coming_soon: 'slate',
    },
    // Shipment::normalizedStatuses() — external-carrier tracking state.
    shipment: {
        draft: 'slate', created: 'blue', sent_to_carrier: 'indigo', awaiting_pickup: 'indigo',
        picked_up: 'indigo', in_transit: 'indigo', out_for_delivery: 'cyan', delivered: 'emerald',
        failed_attempt: 'amber', returned: 'red', refused: 'red', cancelled: 'slate', unknown: 'slate',
        provider_unverified: 'amber',
    },
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
