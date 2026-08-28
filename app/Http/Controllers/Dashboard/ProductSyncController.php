<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\ProductSyncJob;
use App\Models\PlatformConnection;
use App\Models\ProductSyncBatch;
use App\Models\ProductSyncResult;
use App\Models\SyncLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSyncController extends Controller
{
    /**
     * كيجيب الـ Connections المتاحة بحال لي كان ف دالة render()
     */
    public function getConnections(Request $request): JsonResponse
    {
        $store = $request->user()->getActiveStore();
        if (!$store) {
            return response()->json([], 422);
        }

        $connections = $store->connections()
            ->where('status', 'active')
            ->get()
            ->map(function (PlatformConnection $conn) use ($store): array {
                $lastSync = SyncLog::where('platform_connection_id', $conn->id)
                    ->where('direction', 'pull')
                    ->whereNotNull('completed_at')
                    ->latest('completed_at')
                    ->first()
                    ?->completed_at;

                return [
                    'id'            => $conn->id,
                    'platform'      => $conn->platform,
                    'label'         => $conn->label ?: ucfirst($conn->platform),
                    'product_count' => $conn->productListings()->count(),
                    'last_sync'     => $lastSync instanceof Carbon ? $lastSync->diffForHumans() : null,
                ];
            });

        return response()->json($connections);
    }

    /**
     * Queues a background import from each selected platform connection and
     * returns immediately — never blocks the request on the platform's API.
     * One ProductSyncJob per connection, scoped to this store only.
     */
    public function startSync(Request $request): JsonResponse
    {
        $store = $request->user()->getActiveStore();
        if (!$store) {
            return response()->json(['error' => 'No active store.'], 422);
        }

        $validated = $request->validate([
            'connection_ids' => ['required', 'array', 'min:1'],
            'connection_ids.*' => ['string'],
        ]);

        $connections = PlatformConnection::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $validated['connection_ids'])
            ->where('status', 'active')
            ->get();

        if ($connections->isEmpty()) {
            return response()->json(['error' => 'No matching active connection for this store.'], 422);
        }

        $batch = ProductSyncBatch::create([
            'store_id' => $store->id,
            'organization_id' => $store->organization_id,
            'user_id' => $request->user()->id,
            'status' => ProductSyncBatch::STATUS_PENDING,
            'total_count' => $connections->count(),
            'payload' => ['connection_ids' => $connections->pluck('id')->all()],
        ]);

        foreach ($connections as $connection) {
            $result = ProductSyncResult::create([
                'batch_id' => $batch->id,
                'store_id' => $store->id,
                'platform_connection_id' => $connection->id,
                'platform' => $connection->platform,
                'status' => ProductSyncResult::STATUS_QUEUED,
            ]);

            ProductSyncJob::dispatch($result->id);
        }

        return response()->json([
            'status' => 'queued',
            'batch_id' => $batch->id,
        ]);
    }

    /** Poll the outcome of a queued sync batch — scoped to the acting user's active store. */
    public function getSyncBatchStatus(Request $request, string $batch): JsonResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $model = ProductSyncBatch::query()
            ->where('store_id', $store->id)
            ->with('results.connection:id,platform,label')
            ->find($batch);

        abort_if($model === null, 404);

        return response()->json([
            'batch_id' => $model->id,
            'status' => $model->status,
            'total_count' => $model->total_count,
            'succeeded_count' => $model->succeeded_count,
            'failed_count' => $model->failed_count,
            'skipped_count' => $model->skipped_count,
            'results' => $model->results->map(fn (ProductSyncResult $r) => [
                'connection_id' => $r->platform_connection_id,
                'platform' => $r->platform,
                'label' => $r->connection?->label ?: ucfirst($r->platform),
                'status' => $r->status,
                'created' => $r->created_count,
                'updated' => $r->updated_count,
                'failed' => $r->failed_item_count,
                'message' => $r->message,
            ]),
        ]);
    }
}
