/**
 * Thin wrapper for the `bg-surface-2 border border-line rounded-xl` pattern
 * already repeated ad hoc across Warehouses/Stores/Agency pages. Optional —
 * pages with a bespoke layout (e.g. warehouse cards' ownership line) can
 * keep writing the div directly; this is for new/rebuilt pages so they
 * don't reinvent it.
 */
export default function Card({ title, subtitle, badges, actions, children, className = '' }) {
    const hasHeader = title || subtitle || badges || actions;

    return (
        <div className={`bg-surface-2 border border-line rounded-[var(--radius-card)] p-5 ${className}`}>
            {hasHeader && (
                <div className="flex items-start justify-between gap-3 mb-3">
                    <div className="min-w-0">
                        {title && (
                            <div className="flex items-center gap-1.5">
                                <h3 className="text-sm font-semibold text-content truncate">{title}</h3>
                                {badges}
                            </div>
                        )}
                        {subtitle && <p className="mt-0.5 text-xs text-content-muted truncate">{subtitle}</p>}
                    </div>
                    {actions && <div className="flex items-center gap-2 flex-shrink-0">{actions}</div>}
                </div>
            )}

            {children}
        </div>
    );
}
