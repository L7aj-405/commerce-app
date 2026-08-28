<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Enums\CustomerType;
use App\Enums\FulfillmentType;
use App\Http\Controllers\Controller;
use App\Models\PosSession;
use App\Services\Pos\DocumentGenerationService;
use App\Services\Pos\OrderProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function store(
        Request $request,
        OrderProcessingService $orderService,        // ← Parameter
        DocumentGenerationService $documentService,  // ← Parameter
    ): JsonResponse {
        $validated = $request->validate([
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.product_id'        => ['required', 'string', 'exists:products,id'],
            'items.*.variant_id'        => ['nullable', 'string', 'exists:product_variants,id'],
            'items.*.product_name'      => ['required', 'string'],
            'items.*.product_sku'       => ['nullable', 'string'],
            'items.*.unit_price'        => ['required', 'numeric', 'min:0'],
            'items.*.quantity'          => ['required', 'integer', 'min:1'],
            'items.*.subtotal'          => ['required', 'numeric', 'min:0'],
            'items.*.line_total'        => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_amount'   => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_percent'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_amount'        => ['nullable', 'numeric', 'min:0'],

            'subtotal'        => ['required', 'numeric', 'min:0'],
            'tax_amount'      => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount'    => ['required', 'numeric', 'min:0'],

            'payment_method'  => ['required', 'in:cash,card,check,mobile,mixed'],
            'amount_paid'     => ['required', 'numeric', 'min:0'],
            'change_amount'   => ['nullable', 'numeric', 'min:0'],

            'customer_name'   => ['nullable', 'string', 'max:255'],
            'customer_phone'  => ['nullable', 'string', 'max:50'],
            'customer_email'  => ['nullable', 'email', 'max:255'],
            'customer_type'   => ['nullable', Rule::enum(CustomerType::class)],
            'company_name'    => ['nullable', 'required_if:customer_type,company', 'string', 'max:255'],
            'tax_id'          => ['nullable', 'string', 'max:100'],
            'notes'           => ['nullable', 'string'],

            'fulfillment_type' => ['nullable', Rule::enum(FulfillmentType::class)],
            'delivery_address' => ['nullable', 'required_if:fulfillment_type,delivery', 'string', 'max:1000'],
            'delivery_fee'     => ['nullable', 'numeric', 'min:0'],
        ]);

        $store   = $request->attributes->get('pos_store');
        $cashier = $request->user();

        $session = PosSession::query()
            ->where('store_id', $store->id)
            ->where('cashier_id', $cashier->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        if ($session === null) {
            return response()->json([
                'message' => 'No open POS session. Open one before checkout.',
            ], 422);
        }

        // ✅ Use $orderService (parameter), not $this->orderService
        $order = $orderService->createOrder(
            store: $store,
            cashier: $cashier,
            data: $validated,
        );

        // ✅ Use $documentService (parameter)
        $receiptPath = $documentService->generateReceipt($order);
        $order->update(['receipt_path' => $receiptPath]);

        // ✅ Use $orderService (parameter)
        $orderService->adjustInventory($order, $cashier);
        $orderService->queueInventorySyncToWebhooks($store, $order);

        return response()->json([
            'order_id'       => $order->id,
            'receipt_number' => $order->receipt_number,
            'receipt_path'   => $order->receipt_path,
            'total_amount'   => $order->total_amount,
            'delivery_fee'   => $order->delivery_fee,
            'customer_type'  => $order->customer_type?->value,
            'company_name'   => $order->company_name,
            'fulfillment_status' => $order->fulfillment_status?->value,
            'is_delivery'    => $order->status === 'pending_delivery',
        ], 201);
    }
}