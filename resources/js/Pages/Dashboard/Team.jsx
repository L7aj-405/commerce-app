import { Link, router } from '@inertiajs/react';
import { Users, Mail, Trash2, Clock, UserPlus, Pencil } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';

const RoleBadge = ({ name }) => (
    <span className="inline-flex px-2 py-0.5 rounded-md text-xs font-medium bg-primary-soft text-primary">
        {name ?? '—'}
    </span>
);

export default function Team({ store, members, invitations }) {
    const removeMember = (member) => {
        if (! confirm(`Remove ${member.user?.name} from this store?`)) return;
        router.delete(`/dashboard/team/${member.id}`, { preserveScroll: true });
    };

    const revokeInvite = (invitation) => {
        if (! confirm(`Revoke invitation for ${invitation.email}?`)) return;
        router.delete(`/dashboard/invitations/${invitation.id}`, { preserveScroll: true });
    };

    const memberColumns = [
        {
            key: 'user',
            label: 'Member',
            render: (m) => (
                <div className="flex items-center gap-2.5">
                    <div className="w-8 h-8 rounded-full bg-primary text-primary-contrast text-xs font-bold flex items-center justify-center">
                        {(m.user?.name ?? '?').split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase()}
                    </div>
                    <div className="min-w-0">
                        <div className="text-content font-medium truncate">{m.user?.name}</div>
                        <div className="text-xs text-content-muted truncate">{m.user?.email}</div>
                    </div>
                </div>
            ),
        },
        {
            key: 'role',
            label: 'Role',
            render: (m) => <RoleBadge name={m.role_name ?? 'Team member'} />,
        },
        { key: 'joined_at', label: 'Joined', render: (m) => <span className="text-xs text-content-muted">{m.joined_at ? new Date(m.joined_at).toLocaleDateString() : '—'}</span> },
        {
            key: 'actions',
            label: '',
            align: 'right',
            render: (m) => (
                <div className="flex items-center justify-end gap-1">
                    <Link
                        href={`/dashboard/team/members/${m.id}/edit`}
                        className="p-1.5 rounded-md text-content-muted hover:bg-surface-3 hover:text-content"
                        aria-label="Edit member"
                    >
                        <Pencil className="w-4 h-4" />
                    </Link>
                    {! m.is_owner && (
                        <button
                            type="button"
                            onClick={() => removeMember(m)}
                            className="p-1.5 rounded-md text-content-muted hover:bg-danger-soft hover:text-danger"
                            aria-label="Remove member"
                        >
                            <Trash2 className="w-4 h-4" />
                        </button>
                    )}
                </div>
            ),
        },
    ];

    const invitationColumns = [
        {
            key: 'email',
            label: 'Email',
            render: (i) => (
                <div className="flex items-center gap-2 text-content">
                    <Mail className="w-4 h-4 text-content-muted" />
                    <span>{i.email}</span>
                </div>
            ),
        },
        {
            key: 'role',
            label: 'Role',
            render: (i) => <RoleBadge name={i.role_name} />,
        },
        { key: 'expires_at', label: 'Expires', render: (i) => <span className="text-xs text-content-muted"><Clock className="inline w-3 h-3 mr-1" />{new Date(i.expires_at).toLocaleString()}</span> },
        {
            key: 'actions',
            label: '',
            align: 'right',
            render: (i) => (
                <button
                    type="button"
                    onClick={() => revokeInvite(i)}
                    className="px-2 py-1 text-xs font-medium rounded-md bg-surface border border-line text-content-muted hover:bg-danger-soft hover:text-danger hover:border-danger/30"
                >
                    Revoke
                </button>
            ),
        },
    ];

    return (
        <SaasLayout pageHeader={{
            title: 'Team',
            subtitle: `Manage who has access to ${store?.name ?? 'your store'}`,
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Team' }],
            actions: (
                <div className="flex items-center gap-2">
                    <Link
                        href="/dashboard/team/invite"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted hover:bg-surface-3 transition"
                    >
                        <Mail className="w-4 h-4" /> Invite by email
                    </Link>
                    <Link
                        href="/dashboard/team/add"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong transition"
                    >
                        <UserPlus className="w-4 h-4" /> Add member
                    </Link>
                </div>
            ),
        }}>
            <section className="mb-6">
                <h2 className="text-sm font-semibold text-content mb-3">Active members <span className="text-content-muted font-normal">({members.length})</span></h2>
                <DataTable columns={memberColumns} data={members} emptyMessage="No active members yet." emptyIcon={Users} />
            </section>

            <section>
                <h2 className="text-sm font-semibold text-content mb-3">Pending invitations <span className="text-content-muted font-normal">({invitations.length})</span></h2>
                <DataTable columns={invitationColumns} data={invitations} emptyMessage="No pending invitations." emptyIcon={Mail} />
            </section>
        </SaasLayout>
    );
}
