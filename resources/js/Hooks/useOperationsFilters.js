import { useState, useMemo } from 'react';

/**
 * Warehouse / city / assigned-employee / client-organization filters layered
 * on top of a queue's rows — the four dimensions every operations table
 * offers (client org only matters, and only appears, in agency context).
 */
export default function useOperationsFilters(rows) {
    const [warehouse, setWarehouse] = useState('');
    const [city, setCity]           = useState('');
    const [assignee, setAssignee]   = useState('');
    const [client, setClient]       = useState('');

    const options = useMemo(() => ({
        warehouses: uniqueSorted(rows, (r) => r.allocation?.warehouse_name),
        cities:     uniqueSorted(rows, (r) => r.shipping_city_name),
        assignees:  uniqueSorted(rows, (r) => r.assignee_name),
        clients:    uniqueSorted(rows, (r) => r.client_organization_name),
    }), [rows]);

    const filtered = useMemo(() => rows.filter((r) => (
        (! warehouse || r.allocation?.warehouse_name === warehouse)
        && (! city || r.shipping_city_name === city)
        && (! assignee || r.assignee_name === assignee)
        && (! client || r.client_organization_name === client)
    )), [rows, warehouse, city, assignee, client]);

    return {
        filtered,
        warehouse, setWarehouse,
        city, setCity,
        assignee, setAssignee,
        client, setClient,
        options,
    };
}

function uniqueSorted(rows, pick) {
    return Array.from(new Set(rows.map(pick).filter(Boolean))).sort();
}
