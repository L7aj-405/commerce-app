<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\PlatformConnection;
use App\Models\SyncLog;
use App\Services\Sync\ProductSyncService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     * كيبدا المزامنة وكيدور على المنصات باستعمال نفس منطق الـ Queue ف الـ Cache
     */
    public function startSync(Request $request, ProductSyncService $syncService): JsonResponse
    {
        $store = $request->user()->getActiveStore();
        if (!$store) {
            return response()->json(['error' => 'No active store.'], 422);
        }

        $connectionIds = $request->input('connection_ids', []);
        if (empty($connectionIds)) {
            return response()->json(['error' => 'Select at least one platform.'], 422);
        }

        $cacheKey = "sync_store_{$store->id}";
        
        // جلب الإعدادات (إذا كنتي باغي تصيفطهم لـ الـ Service مستقبلاً)
        $options = $request->only(['sync_products', 'sync_variants', 'sync_stock']);

        // إعداد الـ State Machine داخل الكاش
        $syncState = [
            'in_progress' => true,
            'done' => false,
            'progress' => 5,
            'status' => 'Starting sync…',
            'queue' => array_values($connectionIds),
            'selected_ids' => array_values($connectionIds),
            'results' => [],
            'started_at' => microtime(true),
            'elapsed' => '0s'
        ];

        Cache::put($cacheKey, $syncState, now()->addMinutes(30));

        // تشغيل الـ Processing Loop (هنا غيدور على الـ Queue كامل)
        // وباش السيرفر ميموتش، تقدر تخليه يدور ف الباكيند حيت الـ Service ديجا سريعة ف دورة وحدة
        try {
            while (!empty($syncState['queue'])) {
                $connectionId = array_shift($syncState['queue']);
                $connection = PlatformConnection::find($connectionId);

                if (!$connection) {
                    continue;
                }

                $label = $connection->label ?: ucfirst($connection->platform);
                $syncState['status'] = "Syncing from {$label}…";
                
                // تحديث الـ Progress بار ف الكاش وسط المزامنة
                $total = count($syncState['selected_ids']);
                $processed = $total - count($syncState['queue']);
                $syncState['progress'] = (int) max(5, min(90, ($processed / $total) * 90));
                Cache::put($cacheKey, $syncState, now()->addMinutes(30));

                try {
                    // استدعاء السيرفيس ديالك القديمة بنفس الطريقة
                    $result = $syncService->syncFromPlatform($store, $connection->platform);

                    $syncState['results'][$connection->platform] = [
                        'label'   => $label,
                        'created' => $result['created'] ?? 0,
                        'updated' => $result['updated'] ?? 0,
                        'failed'  => $result['failed']  ?? 0,
                        'error'   => null,
                    ];
                } catch (Throwable $e) {
                    $syncState['results'][$connection->platform] = [
                        'label'   => $label,
                        'created' => 0,
                        'updated' => 0,
                        'failed'  => 0,
                        'error'   => $e->getMessage(),
                    ];
                }
            }

            // إنهاء المزامنة بنجاح نجاح
            $seconds = (int) round(microtime(true) - $syncState['started_at'], 0);
            $elapsedStr = $seconds < 60 ? "{$seconds}s" : intdiv($seconds, 60) . ":" . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);

            $syncState['in_progress'] = false;
            $syncState['done'] = true;
            $syncState['progress'] = 100;
            $syncState['status'] = 'Sync complete!';
            $syncState['elapsed'] = $elapsedStr;

            Cache::put($cacheKey, $syncState, now()->addMinutes(10));
            return response()->json(['success' => true]);

        } catch (Throwable $e) {
            Log::error('Global Product sync failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * هادي الـ API لي كيعيط ليها React كل ثانية باش يجيب التحديثات (Polling)
     */
    public function getSyncProgress(Request $request): JsonResponse
    {
        $store = $request->user()->getActiveStore();
        if (!$store) {
            return response()->json(['error' => 'No active store'], 422);
        }

        $state = Cache::get("sync_store_{$store->id}", [
            'in_progress' => false,
            'done' => false,
            'progress' => 0,
            'status' => 'No active sync.',
            'results' => [],
            'elapsed' => '0s'
        ]);

        return response()->json($state);
    }
}