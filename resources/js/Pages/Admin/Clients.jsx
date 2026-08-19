import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Users, Eye, Pause, Play } from 'lucide-react';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout';
import DataTable from '@/Components/DataTable';
import SearchFilterBar from '@/Components/SearchFilterBar';

const STATUS_OPTIONS = [
    { value: 'active',    label: 'Active' },
    { value: 'suspended', label: 'Suspended' },
];

export default function Clients({ clients, filters = {} }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [active, setActive] = useState({ status: filters.status ?? '' });

    const applyFilters = (next = active, nextSearch = search) => {
        const params = { search: nextSearch, ...next };
        Object.keys(params).forEach((k) => (params[k] === '' || params[k] == null) && delete params[k]);
        router.get('/admin/clients', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const onSearch       = (q) => { setSearch(q); applyFilters(active, q); };
    const onFilterChange = (k, v) => { const n = { ...active, [k]: v }; setActive(n); applyFilters(n); };

    const toggle = (u) => {
        const url = u.is_active ? `/admin/clients/${u.id}/suspend` : `/admin/clients/${u.id}/activate`;
        router.patch(url, {}, { preserveScroll: true });
    };

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
        { key: 'owned_stores_count', label: 'Stores', align: 'right', render: (u) => <span className="tabular-nums text-content-muted">{u.owned_stores_count ?? 0}</span> },
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
                <div className="inline-flex items-center gap-1">
                    <Link href={`/admin/clients/${u.id}`} className="p-1.5 rounded-md text-content-muted hover:bg-surface-3 hover:text-content" aria-label="View">
                        <Eye className="w-4 h-4" />
                    </Link>
                    <button
                        type="button"
                        onClick={() => toggle(u)}
                        className={`p-1.5 rounded-md hover:bg-surface-3 ${u.is_active ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'}`}
                        aria-label={u.is_active ? 'Suspend' : 'Activate'}
                    >
                        {u.is_active ? <Pause className="w-4 h-4" /> : <Play className="w-4 h-4" />}
                    </button>
                </div>
            ),
        },
    ];

    return (
        <SuperAdminLayout pageHeader={{ title: 'Clients', subtitle: 'Self-registered store owners' }}>
            <div className="mb-4">
                <SearchFilterBar
                    placeholder="Search by name or email…"
                    value={search}
                    onSearch={onSearch}
                    filters={[{ key: 'status', label: 'Status', options: STATUS_OPTIONS }]}
                    activeFilters={active}
                    onFilterChange={onFilterChange}
                />
            </div>

            <DataTable
                columns={columns}
                data={clients.data}
                emptyMessage="No clients match your filters."
                emptyIcon={Users}
                footer={
                    clients.links && clients.links.length > 3 ? (
                        <Pagination links={clients.links} />
                    ) : null
                }
            />
        </SuperAdminLayout>
    );
}

function Pagination({ links }) {
    return (
        <nav className="flex flex-wrap items-center justify-end gap-1 px-4 py-3">
            {links.map((l, i) => (
                <Link
                    key={i}
                    href={l.url ?? '#'}
                    preserveScroll
                    dangerouslySetInnerHTML={{ __html: l.label }}
                    className={[
                        'min-w-8 px-2.5 py-1 rounded-md text-xs transition',
                        l.active ? 'bg-red-500/30 text-white' : 'text-content-muted hover:bg-surface-3',
                        l.url ? '' : 'opacity-40 pointer-events-none',
                    ].join(' ')}
                />
            ))}
        </nav>
    );
}
