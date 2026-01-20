<?php

namespace App\Services;
use Illuminate\Support\Facades\Http;

class PageSpeedClient
{
    public function run(string $url, string $strategy = 'mobile'): array
    {
        $key = config('services.pagespeed.key');

        $resp = Http::timeout(60)->get(
            'https://www.googleapis.com/pagespeedonline/v5/runPagespeed',
            [
                'url' => $url,
                'strategy' => $strategy, // mobile|desktop
                'key' => $key,
                'category' => ['performance'], // puedes agregar seo, accessibility, best-practices si quieres
            ]
        );

        if (!$resp->successful()) {
            throw new \RuntimeException("PageSpeed error {$resp->status()}: " . $resp->body());
        }

        $json = $resp->json();

        // Extraer métricas principales (Lighthouse)
        $lh = data_get($json, 'lighthouseResult');
        $audits = data_get($lh, 'audits', []);

        $score = data_get($lh, 'categories.performance.score');
        $score = is_numeric($score) ? round($score * 100) : null;

        return [
            'strategy' => $strategy,
            'score' => $score,
            'lcp' => data_get($audits, 'largest-contentful-paint.displayValue'),
            'cls' => data_get($audits, 'cumulative-layout-shift.displayValue'),
            'inp' => data_get($audits, 'interaction-to-next-paint.displayValue'), // si no aparece, puede venir null
            'tti' => data_get($audits, 'interactive.displayValue'),
            'fcp' => data_get($audits, 'first-contentful-paint.displayValue'),
            'raw' => $json, // guardas todo por si luego quieres más
        ];
    }
}