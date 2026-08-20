import { Link } from '@inertiajs/react';
import { User, ShieldCheck, Palette } from 'lucide-react';

// Same "switcher across N related pages" pattern as DepartmentNav/OperationsNav.
const PAGES = [
    { key: 'profile',    label: 'Profile',    href: '/settings/profile',    icon: User },
    { key: 'security',   label: 'Security',    href: '/settings/security',   icon: ShieldCheck },
    { key: 'appearance', label: 'Appearance', href: '/settings/appearance', icon: Palette },
];

export default function SettingsNav({ current }) {
    return (
        <nav aria-label="Settings" className="flex items-center gap-1 p-1 mb-6 rounded-xl bg-surface-2 border border-line w-fit overflow-x-auto">
            {PAGES.map((p) => {
                const Icon = p.icon;
                const active = p.key === current;

                return (
                    <Link
                        key={p.key}
                        href={p.href}
                        aria-current={active ? 'page' : undefined}
                        className={`inline-flex items-center gap-2 px-3.5 py-2 text-sm font-semibold rounded-lg whitespace-nowrap transition ${
                            active
                                ? 'bg-surface text-content shadow-sm border border-line'
                                : 'text-content-muted hover:text-content hover:bg-surface-3 border border-transparent'
                        }`}
                    >
                        <Icon className="w-4 h-4" />
                        {p.label}
                    </Link>
                );
            })}
        </nav>
    );
}
