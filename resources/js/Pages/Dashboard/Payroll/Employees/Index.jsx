import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Plus, Users, Pencil, Trash2 } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';
import StatusBadge from '@/Components/StatusBadge';
import Button from '@/Components/Button';

export default function Index({ employees, filters, options, can }) {
    const canManage = can?.manage ?? false;
    const rows = employees?.data ?? [];
    const [local, setLocal] = useState({
        store_id: filters.store_id ?? '', role_type: filters.role_type ?? '',
        employment_status: filters.employment_status ?? '', search: filters.search ?? '',
    });

    const applyFilters = (next) => {
        const merged = { ...local, ...next };
        setLocal(merged);
        router.get('/dashboard/employees', Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== '')), { preserveState: true, replace: true });
    };

    const columns = [
        { key: 'display_name', label: 'Employee', render: (e) => (
            <div>
                <p className="font-medium text-content">{e.display_name}</p>
                <p className="text-xs text-content-muted">
                    {e.employee_code ? `${e.employee_code} · ` : ''}{e.store?.name ?? 'Organization-level'}
                    {e.user ? ` · linked to ${e.user.name}` : ' · no login'}
                </p>
            </div>
        ) },
        { key: 'role_type', label: 'Role', render: (e) => e.role_type ? e.role_type.replace(/_/g, ' ') : '—' },
        { key: 'phone', label: 'Contact', render: (e) => <span className="text-xs text-content-muted">{e.phone ?? e.email ?? '—'}</span> },
        { key: 'employment_status', label: 'Status', render: (e) => <StatusBadge status={e.employment_status} type="employment" /> },
        ...(canManage ? [{ key: 'actions', label: '', align: 'right', render: (e) => (
            <div className="flex justify-end gap-1">
                <Link href={`/dashboard/employees/${e.id}/edit`} className="p-1.5 rounded-lg text-content-muted hover:text-content hover:bg-surface-3" aria-label="Edit">
                    <Pencil className="w-3.5 h-3.5" />
                </Link>
                <button type="button" onClick={() => { if (confirm(`Remove ${e.display_name}?`)) router.delete(`/dashboard/employees/${e.id}`, { preserveScroll: true }); }}
                    className="p-1.5 rounded-lg text-content-muted hover:text-danger hover:bg-danger-soft" aria-label="Remove" title="Remove">
                    <Trash2 className="w-3.5 h-3.5" />
                </button>
            </div>
        ) }] : []),
    ];

    return (
        <SaasLayout pageHeader={{
            title: 'Employees',
            subtitle: 'People the business pays — with or without a dashboard login',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Employees' }],
            actions: canManage ? <Link href="/dashboard/employees/create"><Button icon={Plus}>Add employee</Button></Link> : null,
        }}>
            <div className="mb-4 grid grid-cols-2 sm:grid-cols-4 gap-2">
                <input value={local.search} onChange={(e) => applyFilters({ search: e.target.value })} placeholder="Search name, code, phone…"
                    className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content col-span-2 sm:col-span-1" />
                <select value={local.store_id} onChange={(e) => applyFilters({ store_id: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All stores</option>
                    {options.stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
                <select value={local.role_type} onChange={(e) => applyFilters({ role_type: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All roles</option>
                    {options.roleTypes.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                </select>
                <select value={local.employment_status} onChange={(e) => applyFilters({ employment_status: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All statuses</option>
                    {options.employmentStatuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                </select>
            </div>

            <DataTable columns={columns} data={rows} emptyIcon={Users} emptyMessage="No employees match these filters." />

            {Array.isArray(employees?.data) && employees.links?.length > 3 && (
                <nav className="flex flex-wrap items-center justify-end gap-1 mt-6">
                    {employees.links.map((l, i) => (
                        <Link key={i} href={l.url ?? '#'} preserveScroll dangerouslySetInnerHTML={{ __html: l.label }}
                            className={`min-w-8 px-2.5 py-1 rounded-md text-xs transition ${l.active ? 'bg-primary text-white' : 'text-content-muted hover:bg-surface-3 bg-surface-2 border border-line'} ${l.url ? '' : 'opacity-40 pointer-events-none'}`} />
                    ))}
                </nav>
            )}
        </SaasLayout>
    );
}
