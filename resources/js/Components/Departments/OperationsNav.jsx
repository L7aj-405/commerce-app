import { Link } from '@inertiajs/react';
import { ClipboardList, PackageSearch, Package, PackageCheck, Truck, ArrowLeftRight, LayoutGrid } from 'lucide-react';

/**
 * Switcher across the five single-station operations queues.
 *
 * Separate from DepartmentNav on purpose — these are narrower, warehouse-
 * operator-scoped lenses onto the same fulfillment phase the combined
 * "Pick & Pack" department page already covers, and mixing the two nav lists
 * would make "Packing" ambiguous (the combined bench vs. this focused queue).
 */

const PAGES = [
    { key: 'waiting-stock',  label: 'Waiting for stock', href: '/dashboard/operations/waiting-stock',  icon: ClipboardList },
    { key: 'picking',        label: 'Picking Queue',      href: '/dashboard/operations/picking',        icon: PackageSearch },
    { key: 'packing',        label: 'Packing Queue',      href: '/dashboard/operations/packing',        icon: Package },
    { key: 'ready-delivery', label: 'Ready for Dispatch', href: '/dashboard/operations/ready-delivery', icon: PackageCheck },
    { key: 'transfers',      label: 'Transfer Receiving', href: '/dashboard/operations/transfers',     icon: ArrowLeftRight },
];

export default function OperationsNav({ current }) {
    return (
        <div className="flex flex-col sm:flex-row sm:items-center gap-3 mb-6">
            <nav
                aria-label="Operations queues"
                className="flex items-center gap-1 p-1 rounded-xl bg-surface-2 border border-line overflow-x-auto"
            >
                {PAGES.map((p) => {
                    const Icon = p.icon;
                    const isActive = p.key === current;

                    return (
                        <Link
                            key={p.key}
                            href={p.href}
                            aria-current={isActive ? 'page' : undefined}
                            className={[
                                'inline-flex items-center gap-2 px-3.5 py-2 text-sm font-semibold rounded-lg whitespace-nowrap transition',
                                isActive
                                    ? 'bg-surface text-content shadow-sm border border-line'
                                    : 'text-content-muted hover:text-content hover:bg-surface-3 border border-transparent',
                            ].join(' ')}
                        >
                            <Icon className="w-4 h-4" />
                            {p.label}
                        </Link>
                    );
                })}
            </nav>

            <Link
                href="/dashboard/departments/packing"
                className="sm:ml-auto inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface border border-line text-content-muted hover:text-content hover:bg-surface-3 transition"
            >
                <Truck className="w-4 h-4" /> Pick &amp; Pack Workbench
            </Link>
            <Link
                href="/dashboard/orders/manage"
                className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface border border-line text-content-muted hover:text-content hover:bg-surface-3 transition"
            >
                <LayoutGrid className="w-4 h-4" /> All orders
            </Link>
        </div>
    );
}
