<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $itemCount = $this->faker->numberBetween(1, 5);
        $items     = array_map(fn (int $i) => [
            'id'       => (string) ($i + 1),
            'name'     => $this->faker->words(3, true),
            'sku'      => strtoupper($this->faker->lexify('???-####')),
            'quantity' => $this->faker->numberBetween(1, 4),
            'price'    => $this->faker->randomFloat(2, 20, 800),
        ], range(0, $itemCount - 1));

        $total = (float) array_sum(array_map(
            fn (array $item) => $item['price'] * $item['quantity'],
            $items,
        ));

        return [
            'store_id'               => Store::factory(),
            'platform_connection_id' => null,
            'platform_order_id'      => (string) $this->faker->unique()->numberBetween(1000, 99999),
            'order_number'           => '#' . $this->faker->numberBetween(1000, 9999),
            'status'                 => OrderStatus::Pending,
            'total'                  => round($total, 2),
            'currency'               => 'MAD',
            'customer_name'          => $this->faker->name(),
            'customer_email'         => $this->faker->safeEmail(),
            'customer_phone'         => '+212' . $this->faker->numerify('6########'),
            'items'                  => $items,
            'platform_data'          => [],
            'synced_at'              => now(),
            'whatsapp_sent_at'       => null,
            'whatsapp_confirmed_at'  => null,
            'whatsapp_cancelled_at'  => null,
            'confirmation_method'    => null,
            'notes'                  => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => OrderStatus::Pending]);
    }

    public function confirmed(): static
    {
        return $this->state([
            'status'                => OrderStatus::Confirmed,
            'whatsapp_confirmed_at' => now(),
            'confirmation_method'   => 'whatsapp',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status'               => OrderStatus::Cancelled,
            'whatsapp_cancelled_at' => now(),
        ]);
    }
}
