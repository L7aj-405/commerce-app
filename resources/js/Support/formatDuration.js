/** Formats a duration in seconds as a short human string — "45s", "3m 20s", "1h 12m". */
export function formatDuration(seconds) {
    const total = Math.round(Number(seconds) || 0);

    if (total < 60) return `${total}s`;

    const minutes = Math.floor(total / 60);
    const remainingSeconds = total % 60;

    if (minutes < 60) {
        return remainingSeconds > 0 ? `${minutes}m ${remainingSeconds}s` : `${minutes}m`;
    }

    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;

    return remainingMinutes > 0 ? `${hours}h ${remainingMinutes}m` : `${hours}h`;
}
