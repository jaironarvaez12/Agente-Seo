<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\LicenseService;

class LicenseController extends Controller
{
    // 1) Guardar licencia en el usuario
    public function store(Request $request)
    {
        $request->validate([
            'license_key' => ['required','string'],
            'license_email' => ['nullable','email'],
        ]);

        $user = $request->user();
        $user->setLicenseKeyPlain($request->license_key);
        $user->license_email = $request->license_email;
        $user->save();

        return response()->json(['success' => true]);
    }

    // 2) Validar licencia (consulta API)
    public function validateLicense(Request $request, LicenseService $licenses)
    {
        $request->validate([
            'domain' => ['required','string'], // ejemplo.com
        ]);

        $user = $request->user();
        $licenseKey = $user->getLicenseKeyPlain();

        if (!$licenseKey) {
            return response()->json([
                'valid' => false,
                'message' => 'El usuario no tiene licencia guardada.'
            ], 422);
        }

        $resp = $licenses->validateCached($licenseKey, $request->domain);

        return response()->json($resp);
    }
}
