# Order Lifecycle & Status Workflow — Architecture

Design for the full pipeline: **Sync → Confirmation → Fulfillment → Delivery → Returns → Inspection → Restock / Damaged stock**.

This extends what already exists rather than introducing a parallel system:

| Already built | Where |
|---|---|
| Canonical workflow enum | `app/Enums/FulfillmentStatus.php` |
| Validated transitions endpoint | `OrderController@updateStatus` (`POST /dashboard/orders/{type}/{id}/status`) |
| Channel-agnostic row shape | `app/Support/OrderPresenter.php` |
| Kanban + table + drawer UI | `resources/js/Pages/Dashboard/Orders/Manage.jsx` |
| Stock mutation + audit | `Stock`, `StockLedger`, `InventoryAdjustment`, `OrderProcessingService::adjustInventory()` |
| RBAC | `App\Support\PermissionCatalog` + `perm:` middleware + `Gate::before` bridge |

**Guiding rule: `FulfillmentStatus` stays the single source of truth.** Do not add a second enum. The controller validates against `transitions()` and the React board renders buttons from the same list, so any new state automatically flows to both.

---

## 1. State machine

### 1.1 States

Existing values are preserved (rows already carry them); three states are re-labelled and four are new.

| Case | Value | Label | Phase | New? |
|---|---|---|---|---|
| `Pending` | `pending` | Pending confirmation | confirmation | relabel |
| `Confirmed` | `confirmed` | Confirmed | fulfillment | **new** |
| `InProgress` | `in_progress` | Processing | fulfillment | relabel |
| `ReadyForDelivery` | `ready_for_delivery` | Ready for delivery | fulfillment | — |
| `Delivered` | `delivered` | Delivered | fulfillment | **new** |
| `Completed` | `completed` | Completed | closed | — |
| `Cancelled` | `cancelled` | Cancelled | closed | — |
| `Returned` | `returned` | Returned — awaiting inspection | returns | **new** |
| `UnderInspection` | `under_inspection` | Under inspection | returns | **new** |
| `ReturnCompleted` | `return_completed` | Return closed | returns | **new** |

### 1.2 Transition graph

```
                            ┌──────────── Cancelled (terminal)
                            │
  [sync / POS delivery]     │
          │                 │
          ▼                 │
      Pending ──────────────┤
   (confirmation queue)     │
          │ confirm         │
          ▼                 │
      Confirmed ────────────┤   ← stock is committed HERE for online orders
          │ start           │
          ▼                 │
     InProgress ────────────┤
          │ pack done       │
          ▼                 │
  ReadyForDelivery ─────────┘
          │ handed over          │ refused / failed delivery
          ▼                      ▼
      Delivered ───────────► Returned ◄──── Completed  (post-sale return window)
          │ close                 │              ▲
          ▼                       │ receive      │
      Completed ──────────────────┘              │
     (terminal-ish) ───────────────────────────── ┘
                                  │
                                  ▼
                          UnderInspection
                                  │ all lines dispositioned
                                  ▼
                          ReturnCompleted (terminal)
```

```php
// app/Enums/FulfillmentStatus.php
public function transitions(): array
{
    return match ($this) {
        self::Pending          => [self::Confirmed, self::Cancelled],
        self::Confirmed        => [self::InProgress, self::Cancelled],
        self::InProgress       => [self::ReadyForDelivery, self::Cancelled],
        self::ReadyForDelivery => [self::Delivered, self::Returned, self::Cancelled],
        self::Delivered        => [self::Completed, self::Returned],
        self::Completed        => [self::Returned],          // return window
        self::Returned         => [self::UnderInspection],
        self::UnderInspection  => [self::ReturnCompleted],
        self::ReturnCompleted,
        self::Cancelled        => [],
    };
}
```

Supporting methods to add alongside `label()` / `actionLabel()` / `isTerminal()`:

