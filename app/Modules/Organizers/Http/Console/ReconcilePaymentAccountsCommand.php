<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Http\Console;

use App\Modules\Organizers\Application\ReconcilePaymentAccountsAction;
use Illuminate\Console\Command;

/**
 * Reconciliação periódica de subcontas pendentes (ADR 0018 §6).
 *
 * Comando sem regra própria — só o gatilho agendável da action
 * (CLAUDE.md §5).
 */
final class ReconcilePaymentAccountsCommand extends Command
{
    protected $signature = 'organizers:reconcile-payment-accounts {--limit=100 : máximo de organizadores por execução}';

    protected $description = 'Consulta o gateway pelas subcontas PENDING e move para LINKED quando aprovadas';

    public function handle(ReconcilePaymentAccountsAction $action): int
    {
        $result = $action->execute(limit: (int) $this->option('limit'));

        $this->info(sprintf(
            'Reconciliação de contas: %d verificadas, %d vinculadas, %d falhas de consulta.',
            $result['checked'],
            $result['linked'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
