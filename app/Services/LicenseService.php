<?php

namespace App\Services;

use App\Models\LicenciaDominiosActivacionModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class LicenseService
{
    public function validateCached(string $licenseKey, string $domain, ?string $email = null): array
    {
        $domain = $this->host($domain);
        $cacheKey = $this->validateCacheKey($licenseKey, $domain, $email);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $payload = [
            'license_key' => $licenseKey,
            'domain'      => $domain,
        ];

        if ($email) {
            $payload['email'] = $email;
        }

        $resp = $this->client()
            ->post('/api/licenses/validate', $payload)
            ->throw()
            ->json();

        $ttl = (int) data_get($resp, 'cache.recommended_ttl', 43200);
        $ttl = max(60, $ttl);

        Cache::put($cacheKey, $resp, now()->addSeconds($ttl));

        return $resp;
    }

    public function activate(string $licenseKey, string $domain, ?string $email = null): array
    {
        $domain = $this->host($domain);

        $payload = ['license_key' => $licenseKey, 'domain' => $domain];
        if ($email) $payload['email'] = $email;

        $resp = $this->client()
            ->post('/api/licenses/activate', $payload)
            ->throw()
            ->json();

        $this->forgetValidateCache($licenseKey, $domain);
        $this->forgetLimitsCache($licenseKey, $domain);

        return $resp;
    }

    public function deactivate(string $licenseKey, string $domain): array
    {
        $domain = $this->host($domain);

        $resp = $this->client()
            ->post('/api/licenses/deactivate', [
                'license_key' => $licenseKey,
                'domain' => $domain,
            ])
            ->throw()
            ->json();

        $this->forgetValidateCache($licenseKey, $domain);
        $this->forgetLimitsCache($licenseKey, $domain);

        return $resp;
    }

    /**
     * ⚠️ Evita usar esto para plan/limits.
     * Activar en validate genera "activaciones fantasma" si no registras en BD.
     */
    public function validateOrActivate(string $licenseKey, string $domain, ?string $email = null): array
    {
        // Si quieres dejarlo, mejor NO activar automáticamente:
        $domain = $this->host($domain);
        return $this->validateCached($licenseKey, $domain);
    }

    /**
     * ✅ Plan + limits SIN auto-activar.
     * Fuente de verdad = validate (si el server exige activación, te lo dirá).
     */
    public function getPlanLimitsCached(string $licenseKey, string $domain, ?string $email = null, bool $force = false): array
    {
        $domain = $this->host($domain);
        $cacheKey = "license:limits:" . sha1($licenseKey . '|' . $domain);

        if ($force) Cache::forget($cacheKey);

        if (!$force && ($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        // ✅ SOLO VALIDAR (NO activar aquí)
       $resp = $this->validateCached($licenseKey, $domain, $email);


        $payload = [
            'plan'   => (string) data_get($resp, 'plan', 'free'),
            'limits' => (array) data_get($resp, 'limits', []),
            'raw'    => $resp,
        ];

        $ttl = (int) data_get($resp, 'cache.recommended_ttl', 43200);
        $ttl = max(60, $ttl);

        Cache::put($cacheKey, $payload, now()->addSeconds($ttl));

        return $payload;
    }

    public function normalizeLimits(string $plan, array $limits): array
    {
        $fallback = (array) config("licenses.limits_by_plan.$plan", []);

        return [
            'max_activations' => (int) ($limits['max_activations'] ?? $fallback['max_activations'] ?? 0),

            'max_content' => array_key_exists('max_content', $limits)
                ? (int) $limits['max_content']
                : 0,

            'max_report' => array_key_exists('max_report', $limits)
                ? (int) $limits['max_report']
                : 0,
        ];
    }

    public function activarYRegistrar(int $userId, string $licenseKeyPlain, string $domain, ?string $email = null): array
    {
        $host = $this->host($domain);

        $resp = $this->activate($licenseKeyPlain, $host, $email);

        if (data_get($resp, 'activated') === true) {
            LicenciaDominiosActivacionModel::updateOrCreate(
                [
                    'license_key' => sha1($licenseKeyPlain),
                    'dominio' => $host,
                ],
                [
                    'user_id' => $userId,
                    'estatus' => 'activo',
                    'activo_at' => now(),
                    'desactivado_at' => null,
                ]
            );

            $this->forgetLimitsCache($licenseKeyPlain, $host);
        }

        return $resp;
    }

    public function desactivarYRegistrar(int $userId, string $licenseKeyPlain, string $domain): array
    {
        $host = $this->host($domain);

        $resp = $this->deactivate($licenseKeyPlain, $host);

        if (data_get($resp, 'deactivated') === true || data_get($resp, 'success') === true) {
            LicenciaDominiosActivacionModel::where('license_key', sha1($licenseKeyPlain))
                ->where('dominio', $host)
                ->update([
                    'user_id' => $userId,
                    'estatus' => 'inactivo',
                    'desactivado_at' => now(),
                ]);

            $this->forgetLimitsCache($licenseKeyPlain, $host);
        }

        return $resp;
    }

    // ✅ Host consistente en TODO el sistema (sin www, lowercase)
    private function host(string $value): string
{
    $value = trim($value);
    $value = preg_replace('#^https?://#i', '', $value);
    $value = rtrim($value, '/');

    if (str_contains($value, '/')) {
        $url = 'https://' . $value;
        $value = parse_url($url, PHP_URL_HOST) ?: $value;
    }

    $value = strtolower($value);
    $value = preg_replace('/^www\./i', '', $value);

    return trim($value);
}

    private function client()
    {
        return Http::baseUrl(config('services.license.base_url'))
            ->timeout((int) config('services.license.timeout', 10))
            ->acceptJson();
    }

    private function validateCacheKey(string $licenseKey, string $domain, ?string $email = null): string
    {
        $domain = $this->host($domain);
        $email  = strtolower(trim((string)$email));
        return "license:validate:" . sha1($licenseKey . '|' . $domain . '|' . $email);
    }


    private function forgetValidateCache(string $licenseKey, string $domain, ?string $email = null): void
    {
        Cache::forget($this->validateCacheKey($licenseKey, $domain, $email));
    }


    private function forgetLimitsCache(string $licenseKey, string $domain): void
    {
        $domain = $this->host($domain);
        $cacheKey = "license:limits:" . sha1($licenseKey . '|' . $domain);
        Cache::forget($cacheKey);
    }

    public function licenseUsageRange(array $planResp): array
    {
        $raw = (array) data_get($planResp, 'raw', $planResp);

        $startStr = data_get($raw, 'validity_start') ?? data_get($raw, 'created_at') ?? null;
        $endStr   = data_get($raw, 'validity_end')   ?? data_get($raw, 'expires_at') ?? null;

        $start = $startStr ? Carbon::parse($startStr)->utc() : null;
        $end   = $endStr   ? Carbon::parse($endStr)->utc()   : null;

        $valid  = (bool) data_get($raw, 'valid', false);
        $active = (bool) data_get($raw, 'active', false);
        $status = strtolower((string) data_get($raw, 'status', ''));

        $nowUtc = now('UTC');

        $statusOk = in_array($status, ['active', 'trial', 'grace', 'valid'], true);

        if (!$start || !$end) {
            return [
                $start ?: $nowUtc->copy()->startOfDay(),
                $end,
                [
                    'is_active' => false,
                    'start' => $start,
                    'end' => $end,
                    'status' => $status,
                    'reason' => 'missing_validity_window',
                ],
            ];
        }

        $isActive =
            $valid && $active && $statusOk &&
            $nowUtc->greaterThanOrEqualTo($start) &&
            $nowUtc->lessThan($end);

        return [
            $start,
            $end,
            [
                'is_active' => $isActive,
                'start' => $start,
                'end' => $end,
                'status' => $status,
                'reason' => $isActive ? 'ok' : 'out_of_window_or_inactive',
            ],
        ];
    }

    public function getPlanLimitsAuto(string $licenseKey, string $domain, ?string $email = null): array
    {
        $domain = $this->host($domain);

        $cached = $this->getPlanLimitsCached($licenseKey, $domain, $email, false);
        $raw = (array) data_get($cached, 'raw', $cached);

        $nowUtc = now('UTC');

        $nextCheckAt = data_get($raw, 'cache.next_check_at');
        $nextCheck = $nextCheckAt ? Carbon::parse($nextCheckAt)->utc() : null;

        $startStr = data_get($raw, 'validity_start') ?? data_get($raw, 'created_at');
        $endStr   = data_get($raw, 'validity_end')   ?? data_get($raw, 'expires_at');

        $start = $startStr ? Carbon::parse($startStr)->utc() : null;
        $end   = $endStr   ? Carbon::parse($endStr)->utc()   : null;

        $status = strtolower((string) data_get($raw, 'status', ''));
        $valid  = (bool) data_get($raw, 'valid', false);
        $active = (bool) data_get($raw, 'active', false);

        $mustRefresh = false;

        if ($nextCheck && $nowUtc->greaterThanOrEqualTo($nextCheck)) $mustRefresh = true;
        if (in_array($status, ['expired','inactive','invalid'], true) || !$valid || !$active) $mustRefresh = true;
        if ($start && $nowUtc->lessThan($start)) $mustRefresh = true;
        if ($end && $nowUtc->greaterThanOrEqualTo($end)) $mustRefresh = true;
        if (!$start || !$end) $mustRefresh = true;

        if (!$mustRefresh) return $cached;

        return $this->getPlanLimitsCached($licenseKey, $domain, $email, true);
    }
}
