<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Exception;

class RolesController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $roles = Role::all();
        return view('Roles.Roles', compact('roles'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $permisos = Permission::select('id', 'name')->orderBy('name')->get();
        return view('Roles.RolesCreate', compact('permisos'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        try {

            $role = Role::create($request->only('name')); //creacion de Roles
            $role->syncPermissions($request->input('permisos', [])); //asignacion de los permisos a los roles
        } 
        catch (Exception $ex) 
            {
                return redirect("roles")->withError('Ha Ocurido Un Error Al Crear El Perfil'.$ex->getMessage());
            }

        return redirect("roles")->withSuccess('El Perfil Ha Sido Creado Exitosamente');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $role = Role::with('permissions')->find($id); //consulta de permisos en el modelo de roles
        $permisos = Permission::select('id', 'name')->orderBy('name')->get();

        return view('Roles.RolesEdit', compact('role', 'permisos'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //dd($request->permisos);
        $roles = Role::find($id);

        try
        {
            $roles->update($request->only('name')); // actualizacion de roles

            // Divide los permisos en lotes de 800
            $permisos = array_chunk($request->input('permisos', []), 800);

            //Vaciar permisos
            $roles->syncPermissions([]); 

            // Asigna los permisos al rol en lotes
            foreach ($permisos as $permiso) 
            {
                $roles->givePermissionTo($permiso); //sincronizacion de los permisos en los roles
            }

            $roles->save();
        }
        catch (Exception $ex) 
            {
                return redirect("roles")->withError('Ha Ocurrido Un Error Al Actualizar El Perfil'.$ex->getMessage());
            }

        return redirect("roles")->withSuccess('El Perfil Se Ha Actualizado Exitosamente');
    } 

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try 
        {
            Role::destroy($id);
        } 
        catch (Exception $ex) 
            {
                return redirect("roles")->withError('No Se Puede Eliminar El Perfil Tiene Permisos y Usuarios asociados');
            }
        
        return redirect("roles")->withSuccess('El Perfil Ha Sido Eliminado Exitosamente');
    }
}
