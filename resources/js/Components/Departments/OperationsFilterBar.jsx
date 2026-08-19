/** Warehouse / city / assignee / client-org select filters for an operations queue. */
export default function OperationsFilterBar({ f, showClient }) {
    return (
        <div className="flex flex-wrap items-center gap-2 mb-4">
            <Select value={f.warehouse} onChange={f.setWarehouse} options={f.options.warehouses} placeholder="All warehouses" />
            <Select value={f.city} onChange={f.setCity} options={f.options.cities} placeholder="All cities" />
            <Select value={f.assignee} onChange={f.setAssignee} options={f.options.assignees} placeholder="All employees" />
            {showClient && (
                <Select value={f.client} onChange={f.setClient} options={f.options.clients} placeholder="All clients" />
            )}
        </div>
    );
}

function Select({ value, onChange, options, placeholder }) {
    if (options.length === 0) return null;

    return (
        <select
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className="px-3 py-2 text-sm rounded-lg bg-surface-2 border border-line text-content focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
        >
            <option value="">{placeholder}</option>
            {options.map((o) => <option key={o} value={o}>{o}</option>)}
        </select>
    );
}
