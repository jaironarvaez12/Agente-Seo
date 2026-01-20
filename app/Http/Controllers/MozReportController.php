<?php

namespace App\Http\Controllers;

use App\Models\DominiosModel;
use App\Models\MozDomainSnapshot;
use App\Services\MozClient;
use Illuminate\Http\Request;

class MozReportController extends Controller
{
    public function generar($id_dominio, MozClient $moz)
    {
        $dominio = DominiosModel::findOrFail($id_dominio);

        $target = $this->toHost($dominio->url);

        try {
            $response = $moz->urlMetrics([$target]);

            MozDomainSnapshot::create([
                'id_dominio' => $dominio->id_dominio,
                'target' => $target,
                'pulled_at' => now(),
                'payload' => $response,
                'status' => 'ok',
            ]);

            return redirect()
                ->route('dominios.moz.reporte.ver', $dominio->id_dominio)
                ->with('success', 'Reporte Moz generado correctamente.');
        } catch (\Throwable $e) {
            MozDomainSnapshot::create([
                'id_dominio' => $dominio->id_dominio,
                'target' => $target,
                'pulled_at' => now(),
                'payload' => null,
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);

            return back()->with('error', 'Error al generar reporte Moz: ' . $e->getMessage());
        }
    }

    public function ver($id_dominio)
    {
        $dominio = DominiosModel::findOrFail($id_dominio);

        $snapshot = MozDomainSnapshot::where('id_dominio', $dominio->id_dominio)
            ->orderByDesc('pulled_at')
            ->first();

        return view('Dominios.moz', compact('dominio', 'snapshot'));
    }

    private function toHost(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $host = preg_replace('/^www\./i', '', $host);
        return strtolower($host);
    }
}
