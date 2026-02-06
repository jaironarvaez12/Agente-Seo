<?php

namespace App\Http\Controllers;

use App\Models\Dominios_Contenido_DetallesModel;
use App\Models\DominiosModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\LicenseService;
use Carbon\Carbon;
use App\Models\User;

use App\Models\LicenciaDominiosActivacionModel;
use App\Models\SeoReport;

class DashboardController extends Controller
{
    
    private function esHostLocal(string $host): bool
    {
        $h = strtolower(trim($host));
        return in_array($h, ['localhost', '127.0.0.1', '0.0.0.0'], true)
            || str_ends_with($h, '.local')
            || str_ends_with($h, '.test');
    }
    public function dashboardseo(LicenseService $licenseService)
    {
        $actor = auth()->user();
        $esAdmin = Auth::user()->roles[0]->name == 'administrador';
        $generado= Dominios_Contenido_DetallesModel::ContenidoGenerado(Auth::user()->id,$esAdmin);
        if($esAdmin =='administrado')
        {
             $dominios=DominiosModel::count();
        }
        else 
        {
            $dominios=DominiosModel::DominiosRegistrados(Auth::user()->id);
        }

        if($esAdmin =='administrado')
        {
             $reportes = SeoReport::where('status', 'ok')->count();

        }
        else 
        {
            $reportes=SeoReport::ReportesGenerados(Auth::user()->id);
        }
       // dd($reportes);
      
       // dd($generado);
        $titular = $actor->titularLicencia();
        if (!$titular) {
            return view('DashboardSeo', [
                'planName' => 'free',
                'isActive' => false,
                'expiresAt' => null,
                'daysLeft' => null,
                'domainUsado' => null,
                'errorLicencia' => 'No se encontró el titular de la licencia.',
            ]);
        }

        $licenciaPlano = $titular->getLicenseKeyPlain();
        if (!$licenciaPlano) {
            return view('DashboardSeo', [
                'planName' => 'free',
                'isActive' => false,
                'expiresAt' => null,
                'daysLeft' => null,
                'domainUsado' => null,
                'errorLicencia' => 'El titular no tiene licencia registrada.',
            ]);
        }

        $emailLicencia = $titular->license_email ?? $titular->email;

        $planName = 'free';
        $isActive = false;
        $expiresAt = null;
        $daysLeft = null;
        $domainUsado = null;
        $errorLicencia = null;

        // 1) Dominio activo guardado en tu BD (si existe)
        $domainActivo = LicenciaDominiosActivacionModel::where('license_key', sha1($licenciaPlano))
            ->where('estatus','activo') // <-- si te da error, vuelve a ->where('estatus','activo')
            ->orderByDesc('activo_at')
            ->value('dominio');

        $hostActual = request()->getHost();

        // 2) Decidir estrategia
        $usarProbe = false;

        if ($domainActivo) {
            $domainUsado = $domainActivo;
        } else {
            // Si estás en local, NO uses localhost para validar
            if ($this->esHostLocal($hostActual)) {
                $usarProbe = true;
            } else {
                $domainUsado = $hostActual;
            }
        }

        // 3) Consultar plan
        try {
            if ($usarProbe) {
                $probe = 'probe-' . substr(sha1(uniqid('', true)), 0, 10) . '.ideiweb.com';
                $domainUsado = $probe;

                // Activación temporal (para que el server te deje consultar plan)
                $act = $licenseService->activate($licenciaPlano, $probe, $emailLicencia);

                if (!data_get($act, 'activated')) {
                    $errorLicencia = data_get($act, 'message', 'No hay activaciones disponibles para consultar la licencia.');
                    return view('DashboardSeo', compact('planName','isActive','expiresAt','daysLeft','domainUsado','errorLicencia'));
                }

                try {
                    $planResp = $licenseService->getPlanLimitsAuto($licenciaPlano, $probe, $emailLicencia);
                } finally {
                    // Siempre desactiva el probe para no gastar cupo
                    try { $licenseService->deactivate($licenciaPlano, $probe); } catch (\Throwable $ignore) {}
                }
            } else {
                // Caso normal: usar dominio activo o host real (no localhost)
                $planResp = $licenseService->getPlanLimitsAuto($licenciaPlano, $domainUsado, $emailLicencia);
            }

            $planName = data_get($planResp, 'plan', 'free');

            $range = $licenseService->licenseUsageRange($planResp);
            $meta = $range[2] ?? [];

            $isActive  = (bool) data_get($meta, 'is_active', false);
            $expiresAt = data_get($meta, 'end');

            if ($expiresAt) {
                $daysLeft = max(0, now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false));
            }

        } catch (\Throwable $e) {
            $errorLicencia = $e->getMessage();
            if (method_exists($e, 'response') && $e->response) {
                $errorLicencia .= ' | ' . $e->response->body();
            }
        }

        return view('DashboardSeo', compact('planName','isActive','expiresAt','daysLeft','domainUsado','errorLicencia','generado','dominios','reportes'));
    }

}
