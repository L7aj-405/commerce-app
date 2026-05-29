<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\Order;

class MessageTemplates
{
    public static function all(): array
    {
        return [
            'standard' => [
                'name'        => 'Standard',
                'description' => 'Order details with item list and total — the most complete option.',
                'body'        => <<<'MSG'
Hi {customer_name},

Your order #{order_number} has been received and is pending your confirmation.

{items}

Total: {total} {currency}

Please confirm so we can proceed with your order.
MSG,
                'buttons' => [
                    ['id' => 'confirm', 'title' => 'Confirm Order'],
                    ['id' => 'cancel',  'title' => 'Cancel Order'],
                ],
            ],

            'simple' => [
                'name'        => 'Simple',
                'description' => 'Short message with just the essentials — best for high-volume stores.',
                'body'        => <<<'MSG'
Hi {customer_name},

Order #{order_number} — {total} {currency}

Please confirm to proceed.
MSG,
                'buttons' => [
                    ['id' => 'confirm', 'title' => 'Confirm'],
                    ['id' => 'cancel',  'title' => 'Cancel'],
                ],
            ],

            'formal' => [
                'name'        => 'Formal',
                'description' => 'Professional tone with greeting and sign-off — suits premium brands.',
                'body'        => <<<'MSG'
Dear {customer_name},

Thank you for your order with us.

Order reference: #{order_number}

{items}

Order total: {total} {currency}

Please confirm your order to allow us to begin processing.

Best regards,
{store_name}
MSG,
                'buttons' => [
                    ['id' => 'confirm', 'title' => 'Yes, confirm'],
                    ['id' => 'cancel',  'title' => 'No, cancel'],
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function render(string $templateKey, Order $order): string
    {
        $template = self::get($templateKey);

        if ($template === null) {
            return "Order #{$order->order_number} — Total: {$order->total} {$order->currency}";
        }

        $itemLines = collect($order->items ?? [])->map(function (array $item): string {
            $name  = $item['name']     ?? 'Item';
            $qty   = $item['quantity'] ?? 1;
            $price = $item['price']    ?? ($item['total'] ?? 0);

            return "• {$name} × {$qty}  —  {$price}";
        })->join("\n");

        $storeName = $order->store?->name ?? 'Our Store';

        return strtr($template['body'], [
            '{customer_name}' => $order->customer_name ?? 'Valued Customer',
            '{order_number}'  => $order->order_number  ?? 'N/A',
            '{items}'         => $itemLines ?: '• (no items)',
            '{total}'         => number_format((float) ($order->total ?? 0), 2),
            '{currency}'      => $order->currency ?? '',
            '{store_name}'    => $storeName,
        ]);
    }

    public static function renderPreview(string $templateKey): string
    {
        $template = self::get($templateKey);

        if ($template === null) {
            return '';
        }

        $sampleItems = "• Blue T-Shirt × 2  —  19.99\n• Black Jeans × 1  —  49.99";

        return strtr($template['body'], [
            '{customer_name}' => 'Ahmed',
            '{order_number}'  => '10042',
            '{items}'         => $sampleItems,
            '{total}'         => '89.97',
            '{currency}'      => 'MAD',
            '{store_name}'    => 'Your Store',
        ]);
    }
}
