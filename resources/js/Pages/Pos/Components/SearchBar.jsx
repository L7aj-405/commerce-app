export default function SearchBar({
    query,
    onQueryChange,
    category,
    onCategoryChange,
    categories = [],
}) {
    return (
        <div className="flex gap-2">
            <div className="relative flex-1">
                <svg
                    className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-content-muted pointer-events-none"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    strokeWidth="1.7"
                    aria-hidden="true"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    type="text"
                    value={query}
                    onChange={(e) => onQueryChange(e.target.value)}
                    placeholder="Search products by name or SKU…"
                    aria-label="Search products"
                    className="w-full pl-9 pr-9 py-2 text-sm rounded-lg border border-line bg-surface-3 text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                {query !== '' && (
                    <button
                        type="button"
                        onClick={() => onQueryChange('')}
                        aria-label="Clear search"
                        className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-content-muted hover:text-content"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                )}
            </div>

            {categories.length > 0 && (
                <select
                    value={category}
                    onChange={(e) => onCategoryChange(e.target.value)}
                    aria-label="Filter by category"
                    className="px-3 py-2 text-sm rounded-lg border border-line bg-surface-3 text-content focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    <option value="">All categories</option>
                    {categories.map((c) => (
                        <option key={c} value={c}>
                            {c}
                        </option>
                    ))}
                </select>
            )}
        </div>
    );
}
