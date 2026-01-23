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
        $usuario->id_empresa = $request->id_empresa;
     
    
       
       
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

        $usuario = User::find($id);
   
        $permisos = Permission::select('id', 'name')->orderBy('name')->get(); //trae todo el listado de permiso
  
        $roles = Role::select('id', 'name')->get(); //trae los roles para el usuario
          
        $DominiosUsuario = Dominios_UsuariosModel::Dominios($id);
        $dominios = DominiosModel::all();

         return view('Usuarios.UsuariosEdit', compact('usuario','permisos','roles','DominiosUsuario','dominios'));
    }


    public function update(UsuariosUpdateRequest $request, $id)
    {
       //dd($request);
        try
        { 
            $usuario = User::find($id);
             $FechaActual = Carbon::now()->format('Y-m-d H:i:s'); // Obtiene La fecha Actual


            $usuario->fill([
                'name' => strtoupper($request['name']),
                'email' => $request['email'],
          
            
              
             ]);
            // Guardar license_email (si viene)
            $usuario->license_email = $request->input('license_email');

            // Guardar license_key SOLO si el usuario pegó una nueva
            if ($request->filled('license_key')) {
                $usuario->setLicenseKeyPlain($request->input('license_key'));
            }
            $usuario->syncRoles($request->input('roles'));  //Sinronizacion de rol
            //  $usuario->syncPermissions($request->input('permisos', [])); // agregar los permisos 

            if (!empty($request->input('password'))) //enviar vacio la contraseña y no la actualice
            {
                $usuario->password = Hash::make($request->input('password'));
            }

    
            $DatosDominios = json_decode($request['datos_tiendas']); //arreglo de datos adicionales
    
           
             DB::transaction(function () use ($DatosDominios , $usuario,$FechaActual)
             {
                 $usuario->save(); //actualizar usuario

              
                 if ($DatosDominios  != NULL)  //verifica si el arreglo no esta vacio
                 {
                  
                    Dominios_UsuariosModel::where('id_usuario', $usuario->id)->delete();

                
                    foreach ($DatosDominios  as $dominio) {
                        $IdDominioUsuario=  Dominios_UsuariosModel::max('id_dominio_usuario') + 1;
                        Dominios_UsuariosModel::create([

                            'id_dominio_usuario' =>  $IdDominioUsuario,
                            'id_dominio' => $dominio->id_dominio,
                            'id_usuario'=> $usuario->id,
                            'creado_por'=> $usuario->id,
                            'fecha_creacion' => $FechaActual,
                        ]);
                    }
                } 
                
                
            });
        
        }
        catch(Exception $ex)
            {
                return redirect()->back()->withError('Ha Ocurrido Un Error Al Actualizar El Usuario '.$ex->getMessage())->withInput();
            }

        return redirect()->route('usuarios.edit', $id)->withSuccess('El Usuario Se Ha Actualizado Exitosamente');

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
   
}