```php
public function phase(): string      // 'confirmation' | 'fulfillment' | 'closed' | 'returns'
public function isReturnFlow(): bool // returned | under_inspection | return_completed
public function requiresReason(): bool  // cancelled | returned  → reason is mandatory
public function permission(): string    // 'orders.confirm' | 'orders.manage' | 'orders.inspect'
```

`Cancelled` is reachable only *before* dispatch. Once the goods are with the customer the correct exit is `Returned`, not `Cancelled` — that is what keeps the reverse-logistics stock accounting honest.

### 1.3 Enum answers legality; the service answers preconditions

`canTransitionTo()` only says "is this edge on the graph". Two moves have additional guards that must live in the service, not the enum:

- `UnderInspection → ReturnCompleted` — allowed only when **every** `order_return_items` row for the open return has a `condition` set and has been dispositioned.
- `* → Returned` — requires a non-empty `reason` and creates the `order_returns` header in the same transaction.

Never encode those in `transitions()`; the presenter would then hide a button that should be visible-but-disabled.

---

## 2. Schema changes

### 2.1 ⚠️ `fulfillment_status` is a native MySQL `ENUM` — fix this first

`2026_07_22_120000_add_fulfillment_status_to_orders.php` created the column with `$table->enum(...)` over five values. Writing `'confirmed'` to it produces **MySQL error 1265 "Data truncated"** — the exact failure already recorded for `sync_logs.action` in the Do-Not-Repeat log, and SQLite (test DB) will not reproduce it.

Convert to a plain string and let the PHP enum cast do the validating, matching the newer convention (`pos_orders.customer_type` is `string` + enum cast):

```php
// 2026_07_24_130000_widen_fulfillment_status_columns.php
public function up(): void
{
    foreach (['orders', 'pos_orders'] as $table) {
        DB::statement("ALTER TABLE `{$table}` MODIFY `fulfillment_status` VARCHAR(32) NOT NULL DEFAULT '"
            . ($table === 'orders' ? 'pending' : 'completed') . "'");
    }
}
```

Raw `ALTER` rather than `->change()` avoids doctrine/dbal enum introspection issues. Add an index while here: `orders(store_id, fulfillment_status)` and `pos_orders(store_id, fulfillment_status)` — the board queries both.

### 2.2 Return tables (the critical piece)

**A return is item-level, not order-level.** A 3-line order can come back with 2 lines resellable and 1 damaged. An order-level status can never express that, so the disposition data needs its own tables and the order status becomes a *rollup* of them.

```php
// 2026_07_24_130001_create_order_returns_tables.php
Schema::create('order_returns', function (Blueprint $table) {
    $table->ulid('id')->primary();
    $table->ulid('store_id');
    $table->nullableUlidMorphs('returnable');        // Order | PosOrder
    $table->string('reference');                     // RET-YYYYMMDD-0001, per store
    $table->string('status', 32)->default('awaiting_inspection'); // awaiting_inspection|inspecting|closed
    $table->string('reason', 64);                    // refused|damaged_in_transit|wrong_item|customer_remorse|other
    $table->text('notes')->nullable();
    $table->char('flagged_by', 26)->nullable();      // users.id
    $table->char('inspected_by', 26)->nullable();
    $table->timestamp('flagged_at');
    $table->timestamp('inspected_at')->nullable();
    $table->timestamp('closed_at')->nullable();
    $table->timestamps();

    $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
    $table->unique(['store_id', 'reference']);       // per-store sequence, cf. invoice_number
    $table->index(['store_id', 'status']);
});

Schema::create('order_return_items', function (Blueprint $table) {
    $table->ulid('id')->primary();
    $table->ulid('order_return_id');
    $table->ulid('product_id')->nullable();          // nullable: online items are JSON, may not map
    $table->ulid('variant_id')->nullable();
    $table->string('product_name');                  // snapshot, same convention as pos_order_items
    $table->string('product_sku')->nullable();
    $table->integer('quantity_ordered');
    $table->integer('quantity_returned');
    $table->string('condition', 24)->nullable();     // resellable|damaged|missing  (null = not yet inspected)
    $table->ulid('destination_warehouse_id')->nullable();
    $table->ulid('stock_ledger_id')->nullable();     // set once stock has moved → idempotency guard
    $table->decimal('refund_amount', 12, 2)->default(0);
    $table->text('inspection_notes')->nullable();
    $table->timestamp('dispositioned_at')->nullable();
    $table->timestamps();

    $table->foreign('order_return_id')->references('id')->on('order_returns')->cascadeOnDelete();
    $table->index(['order_return_id', 'condition']);
});
```

