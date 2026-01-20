<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ChartImageService
{
    public function makeLinePng(string $publicPath, array $labels, array $series, string $title): string
    {
        $config = [
            'type' => 'line',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => $title,
                    'data' => $series,
                    'fill' => false,
                    'borderWidth' => 2,
                    'pointRadius' => 1,
                    'tension' => 0.2,
                ]],
            ],
            'options' => [
                'responsive' => true,
                'plugins' => [
                    'legend' => ['display' => true],
                    'title' => ['display' => true, 'text' => $title],
                ],
                'scales' => [
                    'x' => ['ticks' => ['maxRotation' => 0, 'autoSkip' => true]],
                    'y' => ['beginAtZero' => true],
                ],
            ],
        ];

        return $this->fetchAndStore($publicPath, $config, 900, 300);
    }

    public function makeBarPng(string $publicPath, array $labels, array $series, string $title): string
    {
        $config = [
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => $title,
                    'data' => $series,
                    'borderWidth' => 1,
                ]],
            ],
            'options' => [
                'plugins' => [
                    'legend' => ['display' => false],
                    'title' => ['display' => true, 'text' => $title],
                ],
                'scales' => [
                    'y' => ['beginAtZero' => true, 'max' => 100],
                ],
            ],
        ];

        return $this->fetchAndStore($publicPath, $config, 900, 260);
    }

    private function fetchAndStore(string $publicPath, array $chartConfig, int $w, int $h): string
    {
        $base = rtrim(config('services.quickchart.base', 'https://quickchart.io'), '/');
        $url = $base . '/chart';

        $res = Http::timeout(30)->get($url, [
            'format' => 'png',
            'width' => $w,
            'height' => $h,
            'backgroundColor' => 'white',
            'c' => json_encode($chartConfig),
        ]);

        if (!$res->ok()) {
            throw new \RuntimeException("QuickChart error: {$res->status()}");
        }

        Storage::disk('public')->put($publicPath, $res->body());

        // Retorna ruta absoluta del archivo en disco (para file:// en Dompdf)
        return Storage::disk('public')->path($publicPath);
    }
    public function makeBarComparePng(string $publicPath, array $labels, array $seriesA, array $seriesB, string $labelA, string $labelB, string $title): string
{
    $config = [
        'type' => 'bar',
        'data' => [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $labelA,
                    'data' => $seriesA,
                    'borderWidth' => 1,
                ],
                [
                    'label' => $labelB,
                    'data' => $seriesB,
                    'borderWidth' => 1,
                ],
            ],
        ],
        'options' => [
            'plugins' => [
                'legend' => ['display' => true],
                'title' => ['display' => true, 'text' => $title],
            ],
            'scales' => [
                'y' => ['beginAtZero' => true],
            ],
        ],
    ];

    return $this->fetchAndStore($publicPath, $config, 900, 300);
}

public function makeBarSimplePng(string $publicPath, array $labels, array $series, string $title): string
{
    $config = [
        'type' => 'bar',
        'data' => [
            'labels' => $labels,
            'datasets' => [[
                'label' => $title,
                'data' => $series,
                'borderWidth' => 1,
            ]],
        ],
        'options' => [
            'plugins' => [
                'legend' => ['display' => false],
                'title' => ['display' => true, 'text' => $title],
            ],
            'scales' => [
                'y' => ['beginAtZero' => true],
            ],
        ],
    ];

    return $this->fetchAndStore($publicPath, $config, 900, 260);
}
public function makePiePng(string $publicPath, array $labels, array $series, string $title): string
{
    $config = [
        'type' => 'pie',
        'data' => [
            'labels' => $labels,
            'datasets' => [[
                'data' => $series,
            ]],
        ],
        'options' => [
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'right'],
                'title' => ['display' => true, 'text' => $title],
            ],
        ],
    ];

    return $this->fetchAndStore($publicPath, $config, 900, 320);
}
public function makeLineAreaPng(string $publicPath, array $labels, array $series, string $title, string $datasetLabel = 'Tráfico Orgánico'): string
{
    // Estilo tipo la captura: línea naranja + relleno degradado suave
    $config = [
        'type' => 'line',
        'data' => [
            'labels' => $labels,
            'datasets' => [[
                'label' => $datasetLabel,
                'data' => $series,

                // ✅ look & feel
                'fill' => true,
                'tension' => 0.35, // suaviza la curva
                'borderWidth' => 2,
                'borderColor' => '#2f80ed',

                // puntos
                'pointRadius' => 4,
                'pointHoverRadius' => 5,
                'pointBackgroundColor' => '#ffffff',
                'pointBorderColor' => '#2f80ed',
                'pointBorderWidth' => 2,

                // degradado del área (QuickChart soporta strings especiales)
                'backgroundColor' => 'rgba(47,128,237,0.18)',
            ]],
        ],
        'options' => [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                    'align' => 'end',
                    'labels' => [
                        'boxWidth' => 12,
                        'boxHeight' => 12,
                        'usePointStyle' => true,
                        'pointStyle' => 'rect',
                    ],
                ],
                'title' => [
                    'display' => true,
                    'text' => $title,
                    'align' => 'start',
                    'font' => ['size' => 14, 'weight' => 'bold'],
                ],
            ],
            'scales' => [
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                    'ticks' => [
                        'maxRotation' => 0,
                        'autoSkip' => true,
                        'maxTicksLimit' => 8,
                    ],
                ],
                'y' => [
                'beginAtZero' => true,
                'ticks' => ['precision' => 0],
                'grace' => '10%',
                'grid' => ['color' => 'rgba(0,0,0,0.08)'],
                ],
            ],
            'elements' => [
                'line' => ['capBezierPoints' => true],
            ],
        ],
    ];

    // Tamaño parecido al de tu captura (ancho alto)
    return $this->fetchAndStore($publicPath, $config, 1000, 320);
}
}