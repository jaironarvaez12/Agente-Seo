//LLENA EL SELECT DE TIENDAS





function CargarTablaTiendas() {
    var dominio = $("#id_dominio option:selected").text();
    var iddominio =$('#id_dominio').val();

    
    if(iddominio== 0 || dominio=='')
        {
            alert('DEBE SELECCIONAR UN DOMINIO');
            return; 
        }
    var ListaDominio = CapturarDatosTabla();
   
    
    for (var i = 0; i < ListaDominio.length; i++) {
    
      let Id_DOMINIO = String(ListaDominio[i].id_dominio).trim();
  

        if (iddominio == Id_DOMINIO ) {
            alert('EL DOMINIO YA ESTA CARGADO, DEBE SELECCIONAR OTRO');
            return; 
        }
    }


        $("#tabla_dominios>tbody").append(
            "<tr>"
            + "<td id='id_dominio_usuario' style='display: none'>" +' ' + "</td>"
            + "<td id='id_dominio' style='display: none'>" + iddominio + "</td>"
            + "<td id='nombre'>" +dominio.split('-')[0] + "</td>"
            + "<td id='url'>" + dominio.split('-')[1] + "</td>"
          
          
            + "<th><button type='button' class='remove-item-btn bg-danger-focus bg-hover-danger-200 text-danger-600 fw-medium w-40-px h-40-px d-flex justify-content-center align-items-center rounded-circle borrar'> <iconify-icon icon='fluent:delete-24-regular' class='menu-icon'></iconify-icon></button></th></tr>"
        );
   

 
       
    // Actualiza los datos después de agregar un nuevo vehículo o equipo
    
}




//OBTENER DATOS DE LA TABLA 
function CapturarDatosTabla()
{
    let lista_dominios = [];
    
    document.querySelectorAll('#tabla_dominios tbody tr').forEach(function(e){
        let fila = {
            id_dominio_usuario: e.querySelector('#id_dominio_usuario').innerText,
            id_dominio: e.querySelector('#id_dominio').innerText,
            url: e.querySelector('#url').innerText,
            nombre: e.querySelector('#nombre').innerText,
            
        };

        lista_dominios.push(fila);
    });

    $('#datos_tiendas').val(JSON.stringify(lista_dominios)); //PASA DATOS DE LA TABLA A CAMPO OCULTO APRA ENVIAR POR POST
    console.log(lista_dominios)

    return lista_dominios;

}
$(document).on('click', '.borrar', function(event) {
    event.preventDefault();
    $(this).closest('tr').remove();
});
function EliminarDominios(id)
{
    $.ajax({
        // dataType: "JSON",
        url: eliminardominio+'/'+id,
        type: 'post',
        data:
        {
            _token: $("input[name=_token]").val(),
            _method: 'delete'
        },
        success: function(data)
        {
            console.log("eliminado");
        },
        error: function (data) {
            console.log('Error:', data);
        }
    });
}