`stock_ledger_id` is the idempotency guard: the disposition service skips any line that already has one, so a double-submitted inspection form can never restock twice.

### 2.3 Damaged stock location

`warehouses` has no `type` column. Two options were considered:

| Option | Verdict |
|---|---|
| **A.** `warehouses.type` enum `standard\|damaged\|quarantine` | **Chosen.** Damaged stock is then ordinary `Stock` in a different warehouse — the `(product_id, variant_id, warehouse_id)` key, `StockLedger`, transfers, and the stock dashboard all work unchanged. |
| **B.** `stocks.condition` column | Rejected. Breaks the existing unique index, and every stock query in the app would need a `where('condition','good')` added — a silent-wrong-answer footgun. |

```php
// 2026_07_24_130002_add_type_to_warehouses.php
$table->string('type', 24)->default('standard')->after('name');
$table->index(['type', 'is_active']);
```

Then, mirroring `Store::getPrimaryWarehouse()`:

```php
public function getDamagedWarehouse(): Warehouse
{
    $existing = $this->warehouses()->where('type', 'damaged')->first();
    if ($existing) return $existing;

    $warehouse = Warehouse::create([
        'user_id' => $this->user_id,
        'name'    => "{$this->name} — Damaged stock",
        'type'    => 'damaged',
        'is_active' => true,
    ]);
    $this->warehouses()->attach($warehouse->id, ['is_primary' => false]);

    return $warehouse;
}
```

#### ⚠️ Two places that must now exclude damaged stock

Adding a warehouse silently inflates every "total stock" figure unless these are scoped:

1. **Product listing totals.** `Product::withSum('stocks as total_stock', 'quantity')` sums *all* warehouses. It must become:
   ```php
   ->withSum(['stocks as total_stock' => fn ($q) => $q->whereHas(
       'warehouse', fn ($w) => $w->where('type', 'standard')
   )], 'quantity')
   ->withSum(['stocks as damaged_stock' => fn ($q) => $q->whereHas(
       'warehouse', fn ($w) => $w->where('type', 'damaged')
   )], 'quantity')
   ```
   Surfacing `damaged_stock` as its own column in the stock dashboard is the payoff for choosing option A.

2. **Platform sync.** `SyncInventoryToWebhooks` pushes `new_stock` to WooCommerce/Shopify/YouCan. That number must be **sellable-only**, or damaged units get offered for sale on the storefront. Compute from standard-type warehouses exclusively.

---

## 3. Stock transition matrix

This is the heart of the design. **Stock never moves when an order is *flagged* as returned — only when a line is *dispositioned* during inspection.** The goods are unverified until an inspector physically handles them; restocking on the flag is how phantom inventory gets created.

| Transition | Stock effect | Warehouse | `stock_ledger.type` |
|---|---|---|---|
| platform sync → `Pending` | none | — | — |
| `Pending → Confirmed` | **−qty** (commit) | primary | `sale` |
| `Pending → Cancelled` | none (never deducted) | — | — |
| `Confirmed → Cancelled` | **+qty** (release) | primary | `adjustment` |
| `InProgress → Cancelled` | **+qty** (release) | primary | `adjustment` |
| `Confirmed → InProgress → ReadyForDelivery → Delivered → Completed` | none | — | — |
| any → `Returned` | **none** — goods unverified | — | — |
| `Returned → UnderInspection` | none | — | — |
| inspect line: **resellable** | **+qty** | primary (`standard`) | `return` |
| inspect line: **damaged** | **+qty** | damaged | `damage` |
| inspect line: **missing** | none (write-off) | — | `adjustment`, qty 0 |
| all lines done → `ReturnCompleted` | none | — | — |

