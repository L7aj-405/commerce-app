import { useCallback, useMemo, useReducer } from 'react';

const initialState = {
    items: [],
    discountPercent: 0,
    paymentMethod: 'cash',
    amountPaid: 0,
    customerName: '',
    customerPhone: '',
    customerEmail: '',
    customerType: 'individual',   // 'individual' | 'company' — drives the print format
    companyName: '',
    taxId: '',                    // ICE / VAT, only relevant for companies
    notes: '',
    fulfillmentType: 'instant',  // 'instant' | 'delivery' — instant completes now, delivery queues for the warehouse
    deliveryAddress: '',         // only relevant for delivery orders
    freeDelivery: false,         // when true the shipping fee is waived to 0
    deliveryFee: 0,              // raw entered fee; only charged on a delivery order that isn't free
};

function reducer(state, action) {
    switch (action.type) {
        case 'ADD_ITEM': {
            // `entry` is fully built by addItem() below — it already carries the
            // resolved variant (line_id, variant_id, unit_price, sku…).
            const entry = action.entry;
            const existing = state.items.find((row) => row.line_id === entry.line_id);

            // Merge into the matching line (same product + same variant), capping
            // at the line's known stock so the cart can't exceed what's sellable.
            if (existing) {
                const merged = existing.quantity + entry.quantity;
                const capped = entry.max_stock != null ? Math.min(merged, entry.max_stock) : merged;
                return {
                    ...state,
                    items: state.items.map((row) =>
                        row.line_id === entry.line_id ? { ...row, quantity: capped } : row
                    ),
                };
            }

            return {
                ...state,
                items: [...state.items, { ...entry, discount_percent: 0 }],
            };
        }

        case 'REMOVE_ITEM':
            return {
                ...state,
                items: state.items.filter((row) => row.line_id !== action.lineId),
            };

        case 'UPDATE_QUANTITY': {
            const qty = Math.max(0, Number(action.quantity) || 0);

            if (qty === 0) {
                return {
                    ...state,
                    items: state.items.filter((row) => row.line_id !== action.lineId),
                };
            }

            return {
                ...state,
                items: state.items.map((row) =>
                    row.line_id === action.lineId
                        ? { ...row, quantity: row.max_stock != null ? Math.min(qty, row.max_stock) : qty }
                        : row
                ),
            };
        }

        case 'UPDATE_ITEM_DISCOUNT': {
            const pct = clampPercent(action.discountPercent);
            return {
                ...state,
                items: state.items.map((row) =>
                    row.line_id === action.lineId
                        ? { ...row, discount_percent: pct }
                        : row
                ),
            };
        }

        case 'SET_GLOBAL_DISCOUNT':
            return { ...state, discountPercent: clampPercent(action.discountPercent) };

        case 'SET_PAYMENT_METHOD':
            return { ...state, paymentMethod: action.paymentMethod };

        case 'SET_AMOUNT_PAID':
            return { ...state, amountPaid: Math.max(0, Number(action.amount) || 0) };

        case 'SET_CUSTOMER':
            return {
                ...state,
                customerName: action.payload.customerName ?? state.customerName,
                customerPhone: action.payload.customerPhone ?? state.customerPhone,
                customerEmail: action.payload.customerEmail ?? state.customerEmail,
                customerType: action.payload.customerType ?? state.customerType,
                companyName: action.payload.companyName ?? state.companyName,
                taxId: action.payload.taxId ?? state.taxId,
            };

        case 'SET_NOTES':
            return { ...state, notes: action.notes };

        case 'SET_FULFILLMENT':
            return {
                ...state,
                fulfillmentType: action.payload.fulfillmentType ?? state.fulfillmentType,
                deliveryAddress: action.payload.deliveryAddress ?? state.deliveryAddress,
            };

        case 'SET_DELIVERY':
            return {
                ...state,
                freeDelivery: action.payload.freeDelivery ?? state.freeDelivery,
                deliveryFee: action.payload.deliveryFee !== undefined
                    ? Math.max(0, Number(action.payload.deliveryFee) || 0)
                    : state.deliveryFee,
            };

        case 'CLEAR_CART':
            return initialState;

        default:
            return state;
    }
}

function clampPercent(value) {
    const n = Number(value) || 0;
    if (n < 0) return 0;
    if (n > 100) return 100;
    return n;
}

function lineSubtotal(row) {
    return row.unit_price * row.quantity;
}

function lineTotal(row) {
    const sub = lineSubtotal(row);
    const discount = sub * (row.discount_percent / 100);
    return sub - discount;
}

