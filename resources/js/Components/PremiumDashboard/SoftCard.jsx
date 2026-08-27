export default function SoftCard({ as: Component = 'section', children, className = '' }) {
    return (
        <Component
            className={`rounded-[28px] border border-white/80 bg-white shadow-[0_24px_70px_-48px_rgba(41,54,45,0.42)] ${className}`}
        >
            {children}
        </Component>
    );
}