### POS orders differ at one point only

POS sales already deduct at checkout (`OrderProcessingService::adjustInventory()`, `source='pos_sale'`) for both instant and delivery fulfillment — the cerebrum records this as deliberate. So for a POS order the `Pending → Confirmed` row above is a **no-op**; the deduction already happened. Everything from `Returned` onward is identical for both channels.

Implement this as a guard, not a branch scattered through the service:

```php
private function shouldCommitStock(Model $order): bool
{
    return $order instanceof Order;   // POS committed at checkout
}
```

### Ledger vs. adjustments — write both, they answer different questions

- `stock_ledger` — the human-facing audit trail (`type`, `stock_before/after`, `reference_number`, `user_id`, polymorphic `source`). The `return` and `damage` types already exist in its enum and have never been used; this feature is what they were created for.
- `inventory_adjustments` — the outbound sync queue consumed by `SyncInventoryToWebhooks`. Every stock movement that should reach the storefront needs a row here with `sync_status='pending'`.

Restock-to-**primary** writes both (the storefront must learn the unit is sellable again). Restock-to-**damaged** writes only the ledger — pushing it would tell the platform the item is available.

---

## 4. Service layer

Controllers stay thin, matching the `InvoiceService` precedent.

```
app/Services/Orders/
├── OrderWorkflowService.php    # every status transition, all stock side-effects
└── ReturnInspectionService.php # per-line dispositions, restock/damage routing
```

### `OrderWorkflowService::transition()`

```php
public function transition(
    Order|PosOrder $order,
    FulfillmentStatus $target,
    User $actor,
    ?string $reason = null,
): Order|PosOrder {
    return DB::transaction(function () use ($order, $target, $actor, $reason) {
        // Re-read under a row lock: two staff on the board can click at once.
        $order = $order->newQuery()->lockForUpdate()->findOrFail($order->id);
        $current = $order->fulfillment_status ?? FulfillmentStatus::Pending;

        if (! $current->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => "Cannot move a {$current->label()} order to {$target->label()}.",
            ]);
        }
        if ($target->requiresReason() && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        $this->applyStockEffects($order, $current, $target, $actor);   // §3 matrix
        $this->syncLegacyStatus($order, $target);                      // §4.1

        $order->update([
            'fulfillment_status'     => $target,
            'fulfillment_updated_at' => now(),
        ]);

        if ($target === FulfillmentStatus::Returned) {
            $this->returns->open($order, $reason, $actor);   // creates order_returns + items
        }

        activity('order')->performedOn($order)->causedBy($actor)
            ->event('fulfillment_transition')
            ->withProperties(['from' => $current->value, 'to' => $target->value, 'reason' => $reason])
            ->log("Order moved to {$target->label()}");

        return $order->refresh();
    });
}
```

`lockForUpdate()` is not optional here — without it two confirmation agents clicking "Confirm" simultaneously both pass the transition check and stock is deducted twice.

### 4.1 Reconciling `Order.status` with `Order.fulfillment_status`

`Order` carries **two** status columns and existing code depends on the old one — `scopePending()`, `scopeNeedsWhatsappConfirmation()`, `markAsConfirmed()`, and the whole WhatsApp confirmation pipeline all read `status` (`OrderStatus`). Deleting or ignoring it breaks them.

Definition to adopt:

- **`status` (`OrderStatus`)** — the *commercial* state, mirrored to the platform and driven by the customer (incl. the WhatsApp YES/NO reply).
- **`fulfillment_status` (`FulfillmentStatus`)** — the *internal operational* state, driven by staff.

