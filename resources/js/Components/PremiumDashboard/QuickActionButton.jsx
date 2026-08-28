import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';

export default function QuickActionButton({ href, icon: Icon, children, variant = 'primary', className = '' }) {
    const styles = variant === 'secondary'
        ? 'border border-[#e7e9e4] bg-white text-[#2b302c] hover:border-[#cddfd3] hover:bg-[#f7faf8]'
        : 'bg-[#118858] text-white shadow-[0_14px_28px_-16px_rgba(17,136,88,.8)] hover:bg-[#0d774c]';

    return (
        <Link
            href={href}
            className={`inline-flex items-center justify-center gap-2 rounded-full px-4 py-2.5 text-sm font-semibold transition-all duration-200 hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#118858]/35 ${styles} ${className}`}
        >
            {Icon && <Icon className="h-4 w-4" strokeWidth={1.9} />}
            <span>{children}</span>
            {! Icon && <ArrowUpRight className="h-3.5 w-3.5" />}
        </Link>
    );
}
