import { Head, Link, useForm } from '@inertiajs/react';
import { Loader2, ArrowLeft, Save, ShieldCheck, Lock } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

export default function RoleForm({ store, catalog = [], role = null }) {
    const isEdit   = role !== null;
    const isSystem = role?.is_system ?? false;
    const isLocked = role?.is_locked ?? false;

    const { data, setData, post, patch, processing, errors } = useForm({
        name:        role?.name ?? '',
        description: role?.description ?? '',
        permissions: role?.permissions?.includes('*')
            ? catalog.flatMap((g) => g.permissions.map((p) => p.key))
            : (role?.permissions ?? []),
    });

    const allKeys   = catalog.flatMap((g) => g.permissions.map((p) => p.key));
    const has       = (key) => data.permissions.includes(key);
    const toggle    = (key) => setData('permissions', has(key)
        ? data.permissions.filter((k) => k !== key)
        : [...data.permissions, key]);

    const groupKeys = (group) => group.permissions.map((p) => p.key);
    const groupAll  = (group) => groupKeys(group).every((k) => has(k));
    const toggleGroup = (group) => {
        const keys = groupKeys(group);
        setData('permissions', groupAll(group)
            ? data.permissions.filter((k) => ! keys.includes(k))
            : [...new Set([...data.permissions, ...keys])]);
    };

    const submit = (e) => {
        e.preventDefault();
        if (isEdit) {
            patch(`/dashboard/roles/${role.id}`);
        } else {
            post('/dashboard/roles');
        }
    };

    return (
        <>
            <Head title={isEdit ? `Edit ${role.name}` : 'New role'} />
            <SaasLayout pageHeader={{
                title: isEdit ? `Edit role — ${role.name}` : 'Create a role',
                subtitle: `Choose exactly what this role can do in ${store?.name ?? 'your store'}`,
                breadcrumbs: [
                    { label: 'Dashboard', href: '/dashboard' },
                    { label: 'Roles', href: '/dashboard/roles' },
                    { label: isEdit ? 'Edit' : 'New' },
                ],
                actions: (
                    <Link
                        href="/dashboard/roles"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3"
                    >
                        <ArrowLeft className="w-4 h-4" /> Back
                    </Link>
                ),
            }}>
                {isLocked && (
                    <div className="mb-4 flex items-center gap-2 px-4 py-3 rounded-lg border border-amber-500/30 bg-amber-500/10 text-sm text-amber-700 dark:text-amber-300">
                        <Lock className="w-4 h-4" /> This is a locked system role and cannot be changed.
                    </div>
                )}

                <form onSubmit={submit} className="max-w-3xl space-y-6">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-medium text-content-muted mb-1">Role name</label>
                            <input
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                disabled={isSystem}
                                placeholder="e.g. Warehouse Staff"
                                className={`w-full px-3 py-2.5 rounded-lg bg-surface-2 border ${
                                    errors.name ? 'border-red-500/60' : 'border-line'
                                } text-content placeholder:text-content-muted focus:outline-none focus:border-indigo-500 disabled:opacity-60`}
                            />
                            {isSystem && <p className="mt-1 text-[11px] text-content-muted">System role names can’t be changed.</p>}
                            {errors.name && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.name}</p>}
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-content-muted mb-1">Description</label>
                            <input
                                type="text"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder="Short summary of this role"
                                className="w-full px-3 py-2.5 rounded-lg bg-surface-2 border border-line text-content placeholder:text-content-muted focus:outline-none focus:border-indigo-500"
                            />
                        </div>
                    </div>

                    <div>
                        <div className="flex items-center justify-between mb-3">
                            <div className="flex items-center gap-2 text-sm font-semibold text-content">
                                <ShieldCheck className="w-4 h-4 text-indigo-600 dark:text-indigo-400" /> Permissions
                                <span className="text-content-muted font-normal">({data.permissions.length}/{allKeys.length})</span>
                            </div>
                            <button
                                type="button"
                                onClick={() => setData('permissions', data.permissions.length === allKeys.length ? [] : allKeys)}
                                disabled={isLocked}
                                className="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:text-indigo-300 disabled:opacity-50"
                            >
                                {data.permissions.length === allKeys.length ? 'Clear all' : 'Select all'}
                            </button>
                        </div>

                        <div className="space-y-3">
                            {catalog.map((group) => (
                                <div key={group.group} className="rounded-xl border border-line bg-surface-2 overflow-hidden">
                                    <label className="flex items-center justify-between px-4 py-3 border-b border-line cursor-pointer">
                                        <span className="text-sm font-medium text-content">{group.label}</span>
                                        <input
                                            type="checkbox"
                                            checked={groupAll(group)}
                                            onChange={() => toggleGroup(group)}
                                            disabled={isLocked}
                                            className="h-4 w-4 rounded border-slate-600 bg-surface text-indigo-500 focus:ring-indigo-500/40"
                                        />
                                    </label>
                                    <div className="divide-y divide-line">
                                        {group.permissions.map((perm) => (
                                            <label key={perm.key} className="flex items-start gap-3 px-4 py-3 cursor-pointer hover:bg-surface-3">
                                                <input
                                                    type="checkbox"
                                                    checked={has(perm.key)}
                                                    onChange={() => toggle(perm.key)}
                                                    disabled={isLocked}
                                                    className="mt-0.5 h-4 w-4 rounded border-slate-600 bg-surface text-indigo-500 focus:ring-indigo-500/40"
                                                />
                                                <div className="min-w-0">
                                                    <div className="text-sm text-content">{perm.label}</div>
                                                    <div className="text-xs text-content-muted">{perm.description}</div>
                                                </div>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                        {errors.permissions && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.permissions}</p>}
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="submit"
                            disabled={processing || isLocked || ! data.name}
                            className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                        >
                            {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving…</> : <><Save className="w-4 h-4" /> {isEdit ? 'Save changes' : 'Create role'}</>}
                        </button>
                        <Link href="/dashboard/roles" className="px-4 py-2.5 text-sm font-medium text-content-muted hover:text-content">Cancel</Link>
                    </div>
                </form>
            </SaasLayout>
        </>
    );
}
