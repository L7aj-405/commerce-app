import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Plus, Tag, Lock, Pencil, Trash2, X } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';
import Button from '@/Components/Button';

export default function Index({ categories }) {
    const can = usePage().props.auth?.permissions ?? [];
    const canManage = can.includes('*') || can.includes('finance.manage_categories');
    const [editing, setEditing] = useState(null); // null | 'new' | category
    const { data, setData, post, patch, processing, errors, reset } = useForm({ name: '', group: '', color: '', icon: '', is_active: true });

    const openCreate = () => { reset(); setEditing('new'); };
    const openEdit = (category) => {
        setData({ name: category.name, group: category.group ?? '', color: category.color ?? '', icon: category.icon ?? '', is_active: category.is_active });
        setEditing(category);
    };
    const close = () => { setEditing(null); reset(); };

    const submit = (e) => {
        e.preventDefault();
        if (editing === 'new') {
            post('/dashboard/finance/categories', { onSuccess: close, preserveScroll: true });
        } else {
            patch(`/dashboard/finance/categories/${editing.id}`, { onSuccess: close, preserveScroll: true });
        }
    };

    const columns = [
        { key: 'name', label: 'Name', render: (c) => (
            <span className="flex items-center gap-2 font-medium text-content">
                {c.color && <span className="w-2.5 h-2.5 rounded-full flex-shrink-0" style={{ backgroundColor: c.color }} />}
                {c.name}
                {c.is_system && <Lock className="w-3 h-3 text-content-muted" />}
            </span>
        ) },
        { key: 'group', label: 'Group', render: (c) => c.group ?? '—' },
        { key: 'usage', label: 'Used by', align: 'right', render: (c) => `${(c.expenses_count ?? 0) + (c.recurring_expenses_count ?? 0)}` },
        { key: 'status', label: 'Status', render: (c) => (
            <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${c.is_active ? 'bg-success-soft text-success' : 'bg-slate-500/15 text-slate-500'}`}>
                {c.is_active ? 'Active' : 'Inactive'}
            </span>
        ) },
        ...(canManage ? [{ key: 'actions', label: '', align: 'right', render: (c) => (
            <div className="flex justify-end gap-1">
                <button type="button" onClick={() => openEdit(c)} className="p-1.5 rounded-lg text-content-muted hover:text-content hover:bg-surface-3" aria-label="Edit">
                    <Pencil className="w-3.5 h-3.5" />
                </button>
                <button
                    type="button"
                    onClick={() => { if (confirm(`Remove "${c.name}"? In-use or default categories are deactivated instead of deleted.`)) router.delete(`/dashboard/finance/categories/${c.id}`, { preserveScroll: true }); }}
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
            title: 'Expense categories',
            subtitle: 'Organize business expenses into categories',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Finance', href: '/dashboard/finance' }, { label: 'Categories' }],
            actions: canManage ? <Button icon={Plus} onClick={openCreate}>Add category</Button> : null,
        }}>
            <DataTable columns={columns} data={categories} emptyIcon={Tag} emptyMessage="No categories yet." />

            {editing && (
                <CategoryModal
                    isNew={editing === 'new'}
                    data={data}
                    setData={setData}
                    errors={errors}
                    processing={processing}
                    onSubmit={submit}
                    onClose={close}
                />
            )}
        </SaasLayout>
    );
}

function CategoryModal({ isNew, data, setData, errors, processing, onSubmit, onClose }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div className="w-full max-w-md rounded-xl bg-surface-2 border border-line p-6 shadow-xl">
                <div className="flex items-center justify-between mb-4">
                    <h3 className="text-base font-semibold text-content">{isNew ? 'Add category' : 'Edit category'}</h3>
                    <button type="button" onClick={onClose} className="text-content-muted hover:text-content"><X className="w-4 h-4" /></button>
                </div>
                <form onSubmit={onSubmit} className="space-y-4">
                    <Field label="Name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} required />
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Group" value={data.group} onChange={(v) => setData('group', v)} error={errors.group} placeholder="Optional" />
                        <Field label="Color" type="color" value={data.color || '#6366f1'} onChange={(v) => setData('color', v)} error={errors.color} />
                    </div>
                    <label className="flex items-center gap-2 text-sm text-content-muted cursor-pointer">
                        <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} className="rounded bg-surface-3 border-line text-primary" />
                        Active
                    </label>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                        <Button type="submit" loading={processing}>{isNew ? 'Create' : 'Save'}</Button>
                    </div>
                </form>
            </div>
        </div>
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
