// Same tinted-pill language as StatusBadge, for the two "what kind of thing
// is this" badges used across the dashboard: organization type and store
// type. One source of truth instead of each page hand-rolling its own pill.
const ORGANIZATION_COLORS = {
    merchant: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
    agency:   'bg-indigo-500/15 text-indigo-600 dark:text-indigo-300',
    client:   'bg-amber-500/15 text-amber-700 dark:text-amber-300',
};

const STORE_COLORS = {
    online:   'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    physical: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    hybrid:   'bg-purple-500/15 text-purple-700 dark:text-purple-300',
};

const PALETTES = { organization: ORGANIZATION_COLORS, store: STORE_COLORS };

/** @param {{ kind: 'organization'|'store', value: string }} props */
export default function TypeBadge({ kind = 'organization', value }) {
    if (! value) return null;

    const palette = PALETTES[kind] ?? ORGANIZATION_COLORS;

    return (
        <span className={`shrink-0 px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide ${palette[value] ?? 'bg-content/10 text-content-muted'}`}>
            {value}
        </span>
    );
}
