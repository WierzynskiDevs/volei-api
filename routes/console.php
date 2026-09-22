<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Console\ReconcilePaymentsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Reconciliação de pagamentos (CLAUDE.md §14).
 *
 * A cada 15 minutos: a doc do Asaas diz que a fila de webhooks pode ser
 * interrompida após 15 falhas consecutivas e que eventos ficam retidos por 14
 * dias (docs/asaas.md §4). Existe, portanto, um cenário documentado em que o
 * gateway para de avisar — e sem isto um atleta pagaria e ficaria sem vaga.
 *
 * `withoutOverlapping`: duas execuções simultâneas consultariam as mesmas
 * cobranças e gastariam quota (25.000 requisições/12h) em dobro.
 */
Schedule::command(ReconcilePaymentsCommand::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
