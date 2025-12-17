<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UsuariosUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|regex:/^[\pL\s]+$/u',
            'email' => 'required|email',
            'password' => '',
            'roles' => 'required|not_in:0',
            
          
          
        ];
    }


    public function messages(): array
    {
        return[

            'name.required'=> 'El nombre es obligatorio',
            'email.required'=> 'El correo es obligatorio',
            'roles.required'=> 'El rol es obligatorio',
           
            
        ];
    }
}
