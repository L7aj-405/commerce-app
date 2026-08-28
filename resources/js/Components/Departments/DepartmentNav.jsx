import { Link } from '@inertiajs/react';
import { ClipboardCheck, PackageCheck, Truck, Undo2, LayoutGrid } from 'lucide-react';

/**
 * Switcher across the four operational departments.
 *
 * The server sends only the departments this user may work in
 * (DepartmentRegistry::visibleTo), so super admins and store owners see all
 * four and a single-department agent sees one — without the client deciding.
 * A lone department renders as a static label rather than a pointless tab strip.
 */

const ICONS = { ClipboardCheck, PackageCheck, Truck, Undo2 };

export default function DepartmentNav({ departments = [], current }) {
    if (departments.length === 0) return null;

    const active = departments.find((d) => d.key === current);

    return (
        <div className="flex flex-col sm:flex-row sm:items-center gap-3 mb-6">
            {departments.length === 1 ? (
                <div className="inline-flex items-center gap-2 px-3.5 py-2 rounded-[var(--radius-card)] bg-surface-2 border border-line">
                    <Chip department={active ?? departments[0]} />
                </div>
            ) : (
                <nav
                    aria-label="Departments"
                    className="flex items-center gap-1 p-1 rounded-[var(--radius-card)] bg-surface-2 border border-line overflow-x-auto"
                >
                    {departments.map((d) => {
                        const Icon = ICONS[d.icon] ?? ClipboardCheck;
                        const isActive = d.key === current;

                        return (
                            <Link
                                key={d.key}
                                href={d.href}
                                aria-current={isActive ? 'page' : undefined}
                                className={[
                                    'inline-flex items-center gap-2 px-3.5 py-2 text-sm font-semibold rounded-[var(--radius-button)] whitespace-nowrap transition',
                                    isActive
                                        ? 'bg-surface text-content shadow-sm border border-line'
                                        : 'text-content-muted hover:text-content hover:bg-surface-3 border border-transparent',
                                ].join(' ')}
                            >
                                <Icon className="w-4 h-4" />
                                {d.label}
                            </Link>
                        );
                    })}
                </nav>
            )}

            <Link
                href="/dashboard/orders/manage"
                className="sm:ml-auto inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface border border-line text-content-muted hover:text-content hover:bg-surface-3 transition"
            >
                <LayoutGrid className="w-4 h-4" /> All orders
            </Link>
        </div>
    );
}

function Chip({ department }) {
    const Icon = ICONS[department.icon] ?? ClipboardCheck;

    return (
        <>
            <Icon className="w-4 h-4 text-content-muted" />
            <span className="text-sm font-semibold text-content">{department.label}</span>
            <span className="hidden sm:inline text-xs text-content-muted">· {department.short}</span>
        </>
    );
}
