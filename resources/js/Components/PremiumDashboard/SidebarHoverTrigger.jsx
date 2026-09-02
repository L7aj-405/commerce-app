/**
 * The ONLY hover-open surface for the full navigation drawer (besides the
 * drawer's own panel, which keeps it open once the cursor is inside it —
 * see SaasLayout.jsx). Deliberately separate from PermissionAwareRail: the
 * icon rail must never open the drawer just because the cursor passes over
 * an icon on its way to clicking it — see the class docblock in
 * PermissionAwareRail.jsx. This is a thin (12px) strip pinned to the true
 * left edge of the screen, to the LEFT of the rail (which starts at
 * left-5/20px) — the two never overlap, so hovering the rail's icons can
 * never also be "inside" this trigger.
 *
 * Purely a hover target: no click handler, no navigation, nothing it does
 * is reachable any other way than by the launcher button (click) or the
 * drawer's own hover.
 */
export default function SidebarHoverTrigger({ onMouseEnter, onMouseLeave, active = false }) {
    return (
        <div
            onMouseEnter={onMouseEnter}
            onMouseLeave={onMouseLeave}
            aria-hidden="true"
            className="group fixed left-0 top-0 z-10 hidden h-full w-3 lg:block"
        >
            {/* Edge highlight — a soft primary glow that brightens on hover,
                hinting "a menu lives behind here" without being loud. */}
            <div
                className={`absolute inset-y-0 left-0 w-px bg-primary/25 transition-all duration-200 group-hover:w-1 group-hover:bg-primary/70 group-hover:shadow-[2px_0_16px_0_var(--primary-soft)] ${active ? 'w-1 bg-primary/70 shadow-[2px_0_16px_0_var(--primary-soft)]' : ''}`}
            />

            {/* The small animated handle — a vertical pill, vertically
                centered, that nudges right and glows on hover so the strip
                doesn't read as a dead, invisible edge. */}
            <div
                className={`absolute left-0 top-1/2 h-10 w-1.5 -translate-y-1/2 rounded-full bg-primary/40 shadow-[0_0_10px_0_var(--primary-soft)] transition-all duration-200 group-hover:translate-x-1 group-hover:bg-primary group-hover:shadow-[0_0_16px_2px_var(--primary-soft)] ${active ? 'translate-x-1 bg-primary shadow-[0_0_16px_2px_var(--primary-soft)]' : ''}`}
            />
        </div>
    );
}
