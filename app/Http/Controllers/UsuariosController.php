<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\UsuariosCreateRequest;
use App\Http\Requests\UsuariosUpdateRequest;
use App\Models\Dominios_UsuariosModel;
use App\Models\DominiosModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\EmpresasModel;
use App\Models\Usuarios_TiendasModel;
use App\Models\Mtto_ActivosModel;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use PhpParser\Node\Stmt\Return_;
use Redirect;
use Session;
use Illuminate\Support\Facades\DB;


class UsuariosController extends Controller
{

    public function index()
    {
        $usuarios = User::select('id','name', 'email')->get();
  
       
        return view('Usuarios.Usuarios',compact('usuarios'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        
    
             $roles = Role::select('id', 'name')->get(); //trae los roles para el usuario
        return view('Usuarios.UsuariosCreate',compact('roles'));
    }


    public function store(UsuariosCreateRequest $request)
    {   
        //dd($request->all());
        $usuario = new User();
        $usuario->name = strtoupper($request['name']);
        $usuario->email = $request['email'];
        $usuario->password = Hash::make($request['contraseña']);
    
    
       
       
        $usuario->syncRoles($request->input('roles'));  //Sinronizacion de rol
        $DatosTiendas = json_decode($request['datos_tiendas']); //arreglo de datos adicionales
    
      

        try
        {    
            


            DB::transaction(function () use ($DatosTiendas , $usuario)
             {
                 $usuario->save(); //actualizar usuario

                // if ($DatosTiendas  != NULL)  //verifica si el arreglo no esta vacio
                // {
                  
            
                //     foreach ($DatosTiendas  as $tienda) {
                //         $IdUsuarioTienda=  Usuarios_TiendasModel::max('id_usuario_tienda') + 1;
                //         Usuarios_TiendasModel::create([

                //             'id_usuario_tienda' =>  $IdUsuarioTienda,
                //             'id_tienda' => $tienda->id_tienda,
                //             'id_usuario'=> $usuario->id,
                //         ]);
                //     }
                // } 
                
                
            });
        }
        catch(Exception $ex)
            {
                return redirect()->back()->withError('Ha Ocurrido Un Error Al Crear El Usuario '. $ex->getMessage())->withInput();
            }

        return redirect("usuarios")->withSuccess('El Usuario Ha sido Creado Exitosamente');
    }


    public function show($id)
    {

    }

    public function edit($id)
    {
        $usuario = User::findOrFail($id);

        $permisos = Permission::select('id', 'name')->orderBy('name')->get();
        $roles    = Role::select('id', 'name')->get();

        // Dominios ya asignados a ESTE usuario (para excluirlos del select)
        $idsDominiosAsignados = Dominios_UsuariosModel::where('id_usuario', $id)
            ->pluck('id_dominio');

        $query = DominiosModel::whereNotIn('id_dominio', $idsDominiosAsignados);

        // Si NO es admin, solo ve los dominios creados por él
        if (Auth::user()->roles[0]->name != 'administrador') {
            $query->where('creado_por', Auth::id());
        }

        $dominios = $query->get();

        $DominiosUsuario = Dominios_UsuariosModel::Dominios($id);

        return view('Usuarios.UsuariosEdit', compact('usuario','permisos','roles','DominiosUsuario','dominios'));
    }


    public function update(UsuariosUpdateRequest $request, $id)
{
    try {
        $actor = auth()->user();

        $usuario = User::findOrFail($id);
        $FechaActual = Carbon::now()->format('Y-m-d H:i:s');

        // -----------------------------
        // 1) SEGURIDAD: ownership
        // -----------------------------
        // Titular real del ACTOR (quien está logueado)
        $idTitularActor = $actor->id_usuario_padre ?? $actor->id;

        // Permisos: admin o el titular editándose a sí mismo, o editando a alguien de su titular
        $permitido =
            $actor->hasRole('administrador')
            || $usuario->id === $idTitularActor
            || $usuario->id_usuario_padre === $idTitularActor;

        abort_if(!$permitido, 403, 'No tienes permiso para editar este usuario.');

        // -----------------------------
        // 2) Datos básicos
        // -----------------------------
        $usuario->fill([
            'name'  => strtoupper($request->input('name')),
            'email' => $request->input('email'),
        ]);

        // -----------------------------
        // 3) Licencia: SOLO si el usuario editado es TITULAR (no dependiente)
        // -----------------------------
        $esDependiente = !is_null($usuario->id_usuario_padre);

        if (!$esDependiente) {
            $usuario->license_email = $request->input('license_email');

            if ($request->filled('license_key')) {
                $usuario->setLicenseKeyPlain($request->input('license_key'));
            }
        } else {
            if ($request->filled('license_key') || $request->filled('license_email')) {
                abort(403, 'Un usuario dependiente no puede modificar la licencia.');
            }
        }

        // -----------------------------
        // 4) Rol
        // -----------------------------
        if ($request->filled('roles') && $request->input('roles') !== '0') {
            $usuario->syncRoles($request->input('roles'));
        }

        // -----------------------------
        // 5) Contraseña (solo si viene)
        // -----------------------------
        if ($request->filled('password')) {
            $usuario->password = Hash::make($request->input('password'));
        }

        // -----------------------------
        // 6) Dominios (tabla pivote)
        //    ✅ NUEVA REGLA:
        //    El ACTOR puede asignar a este usuario cualquier dominio que pertenezca al titular del ACTOR.
        //    (admin puede asignar cualquiera si quieres permitirlo, ver nota abajo)
        // -----------------------------
        $DatosDominios = $request->filled('datos_tiendas')
            ? json_decode($request->input('datos_tiendas'))
            : null;

        DB::transaction(function () use ($DatosDominios, $usuario, $FechaActual, $idTitularActor, $actor) {

            $usuario->save();

            // Si mandan dominios (aunque sea lista vacía), sincronizamos
            if ($DatosDominios !== null) {

                // Limpia los dominios actuales del usuario editado
                Dominios_UsuariosModel::where('id_usuario', $usuario->id)->delete();

                // Evitar duplicados
                $idsUnicos = collect($DatosDominios)
                    ->pluck('id_dominio')
                    ->unique()
                    ->values();

                foreach ($idsUnicos as $idDominio) {

                    // ✅ VALIDACIÓN CLAVE:
                    // Si NO es admin, el dominio debe pertenecer al titular del ACTOR
                    $q = DominiosModel::where('id_dominio', $idDominio);

                    if (!$actor->hasRole('administrador')) {
                        $q->where('creado_por', $idTitularActor);
                    }

                    $dominioValido = $q->exists();

                    abort_if(!$dominioValido, 403, 'Intentaste asignar un dominio que no pertenece a tu titular.');

                    // Crear pivote
                    Dominios_UsuariosModel::create([
                        // ⚠️ recomendado: que este campo sea AUTO INCREMENT en BD
                        'id_dominio_usuario' => Dominios_UsuariosModel::max('id_dominio_usuario') + 1,
                        'id_dominio'         => $idDominio,
                        'id_usuario'         => $usuario->id,

                        // ✅ quien asigna (lo más lógico es el titular del actor)
                        'creado_por'         => $idTitularActor,
                        'fecha_creacion'     => $FechaActual,
                    ]);
                }
            }
        });

    } catch (Exception $ex) {
        return redirect()->back()
            ->withError('Ha ocurrido un error al actualizar el usuario: ' . $ex->getMessage())
            ->withInput();
    }

    return redirect()->route('usuarios.edit', $id)
        ->withSuccess('El Usuario se ha actualizado exitosamente');
}


    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
        
   
    

   

    

    public function destroy($IdUsuario)
    {
    
          try
        {   
  
            User::where('id', $IdUsuario)
            ->update([
                'activo' =>'NO',
            ]);
        
        }

        
        catch (Exception $ex)
        {
            return redirect("usuarios")->withError('No Se Puede Anular El Usuario '.$ex->getMessage());
        }
    
        
         return redirect("usuarios")->withSuccess('El Usuarios Ha Sido Anulado Exitosamente');
    }

   
     public function Activar(string $IdCliente)
    {
        try
        {
            $usuarios = User::find($IdCliente);
            $usuarios->fill([
               'activo'=> 'SI'
                
            ]);
            $usuarios->save(); 
        }
        catch (Exception $ex)
        {
            return redirect("usuarios")->withError('Error Al Activar El Usuario '.$ex->getMessage());
        }
        return redirect("usuarios")->withSuccess('El Usuario Ha Sido Activado Exitosamente');
    }
     public function EliminarDominio($IdDominioUsuario)
    {
         try
         {
            Dominios_UsuariosModel::destroy($IdDominioUsuario);
         }
         catch (Exception $e)
         {
             return back()->withError('Error Al Eliminar');
         }
 
         return back()->with('');
    }
    public function crearDependiente()
    {
        $titular = auth()->user();

        // Permisos disponibles para asignar (solo los que el titular tiene)
        // Los mandamos como colección con ->name para que la vista sea igual a Roles
        $permisos = $titular->getAllPermissions()->sortBy('name');

        return view('Usuarios.UsuarioDependienteCrear', compact('permisos'));
    }

    public function guardarDependiente(Request $request)
{
    $titular = auth()->user();

    $request->validate([
        'nombre' => ['required','string','max:255'],
        'correo' => ['required','email','max:255','unique:users,email'],
        'password' => ['required'],
        'permisos' => ['array'],
        'permisos.*' => ['string'],
    ]);

    // Validar: solo puede asignar permisos que el titular ya tiene
    $permisosSolicitados = collect($request->input('permisos', []));
    $permisosDelTitular  = $titular->getAllPermissions()->pluck('name');

    abort_if($permisosSolicitados->diff($permisosDelTitular)->isNotEmpty(), 403,
        'No puedes asignar permisos que no tienes.'
    );

    $dependiente = new User();
    $dependiente->name = strtoupper($request->input('nombre'));
    $dependiente->email = $request->input('correo');
    $dependiente->password = Hash::make($request->input('password'));

    $dependiente->id_usuario_padre = $titular->id;

    // Hereda licencia (no guardar en hijo)
    $dependiente->license_key = null;
    $dependiente->license_email = null;

    $dependiente->save();

    // Asignar permisos por NOMBRE (Spatie acepta array de strings)
    $dependiente->syncPermissions($permisosSolicitados->toArray());

    return redirect()->route('usuarios.edit', $dependiente->id)
        ->withSuccess('Usuario dependiente creado correctamente.');
}
   
}
