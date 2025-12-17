<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Política de Privacidad</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Si quieres, puedes linkear solo el CSS principal --}}
    {{-- <link rel="stylesheet" href="{{ asset('css/app.css') }}"> --}}
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 0 16px;
            line-height: 1.6;
        }
        h1, h2, h3, h4, h5 {
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <h1>Política de Privacidad</h1>
    <p>Última actualización: {{ date('d/m/Y') }}</p>

    <h3>1. Responsable del tratamiento</h3>
    <p>
        Nombre: TU NOMBRE O EMPRESA<br>
        Email de contacto: contacto@tudominio.com
    </p>

    <h3>2. Datos que recibimos desde Meta</h3>
    <p>
        Cuando conectas tu cuenta de Facebook o Instagram, podemos recibir datos como:
        ID de página, nombre de página, tokens de acceso y estadísticas básicas de publicaciones.
        No accedemos a tu contraseña.
    </p>

    <h3>3. Uso de la información</h3>
    <ul>
        <li>Publicar contenido en tus páginas o cuentas conectadas.</li>
        <li>Programar publicaciones.</li>
        <li>Mostrar estadísticas básicas.</li>
    </ul>

    <h3>4. Conservación y seguridad</h3>
    <p>
        Los datos se almacenan de forma segura y solo durante el tiempo necesario para 
        prestar el servicio. Aplicamos medidas razonables para proteger la información.
    </p>

    <h3>5. Compartición de datos</h3>
    <p>
        No vendemos ni compartimos tus datos con terceros, salvo obligación legal
        o para cumplir las condiciones de las plataformas de Meta.
    </p>

    <h3>6. Derechos del usuario</h3>
    <p>
        Puedes solicitar acceso, rectificación o eliminación de tus datos escribiendo a:
        contacto@tudominio.com.
    </p>

    <h3>7. Cambios en esta política</h3>
    <p>
        Podemos actualizar esta política ocasionalmente. Cualquier cambio se publicará en esta página.
    </p>
</body>
</html>