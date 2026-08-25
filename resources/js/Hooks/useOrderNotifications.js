import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';

const POLL_MS = 20000;

/**
 * Polls GET /dashboard/notifications/order-counts every 20s — the project
 * has no broadcasting infra configured (BROADCAST_CONNECTION=log), so
 * polling is the whole "live" mechanism per this feature's own scope.
 * Pauses while the tab is hidden to avoid piling up background requests.
 */
export default function useOrderNotifications() {
    const [counts, setCounts] = useState({
        new_orders_count: 0,
        confirmation_pending_count: 0,
        unread_notifications_count: 0,
    });
    const [notifications, setNotifications] = useState([]);
    const timerRef = useRef(null);

    const fetchCounts = useCallback(() => {
        if (document.hidden) return;

        axios.get('/dashboard/notifications/order-counts')
            .then((res) => {
                const data = res.data ?? {};
                setCounts({
                    new_orders_count: data.new_orders_count ?? 0,
                    confirmation_pending_count: data.confirmation_pending_count ?? 0,
                    unread_notifications_count: data.unread_notifications_count ?? 0,
                });
                setNotifications(data.latest_notifications ?? []);
            })
            .catch(() => {}); // a transient failure just waits for the next poll
    }, []);

    useEffect(() => {
        fetchCounts();
        timerRef.current = setInterval(fetchCounts, POLL_MS);

        const onVisible = () => { if (! document.hidden) fetchCounts(); };
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            clearInterval(timerRef.current);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [fetchCounts]);

    const markSeen = useCallback((context, orderId = null) => {
        return axios.post('/dashboard/notifications/mark-seen', { context, order_id: orderId })
            .then(() => fetchCounts())
            .catch(() => {});
    }, [fetchCounts]);

    return { counts, notifications, markSeen, refresh: fetchCounts };
}
