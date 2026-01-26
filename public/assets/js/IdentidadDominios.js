function CapturarDatosTabla() {
  dominios = []; // si articulos es global; si no, declara let articulos = []

  document.querySelectorAll('#tabla_identidad tbody tr').forEach(function(e, index) {

    let fila = {
      id_dominio: e.querySelector('#id_dominio').innerText.trim(),
      nombre_dominio: e.querySelector('#nombre_dominio').innerText.trim(),
  
      direccion: e.querySelector('.direccion').value.trim(),

      color: e.querySelector('.color_texto').value.trim()   // <-- del hidden de esa fila
    };

    dominios.push(fila);
  });

  document.getElementById('datos').value = JSON.stringify(dominios);
   console.log(JSON.stringify(dominios));
}