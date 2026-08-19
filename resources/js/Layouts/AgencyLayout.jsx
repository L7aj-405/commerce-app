import { Link, usePage } from '@inertiajs/react';

export default function AgencyLayout({ children, title }) {
    const { props } = usePage();
    return (
        <div className="min-h-screen bg-surface text-content">
            <header className="border-b border-line bg-surface px-6 py-4 flex items-center justify-between">
                <div>
                    <div className="font-semibold">Agency workspace</div>
                    <div className="text-xs text-content-muted">{props.auth?.user?.name}</div>
                </div>
                <nav className="flex gap-3 text-sm">
                    <Link href="/agency/clients" className="text-indigo-600">Clients</Link>
                    <Link href="/agency/warehouses" className="text-indigo-600">Warehouses</Link>
                    <Link href="/settings/profile" className="text-content-muted">Profile</Link>
                </nav>
            </header>
            <main className="max-w-6xl mx-auto p-6">
                {title && <h1 className="text-2xl font-semibold mb-6">{title}</h1>}
                {children}
            </main>
        </div>
    );
}
