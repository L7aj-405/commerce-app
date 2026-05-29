# OpenWolf

@.wolf/OPENWOLF.md

This project uses OpenWolf for context management. Read and follow .wolf/OPENWOLF.md every session. Check .wolf/cerebrum.md before generating code. Check .wolf/anatomy.md before reading files.


# Commerce SaaS — Claude Code Context

## Stack
- Laravel 13, PHP 8.3+
- Blade + Livewire 3 (Volt single-file components)
- Fortify (auth) + Flux UI components
- MySQL
- Tailwind CSS + Alpine.js
- Pest for testing

## Architecture
- User → has many Stores
- Store → has many Orders (from WooCommerce/Shopify/YouCan)
- Store → has many PlatformConnections
- PlatformConnection → has many Orders
- Store → has one StoreCredential (encrypted secrets)
- Multi-tenancy by store ownership (users only see their own stores/orders)

## Auth Stack
- Uses Laravel Fortify (NOT Breeze)
- Registration: app/Actions/Fortify/CreateNewUser.php
- 2FA: Fortify features enabled
- Login: Volt component at resources/views/livewire/pages/auth/login.blade.php
- Profile: Custom Livewire components in app/Livewire/Profile/

## Conventions
- ULID primary keys everywhere (HasUlids trait)
- Pest for all tests (no PHPUnit)
- Livewire for all interactive UI
- Volt (⚡) for auth pages
- Services in app/Services/
- Enums in app/Enums/
- Always declare(strict_types=1)
- JSON columns cast to array in models
- Encrypted cast for sensitive data (API tokens, passwords)
- updateOrCreate() to prevent duplicates (not create())

## Folder Structure
- app/Models/ → User, Store, Order, PlatformConnection, StoreCredential, SyncLog
- app/Services/ → SyncService, WhatsAppService, ConnectorFactory
- app/Services/Connectors/ → WooCommerceConnector, ShopifyConnector, YouCanConnector
- app/Http/Controllers/Api/ → WhatsAppWebhookController (for Meta webhooks)
- app/Enums/ → UserStatus, StoreType, StoreStatus, StoreRole, OrderStatus
- app/Jobs/ → SyncPlatformOrders, TriggerN8nWebhook (use $platformConnection not $connection)
- app/Livewire/Stores/ → StoreIndex, CreateStore, EditStore
- app/Livewire/Stores/Connections/ → ConnectionIndex, ConnectPlatform, EditConnection
- app/Livewire/Orders/ → OrderIndex, OrderDetails
- app/Events/ → OrderCreated
- app/Listeners/ → SendWhatsAppConfirmation
- resources/views/livewire/ → Livewire component views
- database/migrations/ → Schema
- routes/web.php → Blade routes
- routes/api.php → API routes (WhatsApp webhooks, n8n callbacks)
- tests/Feature/ → Feature tests
- tests/Unit/ → Unit tests

## Current Status
- ✅ Phase 1: User auth system (complete)
- ✅ Phase 2: Stores + WooCommerce/Shopify/YouCan integrations (complete)
- 🔄 Phase 3: Orders + WhatsApp automation (in progress)

## What Exists
✅ User model (ULID, status enum, soft deletes, 2FA schema)
✅ Store model (online/physical/hybrid types)
✅ StoreCredential model (encrypted WhatsApp/SMTP credentials)
✅ PlatformConnection model (WooCommerce/Shopify/YouCan)
✅ WooCommerce/Shopify/YouCan connectors
✅ SyncPlatformOrders job
✅ Store CRUD (Livewire: StoreIndex, CreateStore, EditStore)
✅ Connection wizard (ConnectPlatform with 3-step flow)
✅ 5 Enums (UserStatus, StoreType, StoreStatus, StoreRole, OrderStatus)

## What's Being Built (Phase 3)
🔄 Order model + OrderStatus enum
🔄 Orders dashboard (OrderIndex, OrderDetails)
🔄 WhatsApp automation (send confirmation on new order)
🔄 WhatsApp webhook (receive customer YES/NO replies)
🔄 Update SyncService to save orders to DB

## Important Notes
- Property naming: Jobs use `$platformConnection` NOT `$connection` (Queueable trait conflict)
- Duplicate prevention: Always use updateOrCreate() for platform_connections and orders
- Authorization: Users can only access their own stores/orders (use policies)
- Queue workers: Run `php artisan queue:work` to process sync jobs
- Webhook testing: Use ngrok to expose localhost for Meta webhooks

## Common Patterns
```php
// Creating models with ULID
use HasUlids, SoftDeletes;
$keyType = 'string';

// Enums
use App\Enums\OrderStatus;
'status' => OrderStatus::Pending,

// Encrypted fields
'casts' => ['access_token' => 'encrypted']

// JSON fields
'casts' => ['settings' => 'array']

// Relationships
public function orders(): HasMany
{
    return $this->hasMany(Order::class);
}

// Scopes
public function scopePending($query)
{
    return $query->where('status', OrderStatus::Pending);
}

// Livewire validation
#[Validate('required|string|max:255')]
public string $name = '';
```

## Testing Commands
```bash
# Run all tests
php artisan test

# Run specific test
php artisan test --filter OrderTest

# Run queue worker
php artisan queue:work

# Migrate database
php artisan migrate

# Seed data
php artisan db:seed

# Tinker
php artisan tinker
```