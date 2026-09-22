<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Console;

use App\Modules\Payments\Application\ReconcilePaymentsAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Reconciliação periódica de pagamentos (CLAUDE.md §14).
 *
 * Comando **sem regra de negócio própria** — chama a action (CLAUDE.md §5). Ele
 * existe só para dar um gatilho agendável ao que já está em `Application`.
 */
final class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile {--limit=200 : máximo de cobranças por execução}';

    protected $description = 'Compara o estado local das cobranças abertas com o gateway e alerta divergências';

    public function handle(ReconcilePaymentsAction $action): int
    {
        $result = $action->execute(
            now: CarbonImmutable::now(),
            limit: (int) $this->option('limit'),
        );

        $this->info(sprintf(
            'Reconciliação: %d verificadas, %d divergentes corrigidas, %d falhas de consulta.',
            $result['checked'],
            $result['diverged'],
            $result['failed'],
        ));

        /*
         * Código de saída 1 quando houve divergência: permite que o agendador
         * ou o CI trate "webhook não está chegando" como condição de alerta, em
         * vez de esconder num log que ninguém lê.
         */
        return $result['diverged'] > 0 || $result['failed'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
