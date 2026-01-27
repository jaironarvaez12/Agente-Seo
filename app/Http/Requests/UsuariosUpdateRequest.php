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
        return [
            'name' => 'required|regex:/^[\pL\s]+$/u',
            'email' => 'required|email',
            'password' => 'nullable|string',
            'roles' => 'required|not_in:0',

            'license_email' => 'nullable|email',
            'license_key'   => 'nullable|string|min:6',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {

            if (!$this->filled('license_key')) {
                return;
            }

            $plain = trim((string) $this->input('license_key'));

            $userId = $this->route('id') ?? $this->route('usuario') ?? $this->route('user');

            // Recorre usuarios y compara contra la versión DESENCRIPTADA
            $enUso = User::whereNotNull('license_key')
                ->when($userId, fn($q) => $q->where('id', '!=', $userId))
                ->get()
                ->first(function ($u) use ($plain) {
                    try {
                        $storedPlain = $u->getLicenseKeyPlain(); // decrypt
                        return $storedPlain !== null && hash_equals($plain, $storedPlain);
                    } catch (\Throwable $e) {
                        // si hay datos corruptos o no desencripta, lo ignoramos
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

            'license_email.email' => 'El correo de licencia no es válido',
            'license_key.min' => 'La license key es demasiado corta',
        ];
    }
}
