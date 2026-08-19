export default function SessionStatus({ session, currency = '$' }) {
    const isOpen = !!session;

    return (
        <div className="flex items-center gap-2 text-xs">
            <span
                className={`inline-flex items-center gap-1.5 px-2 py-1 rounded-full font-medium ${
                    isOpen
                        ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30'
                        : 'bg-red-500/15 text-red-700 dark:text-red-300 border border-red-500/30'
                }`}
            >
                <span className={`w-1.5 h-1.5 rounded-full ${isOpen ? 'bg-emerald-400' : 'bg-red-400'}`} />
                {isOpen ? 'Session open' : 'No open session'}
            </span>

            {isOpen && (
                <span className="text-content-muted hidden md:inline">
                    Opened {formatTime(session.opened_at)} · Float {currency}
                    {Number(session.opening_balance ?? 0).toFixed(2)} · Sales {currency}
                    {Number(session.total_sales ?? 0).toFixed(2)}
                </span>
            )}
        </div>
    );
}

function formatTime(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch {
        return iso;
    }
}
