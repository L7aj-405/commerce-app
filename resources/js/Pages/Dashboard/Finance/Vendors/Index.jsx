import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Plus, Truck, Pencil, Trash2, X } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';
import Button from '@/Components/Button';

export default function Index({ vendors }) {
    const can = usePage().props.auth?.permissions ?? [];
    const canManage = can.includes('*') || can.includes('finance.manage_vendors');
    const [editing, setEditing] = useState(null);
    const { data, setData, post, patch, processing, errors, reset } = useForm({ name: '', email: '', phone: '', address: '', notes: '', is_active: true });

    const openCreate = () => { reset(); setEditing('new'); };
    const openEdit = (vendor) => {
        setData({ name: vendor.name, email: vendor.email ?? '', phone: vendor.phone ?? '', address: vendor.address ?? '', notes: vendor.notes ?? '', is_active: vendor.is_active });
        setEditing(vendor);
    };
    const close = () => { setEditing(null); reset(); };

    const submit = (e) => {
        e.preventDefault();
        if (editing === 'new') {
            post('/dashboard/finance/vendors', { onSuccess: close, preserveScroll: true });
        } else {
            patch(`/dashboard/finance/vendors/${editing.id}`, { onSuccess: close, preserveScroll: true });
        }
    };

    const columns = [
        { key: 'name', label: 'Name', render: (v) => <span className="font-medium text-content">{v.name}</span> },
        { key: 'contact', label: 'Contact', render: (v) => <span className="text-content-muted">{[v.email, v.phone].filter(Boolean).join(' · ') || '—'}</span> },
        { key: 'usage', label: 'Used by', align: 'right', render: (v) => `${(v.expenses_count ?? 0) + (v.recurring_expenses_count ?? 0)}` },
        { key: 'status', label: 'Status', render: (v) => (
            <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${v.is_active ? 'bg-success-soft text-success' : 'bg-slate-500/15 text-slate-500'}`}>
                {v.is_active ? 'Active' : 'Inactive'}
            </span>
        ) },
        ...(canManage ? [{ key: 'actions', label: '', align: 'right', render: (v) => (
            <div className="flex justify-end gap-1">
                <button type="button" onClick={() => openEdit(v)} className="p-1.5 rounded-lg text-content-muted hover:text-content hover:bg-surface-3" aria-label="Edit">
                    <Pencil className="w-3.5 h-3.5" />
                </button>
                <button
                    type="button"
                    onClick={() => { if (confirm(`Remove "${v.name}"? Vendors in use are deactivated instead of deleted.`)) router.delete(`/dashboard/finance/vendors/${v.id}`, { preserveScroll: true }); }}
                    className="p-1.5 rounded-lg text-content-muted hover:text-danger hover:bg-danger-soft"
                    aria-label="Remove"
                >
                    <Trash2 className="w-3.5 h-3.5" />
                </button>
            </div>
        ) }] : []),
    ];

    return (
        <SaasLayout pageHeader={{
            title: 'Vendors & suppliers',
            subtitle: 'Who you pay — link them to expenses and subscriptions',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Finance', href: '/dashboard/finance' }, { label: 'Vendors' }],
            actions: canManage ? <Button icon={Plus} onClick={openCreate}>Add vendor</Button> : null,
        }}>
            <DataTable columns={columns} data={vendors} emptyIcon={Truck} emptyMessage="No vendors yet." />

            {editing && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
                    <div className="w-full max-w-md rounded-xl bg-surface-2 border border-line p-6 shadow-xl">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-base font-semibold text-content">{editing === 'new' ? 'Add vendor' : 'Edit vendor'}</h3>
                            <button type="button" onClick={close} className="text-content-muted hover:text-content"><X className="w-4 h-4" /></button>
                        </div>
                        <form onSubmit={submit} className="space-y-4">
                            <Field label="Name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} required />
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="Email" type="email" value={data.email} onChange={(v) => setData('email', v)} error={errors.email} />
                                <Field label="Phone" value={data.phone} onChange={(v) => setData('phone', v)} error={errors.phone} />
                            </div>
                            <Field label="Address" value={data.address} onChange={(v) => setData('address', v)} error={errors.address} />
                            <div>
                                <label className="block text-sm font-medium text-content-muted mb-1">Notes</label>
                                <textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2}
                                    className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary" />
                            </div>
                            <label className="flex items-center gap-2 text-sm text-content-muted cursor-pointer">
                                <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} className="rounded bg-surface-3 border-line text-primary" />
                                Active
                            </label>
                            <div className="flex justify-end gap-2 pt-2">
                                <Button type="button" variant="secondary" onClick={close}>Cancel</Button>
                                <Button type="submit" loading={processing}>{editing === 'new' ? 'Create' : 'Save'}</Button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </SaasLayout>
    );
}

function Field({ label, type = 'text', value, onChange, error, required, ...rest }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label} {required && <span className="text-danger">*</span>}</label>
            <input type={type} value={value} onChange={(e) => onChange(e.target.value)} {...rest}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`} />
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}
