// Shared date/time formatting for Finance (and any other page that wants a
// consistent, readable rendering instead of a raw ISO string). Deliberately
// locale-independent (dd/mm/yyyy) so it reads the same for every user,
// rather than following the browser's locale like `toLocaleDateString()`.

function toDate(value) {
    if (! value) return null;
    const date = value instanceof Date ? value : new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

function pad(n) {
    return String(n).padStart(2, '0');
}

/** "2026-08-30T14:05:00Z" -> "30/08/2026 14:05" */
export function formatDateTime(value) {
    const date = toDate(value);
    if (! date) return '—';
    return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** "2026-08-30" -> "30/08/2026" (no time portion, for date-only values). */
export function formatDateOnly(value) {
    const date = toDate(value);
    if (! date) return '—';
    return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;
}
