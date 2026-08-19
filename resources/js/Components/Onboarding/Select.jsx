/** Extracted from the original onboarding Wizard so every onboarding page shares one input style. */
export default function Select({ label, icon: Icon, value, onChange, options, placeholder, error, required }) {
    return (
        <div>
            <label className="block text-xs font-medium text-slate-400 mb-1">
                {label} {required && <span className="text-red-400">*</span>}
            </label>
            <div className="relative">
                {Icon && <Icon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500 pointer-events-none" />}
                <select
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className={`w-full ${Icon ? 'pl-9' : 'pl-3'} pr-3 py-2.5 rounded-lg bg-[#0F1117] border ${
                        error ? 'border-red-500/60' : 'border-[#2A2D3A]'
                    } text-slate-200 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 appearance-none`}
                >
                    {placeholder && <option value="">{placeholder}</option>}
                    {options.map((o) => (
                        <option key={o.value} value={o.value}>{o.label}</option>
                    ))}
                </select>
            </div>
            {error && <p className="mt-1 text-xs text-red-400">{error}</p>}
        </div>
    );
}