`syncLegacyStatus()` keeps `status` a projection of the workflow so both stay coherent:

| `fulfillment_status` | mirrored `status` |
|---|---|
| `pending` | `pending` |
| `confirmed`, `in_progress`, `ready_for_delivery`, `delivered` | `confirmed` |
| `completed` | `completed` |
| `cancelled` | `cancelled` |
| `returned`, `under_inspection`, `return_completed` | `completed` (the sale happened; the return is separate) |

**Go the other way too.** `Order::markAsConfirmed()` is what the WhatsApp webhook calls when a customer replies YES — it currently only sets `status`. Route it through the workflow service so an auto-confirmation lands the order in the fulfillment lane exactly like a manual confirmation, with the actor recorded as the system:

```php
public function markAsConfirmed(): void
{
    app(OrderWorkflowService::class)->transition($this, FulfillmentStatus::Confirmed, actor: null, reason: 'whatsapp_reply');
    $this->update(['whatsapp_confirmed_at' => now()]);
}
```

This is the single highest-value integration point in the design: the confirmation department queue automatically drains itself for every customer who answers on WhatsApp, and staff only handle the non-responders.

### `ReturnInspectionService`

```php
public function open(Order|PosOrder $order, string $reason, ?User $actor): OrderReturn;

/** @param array<int, array{item_id:string, quantity:int, condition:string, notes:?string}> $lines */
public function disposition(OrderReturn $return, array $lines, User $actor): OrderReturn;
```

`disposition()` in one transaction, per line:

1. Skip if `stock_ledger_id` is already set (idempotency).
2. Resolve destination — `resellable` → `store->getPrimaryWarehouse()`, `damaged` → `store->getDamagedWarehouse()`, `missing` → none.
3. `Stock::firstOrCreate([product_id, variant_id, warehouse_id], ['quantity' => 0])`, `lockForUpdate()`, `+= quantity`.
4. Write `StockLedger` (`return` / `damage` / `adjustment`), store its id back onto the line.
5. `resellable` only → write `InventoryAdjustment` (`source='return_restock'`, `sync_status='pending'`) and dispatch `SyncInventoryToWebhooks`.
6. Stamp `condition`, `destination_warehouse_id`, `dispositioned_at`.

Then, if every line has a `condition`: close the return and transition the order to `ReturnCompleted`.

### Refunds / credit notes

A closed return with a non-zero refund total should produce a **credit note**, not edit the original invoice — `Facture` is immutable once `locked_at` is set. Hook `ReturnInspectionService::close()` into `InvoiceService`: full return → `void($facture, $reason)`; partial → issue a credit `Facture` for the returned lines. Flagged as the natural next increment; it is not required for the stock pipeline to be correct.

---

## 5. Permissions & departments

A "department" is a **permission + a saved filter over `phase()`**, not a new entity. Add to `PermissionCatalog::groups()` under Orders:

| Key | Grants | Default roles |
|---|---|---|
| `orders.confirm` | `Pending → Confirmed / Cancelled` | Administrator, Manager, *Confirmation agent* |
| `orders.fulfil` | Confirmed → … → Delivered | Administrator, Manager, *Warehouse* |
| `orders.inspect` | Returns queue + disposition submission | Administrator, Manager, *Inspector* |
| `orders.return` | Flag a delivered order as returned | Administrator, Manager, support |

Three new system roles in `PermissionCatalog::defaultRoles()` — `Confirmation agent` (`orders.view`, `orders.confirm`), `Warehouse` (`orders.view`, `orders.fulfil`, `stock.view`), `Inspector` (`orders.view`, `orders.inspect`, `stock.view`, `stock.manage`).

Because `Gate::before` already bridges catalogue keys, enforcement is just `$this->authorize($target->permission())` inside the controller, and the React side hides lanes with the existing `can()` helper from `SaasLayout`.

---

## 6. UI structure

