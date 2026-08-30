import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Plus, Landmark, Star, Pencil, Trash2, X } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';
import Button from '@/Components/Button';

const TYPES = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'card', label: 'Card / TPE' },
    { value: 'cod_receivable', label: 'COD Receivable' },
    { value: 'delivery_company', label: 'Delivery Company Balance' },
    { value: 'other', label: 'Other' },
];

export default function Index({ accounts, stores }) {
    const can = usePage().props.auth?.permissions ?? [];
    const canManage = can.includes('*') || can.includes('finance.manage_accounts');
    const [editing, setEditing] = useState(null); // null | 'new' | account
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        name: '', type: 'cash', store_id: '', currency: 'MAD', is_default: false, is_active: true,
    });

    const openCreate = () => { reset(); setEditing('new'); };
    const openEdit = (account) => {
        setData({
            name: account.name, type: account.type, store_id: account.store_id ?? '',
            currency: account.currency, is_default: account.is_default, is_active: account.is_active,
        });
        setEditing(account);
    };
    const close = () => { setEditing(null); reset(); };

    const submit = (e) => {
        e.preventDefault();
        if (editing === 'new') {
            post('/dashboard/finance/accounts', { onSuccess: close, preserveScroll: true });
        } else {
            patch(`/dashboard/finance/accounts/${editing.id}`, { onSuccess: close, preserveScroll: true });
        }
    };

    const columns = [
        { key: 'name', label: 'Name', render: (a) => (
            <span className="flex items-center gap-2 font-medium text-content">
                {a.name}
                {a.is_default && <Star className="w-3.5 h-3.5 fill-warning text-warning" />}
            </span>
        ) },
        { key: 'type', label: 'Type', render: (a) => TYPES.find((t) => t.value === a.type)?.label ?? a.type },
        { key: 'store', label: 'Scope', render: (a) => a.store?.name ?? 'Organization-level' },
        { key: 'usage', label: 'Used by', align: 'right', render: (a) => `${a.transactions_count ?? 0}` },
        { key: 'status', label: 'Status', render: (a) => (
            <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${a.is_active ? 'bg-success-soft text-success' : 'bg-slate-500/15 text-slate-500'}`}>
                {a.is_active ? 'Active' : 'Inactive'}
            </span>
        ) },
        ...(canManage ? [{ key: 'actions', label: '', align: 'right', render: (a) => (
            <div className="flex justify-end gap-1">
                <button type="button" onClick={() => openEdit(a)} className="p-1.5 rounded-lg text-content-muted hover:text-content hover:bg-surface-3" aria-label="Edit">
                    <Pencil className="w-3.5 h-3.5" />
                </button>
                <button
                    type="button"
                    onClick={() => { if (confirm(`Remove "${a.name}"? Accounts already used in the ledger are deactivated instead of deleted.`)) router.delete(`/dashboard/finance/accounts/${a.id}`, { preserveScroll: true }); }}
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
            title: 'Finance accounts',
            subtitle: 'Where cash actually lives — cash drawer, bank, card terminal, COD receivable',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Finance', href: '/dashboard/finance' }, { label: 'Accounts' }],
            actions: canManage ? <Button icon={Plus} onClick={openCreate}>Add account</Button> : null,
        }}>
            <DataTable columns={columns} data={accounts} emptyIcon={Landmark} emptyMessage="No accounts yet." />

            {editing && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
                    <div className="w-full max-w-md rounded-xl bg-surface-2 border border-line p-6 shadow-xl">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-base font-semibold text-content">{editing === 'new' ? 'Add account' : 'Edit account'}</h3>
                            <button type="button" onClick={close} className="text-content-muted hover:text-content"><X className="w-4 h-4" /></button>
                        </div>
                        <form onSubmit={submit} className="space-y-4">
                            <Field label="Name" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} required />
                            <Select label="Type" value={data.type} onChange={(v) => setData('type', v)} error={errors.type} options={TYPES} />
                            <Select label="Store (optional)" value={data.store_id} onChange={(v) => setData('store_id', v)} error={errors.store_id}
                                options={[{ value: '', label: 'Organization-level' }, ...stores.map((s) => ({ value: s.id, label: s.name }))]} />
                            <Field label="Currency" value={data.currency} onChange={(v) => setData('currency', v)} error={errors.currency} />
                            <label className="flex items-center gap-2 text-sm text-content-muted cursor-pointer">
                                <input type="checkbox" checked={data.is_default} onChange={(e) => setData('is_default', e.target.checked)} className="rounded bg-surface-3 border-line text-primary" />
                                Default account
                            </label>
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

function Select({ label, value, onChange, error, options }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label}</label>
            <select value={value} onChange={(e) => onChange(e.target.value)}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`}>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}
