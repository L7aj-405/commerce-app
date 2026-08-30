import { Loader2, Save } from 'lucide-react';

const PAYMENT_METHODS = [
    { value: '', label: 'Not specified' },
    { value: 'cash', label: 'Cash' },
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'card', label: 'Card' },
    { value: 'cheque', label: 'Cheque' },
    { value: 'cod_settlement', label: 'COD settlement' },
    { value: 'other', label: 'Other' },
];

export default function ExpenseForm({ data, setData, errors, processing, options, onSubmit, submitLabel = 'Save', ledgerLocked = false }) {
    return (
        <form onSubmit={onSubmit} className="bg-surface-2 border border-line rounded-xl p-6 max-w-3xl space-y-6">
            {ledgerLocked && (
                <div className="rounded-lg border border-warning/30 bg-warning-soft px-3 py-2 text-sm text-warning">
                    This expense is already paid — amount, currency, payment method and date are locked to match the recorded transaction. Mark it back to unpaid first to change any of those.
                </div>
            )}

            <Section title="Expense details">
                <Field label="Title" value={data.title} onChange={(v) => setData('title', v)} error={errors.title} required />
                <div>
                    <label className="block text-sm font-medium text-content-muted mb-1">Description</label>
                    <textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2}
                        className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Amount" type="number" step="0.01" min="0.01" value={data.amount} onChange={(v) => setData('amount', v)} error={errors.amount} required disabled={ledgerLocked} />
                    <Field label="Currency" value={data.currency} onChange={(v) => setData('currency', v)} error={errors.currency} disabled={ledgerLocked} />
                </div>
            </Section>

            <Section title="Classification">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Select label="Category" value={data.category_id} onChange={(v) => setData('category_id', v)} error={errors.category_id} required
                        options={[{ value: '', label: 'Select a category' }, ...options.categories.map((c) => ({ value: c.id, label: c.name }))]} />
                    <Select label="Vendor" value={data.vendor_id} onChange={(v) => setData('vendor_id', v)} error={errors.vendor_id}
                        options={[{ value: '', label: 'No vendor' }, ...options.vendors.map((v) => ({ value: v.id, label: v.name }))]} />
                </div>
                <Select label="Store (optional — leave blank for organization-level)" value={data.store_id} onChange={(v) => setData('store_id', v)} error={errors.store_id}
                    options={[{ value: '', label: 'Organization-level' }, ...options.stores.map((s) => ({ value: s.id, label: s.name }))]} />
            </Section>

            <Section title="Dates & payment">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Field label="Expense date" type="date" value={data.expense_date} onChange={(v) => setData('expense_date', v)} error={errors.expense_date} required disabled={ledgerLocked} />
                    <Field label="Due date" type="date" value={data.due_date} onChange={(v) => setData('due_date', v)} error={errors.due_date} />
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Select label="Payment method" value={data.payment_method} onChange={(v) => setData('payment_method', v)} error={errors.payment_method} options={PAYMENT_METHODS} disabled={ledgerLocked} />
                    <Field label="Reference" value={data.reference} onChange={(v) => setData('reference', v)} error={errors.reference} placeholder="Invoice #, receipt #…" />
                </div>
            </Section>

            <button type="submit" disabled={processing} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-primary text-white hover:bg-primary-strong disabled:opacity-50">
                {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving…</> : <><Save className="w-4 h-4" /> {submitLabel}</>}
            </button>
        </form>
    );
}

function Section({ title, children }) {
    return <div className="space-y-4"><h3 className="text-sm font-semibold text-content">{title}</h3>{children}</div>;
}

function Field({ label, type = 'text', value, onChange, error, required, disabled, ...rest }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label} {required && <span className="text-danger">*</span>}</label>
            <input type={type} value={value ?? ''} onChange={(e) => onChange(e.target.value)} disabled={disabled} {...rest}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-60 disabled:cursor-not-allowed`} />
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}

function Select({ label, value, onChange, error, required, options, disabled }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label} {required && <span className="text-danger">*</span>}</label>
            <select value={value ?? ''} onChange={(e) => onChange(e.target.value)} disabled={disabled}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-60 disabled:cursor-not-allowed`}>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}
