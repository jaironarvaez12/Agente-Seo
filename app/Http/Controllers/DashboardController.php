<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\LicenseService;
use Carbon\Carbon;
use App\Models\LicenciaDominiosActivacionModel;
class DashboardController extends Controller
{
    

  public function dashboardseo(LicenseService $licenseService)
{
    $actor = Auth::user();

    // ✅ Titular real (si el actor es dependiente, tomaría el padre)
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

    // ✅ Licencia plana del titular
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

    // Defaults
    $planName   = 'free';
    $isActive   = false;
    $expiresAt  = null; // Carbon|null
    $daysLeft   = null; // int|null
    $domainUsado = null;
    $errorLicencia = null;

    // ✅ 1) Buscar el último dominio ACTIVO asociado a esa licencia (guardada como sha1)
    $domainUsado = LicenciaDominiosActivacionModel::where('license_key', sha1($licenciaPlano))
        ->where('estatus', 'activo')
        ->orderByDesc('activo_at')
        ->value('dominio');

    // ✅ 2) Si no hay dominio activo guardado, usa el host actual como fallback
    $domainUsado = $domainUsado ?: request()->getHost();

    // ✅ 3) Consultar plan/vigencia usando ese dominio (tu service ya hace refresh automático)
    try {
        $planResp = $licenseService->getPlanLimitsAuto($licenciaPlano, $domainUsado, $emailLicencia);

        $planName = data_get($planResp, 'plan', 'free');

        // ✅ 4) Ventana de vigencia (usa validity_start/end y fallback a created/expires)
        $w = $licenseService->licenseWindow($planResp);

        $isActive  = (bool) data_get($w, 'is_active', false);
        $expiresAt = data_get($w, 'end'); // Carbon|null

        if ($expiresAt) {
            $daysLeft = max(
                0,
                now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false)
            );
        }
    } catch (\Throwable $e) {
        // Si la API falla o hay un throw()
        $errorLicencia = 'No se pudo consultar la licencia. Intenta de nuevo.';
    }

    return view('DashboardSeo', compact('planName','isActive','expiresAt','daysLeft','domainUsado','errorLicencia'));
}
}
