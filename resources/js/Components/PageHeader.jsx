import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

export default function PageHeader({ title, subtitle, actions, breadcrumbs = [] }) {
    return (
        <div className="mb-6 pb-5 border-b border-line">
            {breadcrumbs.length > 0 && (
                <nav aria-label="Breadcrumb" className="mb-3 flex items-center text-xs text-content-muted">
                    {breadcrumbs.map((c, i) => (
                        <span key={i} className="flex items-center">
                            {i > 0 && <ChevronRight className="mx-1 w-3 h-3 text-content-muted/60" />}
                            {c.href && i < breadcrumbs.length - 1 ? (
                                <Link href={c.href} className="hover:text-content transition">{c.label}</Link>
                            ) : (
                                <span className={i === breadcrumbs.length - 1 ? 'text-content' : ''}>{c.label}</span>
                            )}
                        </span>
                    ))}
                </nav>
            )}

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-2xl font-bold text-content tracking-tight">{title}</h1>
                    {subtitle && <p className="mt-1 text-sm text-content-muted">{subtitle}</p>}
                </div>
                {actions && <div className="flex items-center gap-2 flex-shrink-0">{actions}</div>}
            </div>
        </div>
    );
}