### 6.1 Extend `Orders/Manage.jsx` — don't build a second board

Ten statuses is too many Kanban columns. Add a **department tab strip** above the existing source tabs; each tab presets which columns render:

| Tab | Columns shown | Gated by |
|---|---|---|
| **All** | every non-terminal lane | `orders.view` |
| **Confirmation** | Pending confirmation | `orders.confirm` |
| **Fulfillment** | Confirmed · Processing · Ready for delivery · Delivered | `orders.fulfil` |
| **Returns** | Returned · Under inspection · Return closed | `orders.inspect` |
| **Closed** | Completed · Cancelled (table view only) | `orders.view` |

```jsx
const COLUMNS = [
    { value: 'pending',            label: 'Pending confirmation', phase: 'confirmation', tone: 'amber'  },
    { value: 'confirmed',          label: 'Confirmed',            phase: 'fulfillment',  tone: 'blue'   },
    { value: 'in_progress',        label: 'Processing',           phase: 'fulfillment',  tone: 'indigo' },
    { value: 'ready_for_delivery', label: 'Ready for delivery',   phase: 'fulfillment',  tone: 'violet' },
    { value: 'delivered',          label: 'Delivered',            phase: 'fulfillment',  tone: 'teal'   },
    { value: 'completed',          label: 'Completed',            phase: 'closed',       tone: 'green'  },
    { value: 'cancelled',          label: 'Cancelled',            phase: 'closed',       tone: 'red'    },
    { value: 'returned',           label: 'Returned',             phase: 'returns',      tone: 'orange' },
    { value: 'under_inspection',   label: 'Under inspection',     phase: 'returns',      tone: 'amber'  },
    { value: 'return_completed',   label: 'Return closed',        phase: 'returns',      tone: 'slate'  },
];
```

Everything else on that page keeps working as built: the drawer renders buttons from `order.transitions[]` (server-supplied, so illegal moves are unofferable), and `preserveState: true` + the `selectedKey` re-lookup keeps the drawer bound to fresh data after each update.

Two additions to the existing page:

- **Reason modal.** `Cancel` and `Mark returned` must collect a reason before posting — same pattern as the Amend/Void modals in `FacturesDetail.jsx`.
- **Drag-and-drop** (optional) must post the *same* `updateStatus` endpoint. A card dropped onto an illegal lane snaps back on the 422.

### 6.2 New page: `Orders/Returns/Inspect.jsx`

Inspection is per-line work and does not fit in the drawer. Route `GET /dashboard/orders/returns/{return}` (`perm:orders.inspect`), rendering a worksheet:

```
┌─────────────────────────────────────────────────────────────────────┐
│ RET-20260724-0003        Order #ORD-1182 · Reason: Refused          │
│ Flagged by Sara · 24 Jul, 14:02                    [ Under inspection ]│
├─────────────────────────────────────────────────────────────────────┤
│ Item              SKU      Ord  Ret   Condition          Destination │
│ Blue Hoodie L     BH-L-01   2    2   (•)Resell ( )Dmg   Main WH  ✓   │
│ Cotton Cap        CC-22     1    1   ( )Resell (•)Dmg   Damaged  ⚠   │
│ Leather Belt      LB-09     1    1   ( )Resell ( )Dmg  ( )Missing    │
│                                       └ note: ____________________   │
├─────────────────────────────────────────────────────────────────────┤
│ 2 of 3 lines inspected                                               │
│                     [ Save progress ]   [ Close return ] (disabled)  │
└─────────────────────────────────────────────────────────────────────┘
```

- Each line: quantity received (≤ quantity returned), a condition radio group, an auto-derived destination warehouse chip, an optional note.
- **Close return** stays disabled until every line has a condition — mirroring the service-side precondition from §1.3 rather than replacing it.
- On submit, a summary toast: *"Return closed — 2 units restocked to Main Warehouse, 1 unit moved to Damaged stock."*

