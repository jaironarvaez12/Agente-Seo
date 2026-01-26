<?php

namespace App\Services;

use App\Models\LicenciaDominiosActivacionModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class LicenseService
{
    /**
     * Validación cacheada (tal cual tu implementación).
     * OJO: si el dominio no está activado, puede devolver plan "free".
     */
    public function validateCached(string $licenseKey, string $domain): array
    {
        $domain = $this->host($domain);
        $cacheKey = "license:validate:" . sha1($licenseKey . '|' . $domain);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $resp = $this->client()
            ->post('/api/licenses/validate', [
                'license_key' => $licenseKey,
                'domain' => $domain,
            ])
            ->throw()
            ->json();

        $ttl = (int) data_get($resp, 'cache.recommended_ttl', 43200); // 12h por defecto
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

        // ✅ invalidar cache de validate para ese dominio
        $this->forgetValidateCache($licenseKey, $domain);

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

        // ✅ invalidar cache de validate para ese dominio
        $this->forgetValidateCache($licenseKey, $domain);

        return $resp;
    }

    /**
     * Si validate dice "not activated on this domain", activa y luego valida fresh.
     */
    public function validateOrActivate(string $licenseKey, string $domain, ?string $email = null): array
    {
        $domain = $this->host($domain);

        $resp = $this->validateCached($licenseKey, $domain);

        $msg = strtolower((string) data_get($resp, 'message', ''));
        $status = strtolower((string) data_get($resp, 'status', ''));

        $needsActivation =
            ($status === 'invalid' || $status === 'inactive') &&
            str_contains($msg, 'not activated on this domain');

        if ($needsActivation) {
            $this->activate($licenseKey, $domain, $email);

            // Validación FRESCA (sin cache)
            $fresh = $this->client()
                ->post('/api/licenses/validate', [
                    'license_key' => $licenseKey,
                    'domain'      => $domain,
                ])
                ->throw()
                ->json();

            // guardar fresh en cache con ttl recomendado
            $ttl = (int) data_get($fresh, 'cache.recommended_ttl', 43200);
            $ttl = max(60, $ttl);

            Cache::put($this->validateCacheKey($licenseKey, $domain), $fresh, now()->addSeconds($ttl));

            return $fresh;
        }

        return $resp;
    }

    /**
     * ✅ Este es el que vas a usar para sacar plan + limits para max_content/max_report.
     * - Usa validateOrActivate para evitar caer en "free"
     * - Cachea con ttl recomendado por el server
     */
    public function getPlanLimitsCached(string $licenseKey, string $domain, ?string $email = null, bool $force = false): array
{
    $domain = $this->host($domain);
    $cacheKey = "license:limits:" . sha1($licenseKey . '|' . $domain);

    if ($force) {
        Cache::forget($cacheKey);
    }

    if (!$force && ($cached = Cache::get($cacheKey))) {
        return $cached;
    }

    $resp = $this->validateOrActivate($licenseKey, $domain, $email);

    $payload = [
        'plan' => (string) data_get($resp, 'plan', 'free'),
        'limits' => (array) data_get($resp, 'limits', []),
        'raw' => $resp,
    ];

    $ttl = (int) data_get($resp, 'cache.recommended_ttl', 43200);
    $ttl = max(60, $ttl);

    Cache::put($cacheKey, $payload, now()->addSeconds($ttl));

    return $payload;
}

    /**
     * Normaliza límites y aplica fallback por config si la API no los manda.
     */
    public function normalizeLimits(string $plan, array $limits): array
{
    // Si la API NO trae un límite, mejor ser conservador (0) en vez de inventar.
    // Así no te pasas del plan real.
    $fallback = (array) config("licenses.limits_by_plan.$plan", []);

    return [
        'max_activations' => (int) ($limits['max_activations'] ?? $fallback['max_activations'] ?? 0),

        // 👇 IMPORTANTE: aquí preferimos 0 si no viene en la API
        'max_content'     => array_key_exists('max_content', $limits)
            ? (int) $limits['max_content']
            : 0,

        'max_report'      => array_key_exists('max_report', $limits)
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

            // ✅ limpia cache de limits también (porque cambia el estado del dominio)
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

    private function host(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('#^https?://#i', '', $value);
        $value = rtrim($value, '/');

        if (str_contains($value, '/')) {
            $url = 'https://' . $value;
            return parse_url($url, PHP_URL_HOST) ?: $value;
        }

        return $value;
    }

    private function client()
    {
        return Http::baseUrl(config('services.license.base_url'))
            ->timeout((int) config('services.license.timeout', 10))
            ->acceptJson();
    }

    private function validateCacheKey(string $licenseKey, string $domain): string
    {
        return "license:validate:" . sha1($licenseKey . '|' . $domain);
    }

    private function forgetValidateCache(string $licenseKey, string $domain): void
    {
        Cache::forget($this->validateCacheKey($licenseKey, $domain));
    }

    private function forgetLimitsCache(string $licenseKey, string $domain): void
    {
        $domain = $this->host($domain);
        $cacheKey = "license:limits:" . sha1($licenseKey . '|' . $domain);
        Cache::forget($cacheKey);
    }


    public function licenseWindow(array $planResp): array
    {
        $raw = (array) ($planResp['raw'] ?? []);

        $valid = (bool) ($raw['valid'] ?? false);
        $active = (bool) ($raw['active'] ?? false);
        $status = strtolower((string) ($raw['status'] ?? ''));

        // ✅ prioridad: validity_start/end; fallback: created_at/expires_at
        $startRaw = $raw['validity_start'] ?? ($raw['created_at'] ?? null);
        $endRaw   = $raw['validity_end']   ?? ($raw['expires_at'] ?? null);

        $start = $startRaw ? \Carbon\Carbon::parse($startRaw) : null;
        $end   = $endRaw   ? \Carbon\Carbon::parse($endRaw)   : null;

        // activa si (valid && active && status=active) y no expirada por fecha
        $isActive = $valid && $active && ($status === 'active') && ($end ? now()->lt($end) : true);

        return [
            'is_active' => (bool) $isActive,
            'status'    => $status,
            'start'     => $start,
            'end'       => $end,
        ];
    }

    /**
     * Helper listo para usar en queries de cupo:
     * devuelve [$desde, $hasta] (Carbon|null)
     */
    public function licenseUsageRange(array $planResp): array
    {
        $w = $this->licenseWindow($planResp);

        // Si no hay start, usa created_at; si no, usa inicio del día de hoy (fallback)
        $desde = $w['start'] ?: now()->startOfDay();
        $hasta = $w['end']; // puede ser null

        return [$desde, $hasta, $w];
    }
}
