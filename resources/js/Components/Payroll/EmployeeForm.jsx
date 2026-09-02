import { Loader2, Save } from 'lucide-react';
import Select from '@/Components/Select';

export default function EmployeeForm({ data, setData, errors, processing, options, onSubmit, submitLabel = 'Save', children }) {
    return (
        <form onSubmit={onSubmit} className="bg-surface-2 border border-line rounded-xl p-6 max-w-3xl space-y-6">
            <Section title="Identity">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Field label="First name" value={data.first_name} onChange={(v) => setData('first_name', v)} error={errors.first_name} required />
                    <Field label="Last name" value={data.last_name} onChange={(v) => setData('last_name', v)} error={errors.last_name} />
                </div>
                <Field label="Display name (optional — defaults to first + last name)" value={data.display_name} onChange={(v) => setData('display_name', v)} error={errors.display_name} />
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Field label="Phone" value={data.phone} onChange={(v) => setData('phone', v)} error={errors.phone} />
                    <Field label="Email" type="email" value={data.email} onChange={(v) => setData('email', v)} error={errors.email} />
                </div>
                <Field label="Employee code (optional)" value={data.employee_code} onChange={(v) => setData('employee_code', v)} error={errors.employee_code} />
            </Section>

            <Section title="Role & assignment">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <SelectField label="Role" value={data.role_type} onChange={(v) => setData('role_type', v)} error={errors.role_type}
                        options={[{ value: '', label: 'Not specified' }, ...options.roleTypes.map((r) => ({ value: r.value, label: r.label }))]} />
                    <SelectField label="Employment status" value={data.employment_status} onChange={(v) => setData('employment_status', v)} error={errors.employment_status}
                        options={options.employmentStatuses.map((s) => ({ value: s.value, label: s.label }))} />
                </div>
                <SelectField label="Store (optional — leave blank for organization-level)" value={data.store_id} onChange={(v) => setData('store_id', v)} error={errors.store_id}
                    options={[{ value: '', label: 'Organization-level' }, ...options.stores.map((s) => ({ value: s.id, label: s.name }))]} />
            </Section>

            <Section title="Employment dates">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Field label="Hired on" type="date" value={data.hired_at} onChange={(v) => setData('hired_at', v)} error={errors.hired_at} />
                    <Field label="Left on" type="date" value={data.left_at} onChange={(v) => setData('left_at', v)} error={errors.left_at} />
                </div>
                <div>
                    <label className="block text-sm font-medium text-content-muted mb-1">Notes</label>
                    <textarea value={data.notes ?? ''} onChange={(e) => setData('notes', e.target.value)} rows={2}
                        className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
            </Section>

            {children}

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

function SelectField({ label, value, onChange, error, required, options }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label} {required && <span className="text-danger">*</span>}</label>
            <Select value={value ?? ''} onChange={onChange} options={options} error={Boolean(error)} />
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}
