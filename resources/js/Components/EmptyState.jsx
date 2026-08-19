import { Inbox } from 'lucide-react';

export default function EmptyState({ icon: Icon = Inbox, title, description, action }) {
    return (
        <div className="flex flex-col items-center justify-center py-16 px-4 text-center">
            <div className="w-14 h-14 rounded-full bg-surface-3 flex items-center justify-center mb-4">
                <Icon className="w-6 h-6 text-content-muted" />
            </div>
            <h3 className="text-base font-semibold text-content">{title}</h3>
            {description && (
                <p className="mt-1 max-w-sm text-sm text-content-muted">{description}</p>
            )}
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}
