import { Search, Sparkles } from 'lucide-react';

/**
 * Opens the existing CommandPalette (Cmd/Ctrl+K, searches every accessible
 * nav item) — this component never filters/searches anything itself, it's
 * just a styled trigger. On the dashboard root it renders as a wide inline
 * pill (the "global search / command bar" the center topbar shows there);
 * everywhere else it collapses to a small icon button next to the
 * contextual module tabs.
 *
 * The small sparkle affordance is a reserved, disabled placeholder for a
 * future AI assistant entry point — no behavior yet, per design brief.
 */
export default function CommandSearchBar({ variant = 'icon', onOpen }) {
    if (variant === 'inline') {
        return (
            <button
                type="button"
                onClick={onOpen}
                className="mx-auto flex w-full max-w-md items-center gap-2.5 rounded-full border border-border bg-input px-4 py-2.5 text-left text-sm text-text-muted transition hover:border-primary/40 hover:text-text"
                aria-label="Search navigation"
            >
                <Search className="h-4 w-4 flex-shrink-0" strokeWidth={1.9} />
                <span className="flex-1 truncate">Search orders, products, stock…</span>
                <span
                    title="AI assistant — coming soon"
                    className="flex items-center gap-1 rounded-full bg-surface-soft px-2 py-0.5 text-[10px] font-semibold text-text-muted"
                >
                    <Sparkles className="h-3 w-3" /> Soon
                </span>
            </button>
        );
    }

    return (
        <button type="button" onClick={onOpen} className="flex h-10 w-10 items-center justify-center rounded-full text-text-muted transition hover:bg-surface-soft hover:text-primary" aria-label="Search navigation">
            <Search className="h-[18px] w-[18px]" strokeWidth={1.9} />
        </button>
    );
}
