import { Link } from '@inertiajs/react';
import { Users, Building2, TrendingUp, Eye } from 'lucide-react';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import StatsCard from '@/Components/StatsCard';
import DataTable from '@/Components/DataTable';

export default function Dashboard({ stats, recent }) {
    const columns = [
        {
            key: 'name',
            label: 'Client',
            render: (u) => (
                <div className="flex items-center gap-2.5">
                    <div className="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-indigo-700 text-white text-xs font-bold flex items-center justify-center">
                        {(u.name ?? '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase()}
                    </div>
                    <div className="min-w-0">
                        <div className="text-content font-medium truncate">{u.name}</div>
                        <div className="text-xs text-content-muted truncate">{u.email}</div>
                    </div>
                </div>
            ),
        },
        {
            key: 'is_active',
            label: 'Status',
            render: (u) => u.is_active
                ? <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">
                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" /> Active
                  </span>
                : <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs bg-red-500/15 text-red-700 dark:text-red-300">
                    <span className="w-1.5 h-1.5 rounded-full bg-red-400" /> Suspended
                  </span>,
        },
        { key: 'created_at', label: 'Joined', render: (u) => <span className="text-xs text-content-muted">{new Date(u.created_at).toLocaleDateString()}</span> },
        {
            key: 'actions',
            label: '',
            align: 'right',
            render: (u) => (
                <Link href={`/admin/clients/${u.id}`} className="p-1.5 rounded-md text-content-muted hover:bg-surface-3 hover:text-content inline-flex">
                    <Eye className="w-4 h-4" />
                </Link>
            ),
        },
    ];

    return (
        <SuperAdminLayout pageHeader={{ title: 'Platform overview', subtitle: 'Cross-tenant signups and platform health' }}>
            <section className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <StatsCard label="Total clients"    value={stats.total_clients}  icon={Users}      color="indigo" />
                <StatsCard label="Active stores"    value={stats.active_stores}  icon={Building2}  color="green" />
                <StatsCard label="New this month"   value={stats.new_this_month} icon={TrendingUp} color="blue" />
            </section>

            <div className="mb-3 flex items-center justify-between">
                <h2 className="text-sm font-semibold text-content">Recent signups</h2>
                <Link href="/admin/clients" className="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:text-indigo-300">View all →</Link>
            </div>

            <DataTable columns={columns} data={recent} emptyMessage="No signups yet." emptyIcon={Users} />
        </SuperAdminLayout>
    );
}
