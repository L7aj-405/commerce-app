import { Link } from '@inertiajs/react';
import { Plus, Wallet } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';
import StatusBadge from '@/Components/StatusBadge';
import Button from '@/Components/Button';
import { formatDateOnly } from '@/Support/formatDate';

function money(amount) {
    return `${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} MAD`;
}

export default function Index({ periods, can }) {
    const canManage = can?.manage ?? false;
    const rows = periods?.data ?? [];

    const columns = [
        { key: 'period', label: 'Period', render: (p) => (
            <div>
                <p className="font-medium text-content">{formatDateOnly(p.period_start)} → {formatDateOnly(p.period_end)}</p>
                <p className="text-xs text-content-muted">{p.store?.name ?? 'Organization-wide'}{p.pay_date ? ` · pay date ${formatDateOnly(p.pay_date)}` : ''}</p>
            </div>
        ) },
        { key: 'items_count', label: 'Employees', align: 'right', render: (p) => p.items_count ?? 0 },
        { key: 'total_net_amount', label: 'Total net', align: 'right', render: (p) => <span className="font-semibold tabular-nums text-content">{money(p.total_net_amount)}</span> },
        { key: 'status', label: 'Status', render: (p) => <StatusBadge status={p.status} type="payroll_period" /> },
        { key: 'actions', label: '', align: 'right', render: (p) => (
            <Link href={`/dashboard/finance/payroll/${p.id}`} className="text-xs font-semibold text-primary hover:underline">View</Link>
        ) },
    ];

    return (
        <SaasLayout pageHeader={{
            title: 'Payroll',
            subtitle: 'Salary due, review and payment — separate from ordinary recurring expenses',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Finance', href: '/dashboard/finance' }, { label: 'Payroll' }],
            actions: canManage ? <Link href="/dashboard/finance/payroll/create"><Button icon={Plus}>New payroll period</Button></Link> : null,
        }}>
            <DataTable columns={columns} data={rows} emptyIcon={Wallet} emptyMessage="No payroll periods yet." />

            {Array.isArray(periods?.data) && periods.links?.length > 3 && (
                <nav className="flex flex-wrap items-center justify-end gap-1 mt-6">
                    {periods.links.map((l, i) => (
                        <Link key={i} href={l.url ?? '#'} preserveScroll dangerouslySetInnerHTML={{ __html: l.label }}
                            className={`min-w-8 px-2.5 py-1 rounded-md text-xs transition ${l.active ? 'bg-primary text-white' : 'text-content-muted hover:bg-surface-3 bg-surface-2 border border-line'} ${l.url ? '' : 'opacity-40 pointer-events-none'}`} />
                    ))}
                </nav>
            )}
        </SaasLayout>
    );
}
