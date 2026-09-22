<?php

declare(strict_types=1);

namespace App\Modules\Users\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Domain\Exceptions\CannotBlockSelfException;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Bloqueio e desbloqueio de conta (CLAUDE.md §28).
 *
 * Vive em `Users` e não em `Administration` porque `users.status` é do módulo
 * Users: quem é dono do dado é dono da regra (ADR 0010 §2). Administration
 * chama esta action; ela é a única porta que escreve o status.
 *
 * `status` está **fora** do `$fillable` do model justamente para que não exista
 * um segundo caminho — nenhum update de perfil consegue suspender ninguém.
 *
 * ## Por que a justificativa é obrigatória
 *
 * `AuditAction::requiresJustification()` já listava `USER_BLOCKED` antes de
 * existir quem bloqueasse. Suspender conta é ação que a pessoa afetada tem
 * direito de contestar, e trilha sem motivo não sustenta contestação.
 */
final readonly class ChangeUserStatusAction
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  User  $actor  quem está executando — sempre o super admin logado
     * @param  string  $reason  justificativa, obrigatória e já validada no FormRequest
     */
    public function execute(User $target, User $actor, UserStatus $status, string $reason): User
    {
        /*
         * Trancar a própria conta deixaria a plataforma sem acesso global e sem
         * caminho de volta pela aplicação — só por SQL direto no banco.
         */
        if ($status === UserStatus::BLOCKED && $target->id === $actor->id) {
            throw CannotBlockSelfException::forUser($actor->id);
        }

        $from = $target->status;

        // Já está no estado pedido: não é erro, mas também não gera trilha nova.
        // Repetir o clique não pode produzir dois registros de bloqueio.
        if ($from === $status) {
            return $target;
        }

        DB::transaction(function () use ($target, $status): void {
            $target->status = $status;
            $target->save();
        });

        /*
         * Não há token a revogar aqui: a autenticação é por cookie de sessão
         * (ADR 0005) e o model não usa `HasApiTokens`. Quem encerra a sessão
         * aberta é o middleware `EnsureAccountIsActive`, que recusa o request
         * seguinte e invalida a sessão no caminho (ADR 0010 §3).
         *
         * Se um dia entrar Bearer token, é aqui que a revogação precisa ser
         * acrescentada — bloquear sem revogar deixaria o token valendo.
         */

        $this->audit->log(
            action: $status === UserStatus::BLOCKED
                ? AuditAction::USER_BLOCKED
                : AuditAction::USER_UNBLOCKED,
            actor: $actor,
            targetType: 'user',
            targetId: $target->id,
            metadata: [
                'from' => $from->value,
                'to' => $status->value,
                'reason' => $reason,
            ],
        );

        return $target;
    }
}
