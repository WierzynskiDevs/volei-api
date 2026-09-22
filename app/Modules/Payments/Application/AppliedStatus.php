<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

/**
 * Resultado de aplicar um estado do gateway a um pagamento.
 *
 * Existe para que o job de webhook consiga distinguir "não fiz nada porque já
 * estava feito" de "não fiz nada porque falhou". Um booleano fundiria os dois e
 * o segundo caso viraria alerta falso — ou, pior, o primeiro viraria
 * reprocessamento em loop.
 */
enum AppliedStatus
{
    /** Estado mudou. */
    case APPLIED;

    /** Já estava nesse estado — reentrega de webhook, absorvida. */
    case UNCHANGED;

    /** Estado igual, mas a taxa do gateway foi preenchida agora. */
    case ENRICHED;

    /**
     * Transição inválida por evento atrasado.
     *
     * O Asaas não garante ordem: um `CONFIRMED` pode chegar depois de um
     * `RECEIVED`. Não é erro — é evento já superado, e tratar como falha faria
     * a fila do webhook ser penalizada por comportamento normal do gateway.
     */
    case OUT_OF_ORDER;

    /** Nada a fazer no domínio. */
    public function isNoChange(): bool
    {
        return $this !== self::APPLIED;
    }

    /** Precisa de atenção humana? Nenhum destes precisa. */
    public function isFailure(): bool
    {
        return false;
    }
}
