<?php

namespace App\Jobs;

use App\Models\SeoReport;
use App\Models\SeoReportSection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeSeoReportJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(public int $reportId) {}

    public function handle(): void
    {
        try {
            Log::info('Finalize corriendo', ['report_id' => $this->reportId]);

            $report = SeoReport::findOrFail($this->reportId);

            $required = ['moz', 'tech'];

            $sections = SeoReportSection::where('seo_report_id', $report->id)
                ->get()
                ->keyBy('section');

            $missing = [];
            $failed = [];

            foreach ($required as $sec) {
                if (!$sections->has($sec)) {
                    $missing[] = $sec;
                    continue;
                }
                if ($sections[$sec]->status !== 'ok') {
                    $failed[] = $sec;
                }
            }

            if (!empty($missing)) {
                $report->status = 'error';
                $report->error_message = 'Faltan secciones requeridas: ' . implode(', ', $missing) . '. Revisa failed jobs / worker.';
                $report->save();
                return;
            }

            if (!empty($failed)) {
                $msg = [];
                foreach ($failed as $sec) {
                    $s = $sections[$sec];
                    $msg[] = "Falló {$sec}" . ($s->error_message ? " ({$s->error_message})" : '');
                }
                $report->status = 'error';
                $report->error_message = implode(' | ', $msg);
                $report->save();
                return;
            }

            // ✅ pagespeed puede fallar, no importa
            $report->status = 'ok';
            $report->error_message = null;
            $report->save();

        } catch (Throwable $e) {
            Log::error('Finalize error', ['report_id' => $this->reportId, 'message' => $e->getMessage()]);

            // Último recurso: no dejar generando
            $report = SeoReport::find($this->reportId);
            if ($report) {
                $report->status = 'error';
                $report->error_message = 'Finalize error: ' . $e->getMessage();
                $report->save();
            }
            return;
        }
    }
}