export default function useCart({ taxRate = 0 } = {}) {
    const [state, dispatch] = useReducer(reducer, initialState);

    const totals = useMemo(() => {
        const subtotal = state.items.reduce((sum, row) => sum + lineTotal(row), 0);
        const discountAmount = subtotal * (state.discountPercent / 100);
        const taxableBase = subtotal - discountAmount;
        const taxAmount = taxableBase * (Number(taxRate) || 0);
        // Shipping is only charged on a delivery order that isn't marked free.
        const deliveryCharge = state.fulfillmentType === 'delivery' && ! state.freeDelivery
            ? (Number(state.deliveryFee) || 0)
            : 0;
        const total = taxableBase + taxAmount + deliveryCharge;
        const change = Math.max(0, (Number(state.amountPaid) || 0) - total);

        return {
            subtotal: round2(subtotal),
            discountAmount: round2(discountAmount),
            taxAmount: round2(taxAmount),
            deliveryCharge: round2(deliveryCharge),
            total: round2(total),
            change: round2(change),
            itemCount: state.items.reduce((sum, row) => sum + row.quantity, 0),
        };
    }, [state.items, state.discountPercent, state.amountPaid, taxRate, state.fulfillmentType, state.freeDelivery, state.deliveryFee]);

    // addItem(product)                        → simple product, qty 1
    // addItem(product, { variant, quantity }) → a specific variant of a variable product
    const addItem = useCallback((product, { variant = null, quantity = 1 } = {}) => {
        dispatch({ type: 'ADD_ITEM', entry: buildEntry(product, variant, quantity) });
    }, []);
    const removeItem     = useCallback((lineId) => dispatch({ type: 'REMOVE_ITEM', lineId }), []);
    const updateQuantity = useCallback((lineId, quantity) => dispatch({ type: 'UPDATE_QUANTITY', lineId, quantity }), []);
    const updateItemDiscount = useCallback((lineId, discountPercent) => dispatch({ type: 'UPDATE_ITEM_DISCOUNT', lineId, discountPercent }), []);
    const setGlobalDiscount  = useCallback((discountPercent) => dispatch({ type: 'SET_GLOBAL_DISCOUNT', discountPercent }), []);
    const setPaymentMethod   = useCallback((paymentMethod) => dispatch({ type: 'SET_PAYMENT_METHOD', paymentMethod }), []);
    const setAmountPaid      = useCallback((amount) => dispatch({ type: 'SET_AMOUNT_PAID', amount }), []);
    const setCustomer        = useCallback((payload) => dispatch({ type: 'SET_CUSTOMER', payload }), []);
    const setNotes           = useCallback((notes) => dispatch({ type: 'SET_NOTES', notes }), []);
    const setFulfillment     = useCallback((payload) => dispatch({ type: 'SET_FULFILLMENT', payload }), []);
    const setDelivery        = useCallback((payload) => dispatch({ type: 'SET_DELIVERY', payload }), []);
    const clearCart          = useCallback(() => dispatch({ type: 'CLEAR_CART' }), []);

    return {
        ...state,
        ...totals,
        taxRate,
        addItem,
        removeItem,
        updateQuantity,
        updateItemDiscount,
        setGlobalDiscount,
        setPaymentMethod,
        setAmountPaid,
        setCustomer,
        setNotes,
        setFulfillment,
        setDelivery,
        clearCart,
    };
}

function round2(n) {
    return Math.round(n * 100) / 100;
}

// Turn a product (and optional resolved variant) into a normalized cart line.
// Variant lines are keyed `${productId}::${variantId}` so the same product with
// two different variants stays as two separate rows.
function buildEntry(product, variant, quantity) {
    const qty = Math.max(1, Number(quantity) || 1);

    if (variant) {
        return {
            line_id: `${product.id}::${variant.id}`,
            product_id: product.id,
            variant_id: variant.id,
            product_name: product.name,
            variant_name: variant.name ?? null,   // "M / Red" — shown under the name
            product_sku: variant.sku ?? '',
            unit_price: Number(variant.price) || 0,
            image: variant.image ?? firstImage(product.images),
            max_stock: variant.stock ?? null,
            quantity: qty,
        };
    }

    return {
        line_id: String(product.id),
        product_id: product.id,
        variant_id: null,
        product_name: product.name,
        variant_name: null,
        product_sku: product.sku ?? '',
        unit_price: Number(product.price) || 0,
        image: firstImage(product.images),
        max_stock: product.stock ?? null,
        quantity: qty,
    };
}

// Pull the first image out of an array, a JSON-encoded array string, or a plain
// string (mirrors ProductCard's getFirstImage so cart thumbnails stay in sync).
function firstImage(imageField) {
    try {
        if (Array.isArray(imageField)) return imageField[0] || null;
        if (typeof imageField === 'string' && imageField.startsWith('[')) {
            return JSON.parse(imageField)[0] || null;
        }
        return imageField || null;
    } catch {
        return null;
    }
}
