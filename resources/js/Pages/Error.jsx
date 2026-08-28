import { Link } from '@inertiajs/react';
import { AlertTriangle, Ban, Clock, FileQuestion, ServerCrash } from 'lucide-react';

const STATUS_META = {
    403: {
        icon: Ban,
        title: "You don't have access to this page",
        fallback: 'You are not allowed to view this page.',
    },
    404: {
        icon: FileQuestion,
        title: 'Page not found',
        fallback: "The page you were looking for doesn't exist or may have moved.",
    },
    419: {
        icon: Clock,
        title: 'Session expired',
        fallback: 'Your session expired for security reasons. Please try again.',
    },
    500: {
        icon: ServerCrash,
        title: 'Something went wrong',
        fallback: 'An unexpected error occurred on our end. Our team has been notified.',
    },
};

/**
 * Branded replacement for Laravel's bare framework error views — rendered
 * directly by App\Support\InertiaErrorResponder for a full-page GET request
 * that hits 403/404/419/500. Deliberately standalone (no SaasLayout): it
 * must render correctly even when the error happened before the normal
 * shared props (auth, brand, ...) were ever set up.
 */
export default function ErrorPage({ status, message }) {
    const meta = STATUS_META[status] ?? {
        icon: AlertTriangle,
        title: 'Something went wrong',
        fallback: 'An unexpected error occurred.',
    };
    const Icon = meta.icon;
    const canGoBack = typeof window !== 'undefined' && window.history.length > 1;

    return (
        <div className="flex min-h-screen items-center justify-center bg-surface-2 px-4">
            <div className="w-full max-w-md rounded-[var(--radius-card)] border border-line bg-surface p-8 text-center shadow-xl">
                <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-danger-soft text-danger">
                    <Icon className="h-7 w-7" />
                </div>

                <p className="mt-5 text-xs font-semibold uppercase tracking-[0.14em] text-content-muted">
                    Error {status}
                </p>
                <h1 className="mt-1.5 text-xl font-semibold text-content">{meta.title}</h1>
                <p className="mt-2 text-sm text-content-muted">{message || meta.fallback}</p>

                <div className="mt-7 flex flex-col gap-2.5 sm:flex-row sm:justify-center">
                    {canGoBack && (
                        <button
                            type="button"
                            onClick={() => window.history.back()}
                            className="inline-flex items-center justify-center gap-1.5 rounded-[var(--radius-button)] border border-line bg-surface-2 px-4 py-2.5 text-sm font-medium text-content hover:bg-surface-3 transition"
                        >
                            Go back
                        </button>
                    )}
                    <Link
                        href="/dashboard"
                        className="inline-flex items-center justify-center gap-1.5 rounded-[var(--radius-button)] bg-primary px-4 py-2.5 text-sm font-semibold text-primary-contrast hover:bg-primary-strong transition"
                    >
                        Back to dashboard
                    </Link>
                </div>
            </div>
        </div>
    );
}
