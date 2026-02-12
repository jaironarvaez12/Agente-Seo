<!-- Theme Customization Structure End -->
<style>
/* Fondo y borde como btn-primary para el contenedor */
.panel-primary{
  --skin-bg: var(--bs-primary, #0d6efd);
  background-color: var(--skin-bg);
  border: 1px solid var(--skin-bg);
  border-radius: .5rem; /* por si radius-8 no existe */
  color: #fff;
}

/* Label en blanco sobre fondo primario */
.panel-primary label{ color:#fff; }

/* Select legible sobre fondo primario (blanco) */
.select-on-primary{
  background-color:#fff;
  color:#000000;
  border-color: #fff;
}
.select-on-primary:focus{
  border-color:#fff;
    background-color:#fff  !important;
  box-shadow: 0 0 0 .25rem rgba(13,110,253,.25);
}
.select-on-primary:focus{
  border-color:#fff;
    background-color:#fff  !important;
    color:#000000;
  box-shadow:0 0 0 .25rem rgba(13,110,253,.25);

}
/* ===== Estilo del MENU desplegado (options) ===== */
/* Fondo azul y texto blanco para todas las opciones */
.form-select.select-on-primary option,
.form-select.select-on-primary optgroup{
  background-color:#fff;
  color:#000000;
}


/* Opción resaltada/seleccionada con un azul un poco más oscuro */
.form-select.select-on-primary option:hover,
.form-select.select-on-primary option:checked,
.form-select.select-on-primary option:focus {
  background-color:#fff  !important;
  color:#fff !important;
}

/* Solo submenú anidado */
.sidebar-submenu li.dropdown > ul.sidebar-submenu { display: none; }
.sidebar-submenu li.dropdown.open > ul.sidebar-submenu { display: block; }
</style>
<aside class="sidebar">
  <button type="button" class="sidebar-close-btn">
    <iconify-icon icon="radix-icons:cross-2"></iconify-icon>
  </button>
  <div>
    <a href="{{ url('/') }}" class="sidebar-logo">
      <img src="{{ asset('assets/images/IDEIWEB_300x110_ajustado.png') }}" alt="site logo" class="light-logo">
      <img src="{{ asset('assets/images/logo-light.png') }}" alt="site logo" class="dark-logo">
      <img src="{{ asset('assets/images/logo-ico.png') }}" alt="site logo" class="logo-icon">

    </a>
  </div>
   {{-- Selector de tienda del usuario --}}
  
  <div class="sidebar-menu-area">
    <ul class="sidebar-menu" id="sidebar-menu">
      {{-- <li class="dropdown">
        <a href="javascript:void(0)">
          <iconify-icon icon="solar:home-smile-angle-outline" class="menu-icon"></iconify-icon>
          <span>Dashboard</span>
        </a>
        <ul class="sidebar-submenu">
          <li>
            <a href="index.html"><i class="ri-circle-fill circle-icon text-primary-600 w-auto"></i> AI</a>
          </li>
          <li>
            <a href="index-2.html"><i class="ri-circle-fill circle-icon text-warning-main w-auto"></i> CRM</a>
          </li>
          <li>
            <a href="index-3.html"><i class="ri-circle-fill circle-icon text-info-main w-auto"></i> eCommerce</a>
          </li>
          <li>
            <a href="index-4.html"><i class="ri-circle-fill circle-icon text-danger-main w-auto"></i> Cryptocurrency</a>
          </li>
          <li>
            <a href="index-5.html"><i class="ri-circle-fill circle-icon text-success-main w-auto"></i> Investment</a>
          </li>
          <li>
            <a href="index-6.html"><i class="ri-circle-fill circle-icon text-purple w-auto"></i> LMS</a>
          </li>
          <li>
            <a href="index-7.html"><i class="ri-circle-fill circle-icon text-info-main w-auto"></i> NFT & Gaming</a>
          </li>
          <li>
            <a href="index-8.html"><i class="ri-circle-fill circle-icon text-danger-main w-auto"></i> Medical</a>
          </li>
          <li>
            <a href="index-9.html"><i class="ri-circle-fill circle-icon text-purple w-auto"></i> Analytics</a>
          </li>
          <li>
            <a href="index-10.html"><i class="ri-circle-fill circle-icon text-warning-main w-auto"></i> POS & Inventory
            </a>
          </li>
          <li>
            <a href="index-11.html"><i class="ri-circle-fill circle-icon text-success-main w-auto"></i> Finance &
              Banking </a>
          </li>
          <li>
            <a href="index-12.html"><i class="ri-circle-fill circle-icon text-danger-main w-auto"></i> Booking
              System</a>
          </li>
          <li>
            <a href="index-13.html"><i class="ri-circle-fill circle-icon text-info-main w-auto"></i> Help Desk</a>
          </li>
          <li>
            <a href="index-14.html"><i class="ri-circle-fill circle-icon text-warning-main w-auto"></i> Podcast </a>
          </li>
          <li>
            <a href="index-15.html"><i class="ri-circle-fill circle-icon text-purple w-auto"></i> Project Management
            </a>
          </li>
          <li>
            <a href="index-16.html"><i class="ri-circle-fill circle-icon text-success-main w-auto"></i> Call Center</a>
          </li>
          <li>
            <a href="index-17.html"><i class="ri-circle-fill circle-icon text-danger-main w-auto"></i> Sass</a>
          </li>
        </ul>
      </li> --}}
    

     
      
      
     
   
   
      
      <li class="dropdown">
        <a href="javascript:void(0)">
          <iconify-icon icon="flowbite:users-group-outline" class="menu-icon"></iconify-icon>
          <span>Usuarios</span>
        </a>
        <ul class="sidebar-submenu">
          <li>
            <a href="{{ route('usuarios.index') }}"><i class="ri-circle-fill circle-icon text-primary-600 w-auto"></i> Lista de Usuarios</a>
          </li>
          <li>
            <a href="{{ route('roles.index') }}"><i class="ri-circle-fill circle-icon text-primary-600 w-auto"></i> Roles</a>
          </li>
          <li>
            <a href="{{ route('permisos.index') }}"><i class="ri-circle-fill circle-icon text-primary-600 w-auto"></i> Permisos</a>
          </li>
         
         


          {{-- <li>
            <a href="users-grid.html"><i class="ri-circle-fill circle-icon text-warning-main w-auto"></i> Users Grid</a>
          </li>
          <li>
            <a href="add-user.html"><i class="ri-circle-fill circle-icon text-info-main w-auto"></i> Add User</a>
          </li>
          <li>
            <a href="view-profile.html"><i class="ri-circle-fill circle-icon text-danger-main w-auto"></i> View
              Profile</a>
          </li>
          <li>
            <a href="users-role-permission.html"><i class="ri-circle-fill circle-icon text-info-main w-auto"></i> User
              Role & Permission</a>
          </li> --}}
        </ul>
      </li>
      {{-- <li>
        <a href="{{ route('perfiles.index') }}">
            <iconify-icon icon="flowbite:users-group-outline" class="menu-icon"></iconify-icon>
            <span>Perfiles</span>
        </a>
      </li> --}}
     
      <li class="dropdown">
        <a href="#" class="submenu-toggle">
          <iconify-icon icon="flowbite:users-group-outline" class="menu-icon"></iconify-icon>
          <span>Seo</span>
        </a>
        

        <ul class="sidebar-submenu">
           <li>
            <a href="{{ route('dashboardseo') }}"><i class="ri-circle-fill circle-icon text-primary-600 w-auto"></i>Dashboard</a>
          </li>
          <li class="dropdown">
            <a href="#" class="submenu-toggle">
              <iconify-icon icon="flowbite:users-group-outline" class="menu-icon"></iconify-icon>
              <span>Dominios</span>
            </a>

            <ul class="sidebar-submenu">
              <li>
                <a href="{{ route('dominios.index') }}">
                  <i class="ri-circle-fill circle-icon text-primary-600 w-auto"></i>
                  Lista de Dominios
                </a>
              </li>
              <li>
                <a href="{{ route('dominiosidentidad') }}">
                  <i class="ri-circle-fill circle-icon text-primary-600 w-auto"></i>
                  Identidad de Dominios
                </a>
              </li>
            </ul>
          </li>
        </ul>
      </li>
      <li class="dropdown">
        <a href="javascript:void(0)">
          <iconify-icon icon="flowbite:users-group-outline" class="menu-icon"></iconify-icon>
          <span>Backlinks</span>
        </a>
        <ul class="sidebar-submenu">
          {{-- <li>
            <a href="{{ route('usuarios.index') }}"><i class="ri-circle-fill circle-icon text-primary-600 w-auto"></i> Lista de Usuarios</a>
          </li> --}}
         
         
         
        </ul>
      </li>
      <li class="dropdown">
        <a href="javascript:void(0)">
          <iconify-icon icon="flowbite:users-group-outline" class="menu-icon"></iconify-icon>
          <span>Configuracion</span>
        </a>
        <ul class="sidebar-submenu">
          <li>
            <a href="{{ route('configuracionprompt') }}"><i class="ri-circle-fill circle-icon text-primary-600 w-auto"></i>Prompt Generador SEO</a>
          </li>
          <li>
            <a href="{{ route('cargarplantillas') }}"><i class="ri-circle-fill circle-icon text-primary-600 w-auto"></i>Cargar Plantilla </a>
          </li>
         
         


          
        </ul>
      </li>

       
 

  

    
    </ul>
  </div>
</aside>
<script>
(() => {
  const isNestedDropdown = (li) =>
    li?.matches("li.dropdown") && li.parentElement?.classList.contains("sidebar-submenu");

  const getSubmenu = (li) => li.querySelector(":scope > ul.sidebar-submenu");

  const setVisible = (ul, visible) => {
    if (!ul) return;
    ul.hidden = !visible;
    ul.style.display = visible ? "block" : "none";
  };

  // Sincroniza: si el LI tiene .open => el UL se muestra, si no => se oculta
  const syncNestedDropdown = (li) => {
    if (!isNestedDropdown(li)) return;
    const ul = getSubmenu(li);
    if (!ul) return;
    const open = li.classList.contains("open");
    setVisible(ul, open);
  };

  const syncAllNested = () => {
    document.querySelectorAll("ul.sidebar-submenu > li.dropdown").forEach(syncNestedDropdown);
  };

  // Estado inicial
  document.addEventListener("DOMContentLoaded", syncAllNested);

  // Click handler (solo nested dropdowns como "Dominios")
  document.addEventListener(
    "click",
    (e) => {
      const toggle = e.target.closest("a.submenu-toggle");
      if (!toggle) return;

      const li = toggle.closest("li.dropdown");
      if (!isNestedDropdown(li)) return;

      const submenu = getSubmenu(li);
      if (!submenu) return;

      // Evita que el template lo cierre o lo deje en estado raro
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      const parentUl = li.parentElement;

      // Cierra hermanos del mismo nivel
      Array.from(parentUl.children).forEach((sib) => {
        if (sib !== li && sib.classList?.contains("dropdown")) {
          sib.classList.remove("open");
          setVisible(getSubmenu(sib), false);
        }
      });

      // Toggle actual
      const willOpen = !li.classList.contains("open");
      li.classList.toggle("open", willOpen);
      setVisible(submenu, willOpen);

      // Mantén ancestros abiertos (Seos)
      let parent = li.parentElement?.closest("li.dropdown");
      while (parent) {
        parent.classList.add("open");
        parent = parent.parentElement?.closest("li.dropdown");
      }

      // 🔑 Re-sync por si el template vuelve a tocar estilos/clases
      queueMicrotask(syncAllNested);
      setTimeout(syncAllNested, 0);
    },
    true
  );

  // Observa cambios de clase en dropdowns anidados para mantener todo sincronizado
  const observer = new MutationObserver((mutations) => {
    for (const m of mutations) {
      if (m.type === "attributes" && m.attributeName === "class") {
        syncNestedDropdown(m.target);
      }
    }
  });

  const observeAll = () => {
    document.querySelectorAll("ul.sidebar-submenu > li.dropdown").forEach((li) => {
      observer.observe(li, { attributes: true, attributeFilter: ["class"] });
      // Asegura estado visible correcto
      syncNestedDropdown(li);
    });
  };

  document.addEventListener("DOMContentLoaded", observeAll);
})();
</script>
