<?php

namespace App\Jobs;

use App\Models\DominiosModel;
use App\Models\Dominios_Contenido_DetallesModel;
use App\Services\ServicioBacklinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerarBacklinksContenidoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 240;

    public function __construct(public int $idDetalle) {}

    public function handle(ServicioBacklinks $servicio): void
    {
        $it = Dominios_Contenido_DetallesModel::findOrFail($this->idDetalle);
        $dom = DominiosModel::findOrFail($it->id_dominio);

        // ✅ Validaciones mínimas
        if ($it->estatus !== 'publicado') {
            $it->estatus_backlinks = 'error';
            $it->error_backlinks = 'El contenido no está publicado en WordPress.';
            $it->save();
            return;
        }

        if (empty($it->wp_link)) {
            $it->estatus_backlinks = 'error';
            $it->error_backlinks = 'Falta wp_link (URL del post publicado).';
            $it->save();
            return;
        }

        if (empty($it->contenido_html) || mb_strlen(strip_tags($it->contenido_html)) < 50) {
            $it->estatus_backlinks = 'error';
            $it->error_backlinks = 'Contenido vacío o muy corto.';
            $it->save();
            return;
        }

        // ✅ Marcar en proceso
        $it->estatus_backlinks = 'en_proceso';
        $it->error_backlinks = null;
        $it->save();

        // ✅ “Usar keyword” como anchor del backlink:
        // Agregamos un enlace al final del HTML apuntando al post original
        $keyword = trim((string) $it->keyword);
        $textoAnchor = $keyword !== '' ? $keyword : (trim((string)$it->title) !== '' ? $it->title : 'Ver artículo');

        $contenido = (string) $it->contenido_html;
        $contenido .= '<hr><p>Fuente: <a href="' . e($it->wp_link) . '">' . e($textoAnchor) . '</a></p>';

        // domain_id recomendado: host del dominio (sin https://)
        $domainId = parse_url((string) $dom->url, PHP_URL_HOST) ?: null;

        $payload = [
            'id'      => (int) $it->id_dominio_contenido_detalle,
            'url'     => (string) $it->wp_link,
            'title'   => (string) ($it->title ?: ($it->keyword ?: 'Sin título')),
            'content' => $contenido,
        ];

        if ($domainId) {
            $payload['domain_id'] = $domainId;
        }

        $respuesta = $servicio->procesarArticulo($payload);

        // ✅ Guardar respuesta completa + fecha
        $it->estatus_backlinks = 'listo';
        $it->resultado_backlinks = $respuesta;
        $it->fecha_backlinks = now();
        $it->save();
    }

    public function failed(\Throwable $e): void
    {
        $it = Dominios_Contenido_DetallesModel::find($this->idDetalle);
        if ($it) {
            $it->estatus_backlinks = 'error';
            $it->error_backlinks = $e->getMessage();
            $it->save();
        }
    }
}
