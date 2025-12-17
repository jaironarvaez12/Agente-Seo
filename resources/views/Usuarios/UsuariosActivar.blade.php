<div class="modal fade" id="modal-activar" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header py-16 px-24 border border-top-0 border-start-0 border-end-0">
                        <h1 class="modal-title fs-5" id="exampleModalLabel">Confirmar Accion</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
            <div class="modal-body">
                <h5>¿Desea Activar el Cliente ?</h5>
                <br>
            </div>
            <form id="formactivar" method="POST" action=" {{ route('usuariosactivar',1) }}" data-action=" {{ route('usuariosactivar',1) }}">
                @csrf 
                <div class="modal-footer">
                   
                    <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-40 py-11 radius-8" data-bs-dismiss="modal" >No</button>
                   
                    <button type="submit" class="btn btn-primary border border-primary-600 text-md px-24 py-12 radius-8"> 
                        Si
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
