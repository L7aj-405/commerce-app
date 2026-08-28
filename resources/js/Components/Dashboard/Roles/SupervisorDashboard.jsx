import { Link } from '@inertiajs/react';
import { AlertTriangle, ClipboardCheck, ClipboardList, PackageCheck, Truck, Users } from 'lucide-react';
import StatsCard from '@/Components/StatsCard';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';

const QUEUE_META = {
    confirmation: { label: 'Confirmation', href: '/dashboard/departments/confirmation', icon: ClipboardCheck },
    fulfillment: { label: 'Pick & Pack', href: '/dashboard/departments/packing', icon: PackageCheck },
    delivery: { label: 'Delivery', href: '/dashboard/departments/dispatch', icon: Truck },
};

export default function SupervisorDashboard({ operations }) {
    const { queues, waiting_stock_count, delayed_orders_count, team_activity_today, leaderboard } = operations;

    const workload = Object.entries(leaderboard).flatMap(([phase, rows]) =>
        rows.map((row) => ({ ...row, phase })),
    );

    return (
        <div className="page-enter space-y-6">
            <header>
                <h1 className="text-2xl font-bold tracking-tight text-content">Operations control</h1>
                <p className="mt-1 text-sm text-content-muted">Queue health, team workload, and today's bottlenecks.</p>
            </header>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                {Object.entries(QUEUE_META).map(([phase, meta]) => (
                    <Link key={phase} href={meta.href} className="block">
                        <StatsCard label={`${meta.label} queue`} value={queues[phase] ?? 0} icon={meta.icon} color="primary" />
                    </Link>
                ))}
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatsCard label="Waiting for stock" value={waiting_stock_count} icon={ClipboardList} color={waiting_stock_count > 0 ? 'yellow' : 'green'} />
                <StatsCard label="Delayed orders (24h+)" value={delayed_orders_count} icon={AlertTriangle} color={delayed_orders_count > 0 ? 'red' : 'green'} />
            </div>

            <Card title="Team workload" subtitle="Current assigned load per agent, by queue">
                {workload.length === 0 ? (
                    <EmptyState icon={Users} title="No team members yet" description="Agents with confirmation, fulfillment, or dispatch permissions will show up here once assigned to this store." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs uppercase tracking-wider text-content-muted">
                                    <th className="py-2 pr-4">Agent</th>
                                    <th className="py-2 pr-4">Queue</th>
                                    <th className="py-2 text-right">Assigned</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {workload.map((row) => (
                                    <tr key={`${row.phase}-${row.id}`}>
                                        <td className="py-2 pr-4">
                                            <span className="inline-flex items-center gap-2">
                                                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary">{row.initials}</span>
                                                <span className={row.is_you ? 'font-semibold text-content' : 'text-content'}>{row.name}{row.is_you ? ' (you)' : ''}</span>
                                            </span>
                                        </td>
                                        <td className="py-2 pr-4 capitalize text-content-muted">{QUEUE_META[row.phase]?.label ?? row.phase}</td>
                                        <td className="py-2 text-right font-semibold tabular-nums text-content">{row.assigned}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Card title="Team activity today" subtitle="Every recorded action across the team">
                {Object.keys(team_activity_today).length === 0 ? (
                    <EmptyState icon={ClipboardList} title="No activity recorded yet today" />
                ) : (
                    <div className="flex flex-wrap gap-2">
                        {Object.entries(team_activity_today).map(([type, count]) => (
                            <span key={type} className="inline-flex items-center gap-1.5 rounded-full bg-surface-soft px-3 py-1.5 text-xs font-medium text-content-muted">
                                {type} <span className="font-bold text-content">{count}</span>
                            </span>
                        ))}
                    </div>
                )}
            </Card>
        </div>
    );
}
