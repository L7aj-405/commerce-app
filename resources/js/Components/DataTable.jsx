import { Inbox } from 'lucide-react';

export default function DataTable({
    columns = [],
    data = [],
    loading = false,
    onRowClick,
    emptyMessage = 'No records found.',
    emptyIcon: EmptyIcon = Inbox,
    footer,
}) {
    return (
        <div className="bg-surface-2 border border-line rounded-xl overflow-hidden shadow-sm dark:shadow-none">
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-surface-3 text-xs uppercase tracking-wider text-content-muted border-b border-line">
                        <tr>
                            {columns.map((c) => (
                                <th
                                    key={c.key}
                                    style={c.width ? { width: c.width } : undefined}
                                    className={`px-4 py-3 ${c.align === 'right' ? 'text-right' : 'text-left'}`}
                                >
                                    {c.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {loading ? (
                            Array.from({ length: 5 }).map((_, i) => (
                                <tr key={`skeleton-${i}`}>
                                    {columns.map((c) => (
                                        <td key={c.key} className="px-4 py-3.5">
                                            <div className="h-3 rounded bg-content/10 animate-pulse" />
                                        </td>
                                    ))}
                                </tr>
                            ))
                        ) : data.length === 0 ? (
                            <tr>
                                <td colSpan={columns.length} className="px-4 py-16">
                                    <div className="flex flex-col items-center justify-center text-center text-content-muted">
                                        <div className="w-12 h-12 mb-3 rounded-full bg-surface-3 flex items-center justify-center">
                                            <EmptyIcon className="w-5 h-5 text-content-muted" />
                                        </div>
                                        <p className="text-sm text-content-muted">{emptyMessage}</p>
                                    </div>
                                </td>
                            </tr>
                        ) : data.map((row, idx) => (
                            <tr
                                key={row.id ?? idx}
                                onClick={onRowClick ? () => onRowClick(row) : undefined}
                                className={`text-content-muted transition ${onRowClick ? 'cursor-pointer hover:bg-surface-3' : 'hover:bg-surface-3'}`}
                            >
                                {columns.map((c) => (
                                    <td
                                        key={c.key}
                                        className={`px-4 py-3.5 ${c.align === 'right' ? 'text-right' : 'text-left'} ${c.cellClassName ?? ''}`}
                                    >
                                        {c.render ? c.render(row) : row[c.key]}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {footer && <div className="border-t border-line">{footer}</div>}
        </div>
    );
}
