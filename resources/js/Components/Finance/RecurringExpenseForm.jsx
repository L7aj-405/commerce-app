import { Loader2, Save } from 'lucide-react';

const FREQUENCIES = [
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'yearly', label: 'Yearly' },
];

export default function RecurringExpenseForm({ data, setData, errors, processing, options, onSubmit, submitLabel = 'Save' }) {
    return (
        <form onSubmit={onSubmit} className="bg-surface-2 border border-line rounded-xl p-6 max-w-3xl space-y-6">
            <Section title="Subscription details">
                <Field label="Title" value={data.title} onChange={(v) => setData('title', v)} error={errors.title} required placeholder="e.g. insolea.com domain renewal" />
                <div>
                    <label className="block text-sm font-medium text-content-muted mb-1">Description</label>
                    <textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2}
                        className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Amount" type="number" step="0.01" min="0.01" value={data.amount} onChange={(v) => setData('amount', v)} error={errors.amount} required />
                    <Field label="Currency" value={data.currency} onChange={(v) => setData('currency', v)} error={errors.currency} />
                </div>
            </Section>

            <Section title="Classification">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Select label="Category" value={data.category_id} onChange={(v) => setData('category_id', v)} error={errors.category_id} required
                        options={[{ value: '', label: 'Select a category' }, ...options.categories.map((c) => ({ value: c.id, label: c.name }))]} />
                    <Select label="Vendor" value={data.vendor_id} onChange={(v) => setData('vendor_id', v)} error={errors.vendor_id}
                        options={[{ value: '', label: 'No vendor' }, ...options.vendors.map((v) => ({ value: v.id, label: v.name }))]} />
                </div>
                <Select label="Store (optional)" value={data.store_id} onChange={(v) => setData('store_id', v)} error={errors.store_id}
                    options={[{ value: '', label: 'Organization-level' }, ...options.stores.map((s) => ({ value: s.id, label: s.name }))]} />
            </Section>

            <Section title="Schedule">
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Select label="Frequency" value={data.frequency} onChange={(v) => setData('frequency', v)} error={errors.frequency} required options={FREQUENCIES} />
                    <Field label="Starts on" type="date" value={data.starts_at} onChange={(v) => setData('starts_at', v)} error={errors.starts_at} required />
                    <Field label="Next due date" type="date" value={data.next_due_at} onChange={(v) => setData('next_due_at', v)} error={errors.next_due_at} required />
                </div>
                <Field label="Reminder (days before due)" type="number" min="0" max="365" value={data.reminder_days_before} onChange={(v) => setData('reminder_days_before', v)} error={errors.reminder_days_before} />
            </Section>

            <Section title="Auto-generation">
                <label className="flex items-center gap-2 text-sm text-content-muted cursor-pointer">
                    <input type="checkbox" checked={data.auto_create_expense} onChange={(e) => setData('auto_create_expense', e.target.checked)}
                        className="rounded bg-surface-3 border-line text-primary" />
                    Automatically create an expense when this becomes due
                </label>
                <Select label="Generated expense starts as" value={data.generated_expense_status} onChange={(v) => setData('generated_expense_status', v)} error={errors.generated_expense_status}
                    options={[{ value: 'unpaid', label: 'Unpaid' }, { value: 'paid', label: 'Paid' }]} />
                <div>
                    <label className="block text-sm font-medium text-content-muted mb-1">Notes</label>
                    <textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2}
                        className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary" />
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

function Field({ label, type = 'text', value, onChange, error, required, ...rest }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label} {required && <span className="text-danger">*</span>}</label>
            <input type={type} value={value ?? ''} onChange={(e) => onChange(e.target.value)} {...rest}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`} />
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}

function Select({ label, value, onChange, error, required, options }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label} {required && <span className="text-danger">*</span>}</label>
            <select value={value ?? ''} onChange={(e) => onChange(e.target.value)}
                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`}>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}
