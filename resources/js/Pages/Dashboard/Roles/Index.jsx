import { Head, Link, router } from '@inertiajs/react';
import { ShieldCheck, Plus, Pencil, Trash2, Lock, Users } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

export default function RolesIndex({ store, roles = [] }) {
    const deleteRole = (role) => {
        if (! confirm(`Delete the "${role.name}" role?`)) return;
        router.delete(`/dashboard/roles/${role.id}`, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Roles & Permissions" />
            <SaasLayout pageHeader={{
                title: 'Roles & Permissions',
                subtitle: `Define what each role can do in ${store?.name ?? 'your store'}`,
                breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Roles' }],
                actions: (
                    <Link
                        href="/dashboard/roles/create"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                    >
                        <Plus className="w-4 h-4" /> New role
                    </Link>
                ),
            }}>
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    {roles.map((role) => (
                        <div key={role.id} className="flex flex-col rounded-xl border border-line bg-surface-2 p-4">
                            <div className="flex items-start justify-between gap-2">
                                <div className="flex items-center gap-2 min-w-0">
                                    <div className="w-8 h-8 rounded-lg bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 flex items-center justify-center flex-shrink-0">
                                        <ShieldCheck className="w-4 h-4" />
                                    </div>
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-1.5">
                                            <span className="font-semibold text-content truncate">{role.name}</span>
                                            {role.is_locked && <Lock className="w-3 h-3 text-content-muted flex-shrink-0" />}
                                        </div>
                                        {role.is_system && (
                                            <span className="text-[10px] font-medium uppercase tracking-wide text-content-muted">System role</span>
                                        )}
                                    </div>
                                </div>
                                <div className="flex items-center gap-1 flex-shrink-0">
                                    {! role.is_locked && (
                                        <Link
                                            href={`/dashboard/roles/${role.id}/edit`}
                                            className="p-1.5 rounded-md text-content-muted hover:bg-surface-3 hover:text-content"
                                            aria-label="Edit role"
                                        >
                                            <Pencil className="w-4 h-4" />
                                        </Link>
                                    )}
                                    {! role.is_system && (
                                        <button
                                            type="button"
                                            onClick={() => deleteRole(role)}
                                            className="p-1.5 rounded-md text-content-muted hover:bg-red-500/10 hover:text-red-600 dark:text-red-400"
                                            aria-label="Delete role"
                                        >
                                            <Trash2 className="w-4 h-4" />
                                        </button>
                                    )}
                                </div>
                            </div>

                            {role.description && (
                                <p className="mt-3 text-xs text-content-muted leading-relaxed">{role.description}</p>
                            )}

                            <div className="mt-3 flex items-center gap-3 text-xs text-content-muted">
                                <span className="inline-flex items-center gap-1">
                                    <ShieldCheck className="w-3.5 h-3.5" />
                                    {role.permissions?.includes('*') ? 'All permissions' : `${role.permission_count} permissions`}
                                </span>
                                <span className="inline-flex items-center gap-1">
                                    <Users className="w-3.5 h-3.5" />
                                    {role.member_count} {role.member_count === 1 ? 'member' : 'members'}
                                </span>
                            </div>
                        </div>
                    ))}
                </div>
            </SaasLayout>
        </>
    );
}
