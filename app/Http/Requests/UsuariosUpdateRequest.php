<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\User;

class UsuariosUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Obtener el ID real desde la ruta
        // Normalmente tu método recibe update(UsuariosUpdateRequest $request, $id)
        // así que el parámetro suele llamarse "id" o "usuario" dependiendo de tu ruta/resource.
        $id = $this->route('id')
            ?? $this->route('usuario')
            ?? $this->route('user')
            ?? $this->route('usuarios'); // por si usas Route::resource('usuarios', ...)

        $usuarioEditado = $id ? User::find($id) : null;
        $esDependiente = $usuarioEditado && !is_null($usuarioEditado->id_usuario_padre);

        $reglas = [
            'name'     => 'required|regex:/^[\pL\s]+$/u',
            'email'    => 'required|email',
            'password' => 'nullable|string|min:6',
        ];

        /**
         * ROLES:
         * - Si tú quieres que SIEMPRE tenga rol, déjalo required.
         * - Si el dependiente siempre tendrá rol fijo "dependiente", puedes NO obligarlo desde el form.
         */
        if (!$esDependiente) {
            $reglas['roles'] = 'required|not_in:0';
        } else {
            // dependiente: no obligues roles desde el form (opcional)
            $reglas['roles'] = 'nullable';
        }

        /**
         * LICENCIA:
         * - Solo se valida si el usuario editado es titular (no dependiente)
         * - Si es dependiente, ni se valida ni se pide
         */
        if (!$esDependiente) {
            $reglas['license_email'] = 'nullable|email';
            $reglas['license_key']   = 'nullable|string|min:6';
        } else {
            $reglas['license_email'] = 'nullable';
            $reglas['license_key']   = 'nullable';
        }

        return $reglas;
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {

            // Obtener el ID real de ruta
            $id = $this->route('id')
                ?? $this->route('usuario')
                ?? $this->route('user')
                ?? $this->route('usuarios');

            $usuarioEditado = $id ? User::find($id) : null;
            $esDependiente = $usuarioEditado && !is_null($usuarioEditado->id_usuario_padre);

            // ✅ Si es dependiente, NO validamos licencia (no aplica)
            if ($esDependiente) {
                return;
            }

            // Si no mandó license_key, no hacemos nada
            if (!$this->filled('license_key')) {
                return;
            }

            $plain = trim((string) $this->input('license_key'));

            // Recorre usuarios y compara contra la versión DESENCRIPTADA
            $enUso = User::whereNotNull('license_key')
                ->when($id, fn($q) => $q->where('id', '!=', $id))
                ->get()
                ->first(function ($u) use ($plain) {
                    try {
                        $storedPlain = $u->getLicenseKeyPlain(); // decrypt
                        return $storedPlain !== null && hash_equals($plain, $storedPlain);
                    } catch (\Throwable $e) {
                        return false;
                    }
                });

            if ($enUso) {
                $validator->errors()->add('license_key', 'La license key ya está en uso por otro usuario.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required'=> 'El nombre es obligatorio',
            'email.required'=> 'El correo es obligatorio',
            'roles.required'=> 'El rol es obligatorio',
            'roles.not_in'=> 'Debe seleccionar un rol válido',

            'license_email.email' => 'El correo de licencia no es válido',
            'license_key.min' => 'La license key es demasiado corta',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres',
        ];
    }
}
