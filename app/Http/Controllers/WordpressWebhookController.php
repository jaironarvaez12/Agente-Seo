<?php

namespace App\Http\Controllers;

use App\Models\DominiosModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WordpressWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        // El plugin manda _site_key y _site (y en debug también te muestra site_key)
        $siteKey = (string) (data_get($payload, '_site_key') ?? data_get($payload, 'site_key') ?? '');
        $site    = rtrim((string) (data_get($payload, '_site') ?? data_get($payload, 'site') ?? ''), '/');

        if ($siteKey === '' && $site !== '') {
            $siteKey = md5($site);
        }

        if ($siteKey === '') {
            Log::warning('LWS webhook: missing site_key', ['keys' => array_keys($payload)]);
            return response()->json(['ok' => false, 'message' => 'missing_site_key'], 422);
        }

        // Guarda wp_site_key en DB (MULTI DOMINIO)
        if ($site !== '') {
            $dom = DominiosModel::query()
                ->whereRaw("TRIM(TRAILING '/' FROM url) = ?", [$site])
                ->first();

            if ($dom && empty($dom->wp_site_key)) {
                $dom->wp_site_key = $siteKey;
                $dom->save();
            }
        }

        $type = (string) (data_get($payload, 'type') ?? '');

        // Ping
        if ($type === 'ping') {
            return response()->json(['ok' => true]);
        }

        // INVENTORY
        if ($type === 'inventory_batch') {
            $postType = (string) (data_get($payload, 'post_type') ?? '');
            if (!in_array($postType, ['post', 'page'], true)) {
                return response()->json(['ok' => false, 'message' => 'bad_post_type'], 422);
            }

            $items   = data_get($payload, 'items');
            $items   = is_array($items) ? $items : [];
            $runId   = (string) (data_get($payload, 'run_id') ?? '');
            $page    = (int) (data_get($payload, 'page') ?? 1);
            $perPage = (int) (data_get($payload, 'per_page') ?? 20);

            $kInv   = "inv:{$siteKey}:{$postType}";
            $kMeta  = "inv_meta:{$siteKey}:{$postType}";
            $kCount = "inv_counts:{$siteKey}:{$postType}";

            // si page=1, reinicia el snapshot (nuevo run)
            if ($page <= 1) {
                Cache::put($kInv, [], now()->addHours(6));
            }

            $current = Cache::get($kInv, []);
            $current = is_array($current) ? $current : [];

            // merge por wp_id para no duplicar
            $map = [];
            foreach ($current as $it) {
                if (is_array($it) && isset($it['wp_id'])) $map[(int)$it['wp_id']] = $it;
            }
            foreach ($items as $it) {
                if (is_array($it) && isset($it['wp_id'])) $map[(int)$it['wp_id']] = $it;
            }

            $merged = array_values($map);
            Cache::put($kInv, $merged, now()->addHours(6));

            // counts por status
            $counts = ['publish'=>0,'draft'=>0,'future'=>0,'pending'=>0,'private'=>0];
            foreach ($merged as $it) {
                if (!is_array($it)) continue;
                $st = (string)($it['status'] ?? '');
                if (isset($counts[$st])) $counts[$st]++;
            }
            Cache::put($kCount, $counts, now()->addHours(6));

            // meta
            $isComplete = (count($items) < $perPage);
            Cache::put($kMeta, [
                'run_id'      => $runId,
                'updated_at'  => now()->toIso8601String(),
                'last_page'   => $page,
                'is_complete' => $isComplete,
            ], now()->addHours(6));

            Log::info('LWS inventory saved', [
                'site' => $site,
                'site_key' => $siteKey,
                'post_type' => $postType,
                'page' => $page,
                'items' => count($items),
                'total_snapshot' => count($merged),
            ]);

            return response()->json(['ok' => true]);
        }

        // upsert/status/delete: si no lo necesitas para listar, lo ignoras
        return response()->json(['ok' => true]);
    }
}
