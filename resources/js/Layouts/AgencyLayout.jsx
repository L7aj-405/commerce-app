import { Link, usePage } from '@inertiajs/react';
import { CircleDot, Users, Warehouse, UserCircle } from 'lucide-react';
import PageHeader from '@/Components/PageHeader';
import TypeBadge from '@/Components/TypeBadge';
import ToastNotification from '@/Components/ToastNotification';

const NAV = [
    { label: 'Clients',    href: '/agency/clients',    icon: Users },
    { label: 'Warehouses', href: '/agency/warehouses', icon: Warehouse },
];

/**
 * Lightweight shell for the agency workspace — deliberately not a second
 * SaasLayout sidebar (that's a bigger structural change than a visual pass).
 * Shows the same organization-context language SaasLayout gets from
 * StoreSwitcher: name + TypeBadge, so an agency operator sees which
 * workspace they're in the same way a merchant does.
 */
export default function AgencyLayout({ children, title, pageHeader }) {
    const { props, url } = usePage();
    const agency = props.auth?.agency;

    const header = pageHeader ?? (title ? { title } : null);

    return (
        <div className="min-h-screen bg-surface text-content font-sans">
            <header className="border-b border-line bg-surface px-6 py-4">
                <div className="max-w-6xl mx-auto flex items-center justify-between gap-4">
                    <div className="flex items-center gap-3 min-w-0">
                        <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center flex-shrink-0">
                            <CircleDot className="w-4 h-4 text-white" />
                        </div>
                        <div className="min-w-0">
                            <div className="flex items-center gap-1.5">
                                <span className="font-semibold text-content truncate">{agency?.name ?? 'Agency workspace'}</span>
                                <TypeBadge kind="organization" value="agency" />
                            </div>
                            <div className="text-xs text-content-muted truncate">{props.auth?.user?.name}</div>
                        </div>
                    </div>

                    <nav className="flex items-center gap-1 flex-shrink-0">
                        {NAV.map((item) => {
                            const Icon = item.icon;
                            const active = url.startsWith(item.href);
                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg transition ${
                                        active ? 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300' : 'text-content-muted hover:text-content hover:bg-surface-2'
                                    }`}
                                >
                                    <Icon className="w-3.5 h-3.5" /> {item.label}
                                </Link>
                            );
                        })}
                        <Link
                            href="/settings/profile"
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg text-content-muted hover:text-content hover:bg-surface-2 transition"
                        >
                            <UserCircle className="w-3.5 h-3.5" /> Profile
                        </Link>
                    </nav>
                </div>
            </header>

            <main className="max-w-6xl mx-auto p-6">
                {header && <PageHeader {...header} />}
                {children}
            </main>

            <ToastNotification />
        </div>
    );
}
