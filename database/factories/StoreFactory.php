<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StoreStatus;
use App\Enums\StoreType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        $name = $this->faker->words(2, true) . ' Store';

        return [
            'user_id'        => User::factory(),
            'name'           => $name,
            'slug'           => Str::slug($name) . '-' . $this->faker->unique()->numerify('###'),
            'description'    => $this->faker->sentence(),
            'type'           => StoreType::Online,
            'status'         => StoreStatus::Active,
            'currency'       => 'MAD',
            'country'        => 'MA',
            'address'        => $this->faker->address(),
            'email'          => $this->faker->companyEmail(),
            'phone'          => $this->faker->phoneNumber(),
            'logo'           => null,
            'cover_image'    => null,
            'settings'       => [
                'language'       => 'ar',
                'timezone'       => 'Africa/Casablanca',
                'notifications'  => true,
                'order_prefix'   => 'ORD',
            ],
            'business_rules' => [
                'min_order_amount'    => 50,
                'free_shipping_above' => 500,
                'tax_rate'            => 0.20,
                'allow_cod'           => true,
            ],
            'metadata'       => [],
        ];
    }

    public function online(): static
    {
        return $this->state(['type' => StoreType::Online]);
    }

    public function physical(): static
    {
        return $this->state(['type' => StoreType::Physical]);
    }

    public function hybrid(): static
    {
        return $this->state(['type' => StoreType::Hybrid]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => StoreStatus::Inactive]);
    }
}