Reuse the existing shared components: `DataTable`, `StatusBadge` (extend the `fulfillment` map with the four new values plus a `return` type), `EmptyState`, `SearchFilterBar`.

### 6.3 Presenter changes

`OrderPresenter` needs three fields so the UI can render returns without a second request:

```php
'phase'        => $status->phase(),
'return'       => $o->activeReturn?->only(['id', 'reference', 'status']),
'requires_reason' => array_map(fn ($t) => $t->requiresReason(), $status->transitions()),
```

---

## 7. Build order

1. ~~Migration: widen `fulfillment_status` to `varchar(32)` + indexes.~~ **Done.**
2. ~~Extend `FulfillmentStatus`.~~ **Done.**
3. ~~Migrations: `order_returns`, `order_return_items`, `warehouses.type`; `Store::getDamagedWarehouse()`.~~ **Done.**
4. ~~Scope damaged stock out of `total_stock` and out of `SyncInventoryToWebhooks`.~~ **Done.**
5. ~~`OrderWorkflowService` (+ `syncLegacyStatus`, + reroute `Order::markAsConfirmed`); refit `OrderController@updateStatus`.~~ **Done.**
6. ~~`ReturnInspectionService` + returns controller/routes.~~ **Done** — the two GET routes render React pages that arrive in step 8.
7. ~~Permissions + the three new default roles.~~ **Done.**
8. ~~`Manage.jsx` columns, department tabs, reason modal; `Returns/Index.jsx` + `Returns/Inspect.jsx`.~~ **Done.**
9. Pest tests — **43 written** across `OrderLifecycleFoundationTest` (13), `OrderWorkflowServiceTest` (17) and `OrderDepartmentsTest` (13). The checklist below is fully covered; what remains is optional (drag-and-drop, credit notes).

**The pipeline is functionally complete.** Remaining ideas, none blocking: drag-and-drop on the board (must post the same endpoint), credit notes on close (§4), and a reserved-vs-available stock model (§3).

### Deviations from this plan, and why

- **A second ENUM column had to be widened.** `inventory_adjustments.source` was also a native ENUM and rejected `online_order` / `return_restock`. Fixed in `2026_07_24_130003`. Same root cause as §2.1 — check any status/type/source column before adding a value to it.
- **`StockMovementWriter` was extracted** rather than leaving the stock logic inside the two services. Both the forward path and the return path need identical locking, ledger, and storefront-sync behaviour; one class means the "InventoryAdjustment only for sellable warehouses" rule is written once.
- **Illegal transitions surface as a toast, not a 422.** Inertia requests are not JSON, so a raw 422 would show the user nothing; the controllers catch `ValidationException` and flash `error`, which `SaasLayout` already renders. A JSON client still gets the 422.
- **`PosOrder.status` is only mirrored on cancellation.** It also encodes the delivery lane (`pending_delivery`) that `OrderPresenter` derives the board's source tab from, so rewriting it on the forward path would silently move orders between tabs.

## 8. Test checklist

- Every legal edge succeeds; a representative illegal edge 422s (`pending → delivered`).
- Confirming an **online** order deducts stock and writes a `sale` ledger row; confirming a **POS** order does not double-deduct.
- Cancelling *after* confirm releases stock; cancelling *before* confirm does not.
- Flagging `Returned` moves **zero** stock and opens an `order_returns` row with one item per line.
- `resellable` disposition lands in the primary warehouse with a `return` ledger row **and** a pending `InventoryAdjustment`.
- `damaged` disposition lands in the damaged warehouse with a `damage` ledger row and **no** `InventoryAdjustment`.
- Submitting the same inspection twice restocks once (`stock_ledger_id` guard).
- `ReturnCompleted` is rejected while any line has a null `condition`.
- `total_stock` on the product list excludes damaged units.
- A WhatsApp YES reply moves the order out of the confirmation queue.
- Cross-tenant: staff of store A cannot inspect a return belonging to store B (`TenantScope` + policy).
