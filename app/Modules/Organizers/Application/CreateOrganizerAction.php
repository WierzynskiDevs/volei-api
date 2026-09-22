<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Organizers\Domain\Exceptions\DuplicateOrganizerDocumentException;
use App\Modules\Organizers\Domain\Exceptions\OrganizerAlreadyExistsException;
use App\Modules\Organizers\Domain\Services\DocumentProtector;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Organizers\Infrastructure\Models\Plan;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Infrastructure\Models\User;
use App\Modules\Users\Infrastructure\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Torna um usuário autenticado em organizador (OPEN-QUESTIONS Q6, resolvida em
 * 21/09/2026: self-service, com CPF/CNPJ obrigatório — ADR 0014).
 *
 * Consumidor: fluxo de onboarding (`POST /organizers`). Um usuário tem no
 * máximo um perfil de organizador — `organizers.user_id` é unique, e esta
 * action recusa antes de tentar a escrita (mensagem melhor que estourar a
 * constraint).
 */
final readonly class CreateOrganizerAction
{
    public function __construct(
        private AuditLogger $audit,
        private DocumentProtector $documents,
    ) {}

    public function execute(
        User $actor,
        string $name,
        string $documentNumber,
        ?string $description = null,
        ?string $contactEmail = null,
        ?string $city = null,
        ?string $state = null,
    ): Organizer {
        $actor->loadMissing('organizer');

        if ($actor->organizer !== null) {
            throw OrganizerAlreadyExistsException::create();
        }

        if (! $this->documents->isValid($documentNumber)) {
            // FormRequest já valida forma; chegar aqui com dígito inválido é
            // sinal de bypass da camada HTTP — falha alto, não silencioso.
            throw new RuntimeException('CPF/CNPJ inválido chegou à action sem passar pela validação de forma.');
        }

        // Normalizado antes de qualquer uso: nunca grava a máscara que o
        // usuário digitou, mesmo padrão do PhoneProtector (E.164).
        $normalizedDocument = $this->documents->normalize($documentNumber);
        $documentHash = $this->documents->hash($normalizedDocument);

        if (Organizer::query()->where('document_number_hash', $documentHash)->exists()) {
            throw DuplicateOrganizerDocumentException::create();
        }

        $plan = Plan::query()
            ->where('code', (string) config('saque.default_plan_code'))
            ->firstOrFail();

        $organizer = DB::transaction(function () use (
            $actor, $name, $normalizedDocument, $documentHash, $description, $contactEmail, $city, $state, $plan,
        ): Organizer {
            $organizer = Organizer::query()->create([
                'user_id' => $actor->id,
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'description' => $description,
                'contact_email' => $contactEmail,
                'city' => $city,
                'state' => $state,
                'plan_id' => $plan->id,
                'document_number_encrypted' => $normalizedDocument,
                'document_number_hash' => $documentHash,
                'document_type' => $this->documents->type($normalizedDocument),
            ]);

            /*
             * `status` e `payment_account_status` têm default só no banco
             * (fora de $fillable de propósito — CLAUDE.md §5, mudança de
             * situação é ação administrativa própria). Sem recarregar, o
             * model em memória fica com esses dois atributos `null` até a
             * próxima leitura — `refresh()` traz o que o banco realmente
             * gravou, em vez de duplicar o valor default em código.
             */
            $organizer->refresh();

            if (! $actor->hasRole(Role::ORGANIZER)) {
                UserRole::query()->create([
                    'user_id' => $actor->id,
                    'role' => Role::ORGANIZER,
                ]);
            }

            return $organizer;
        });

        $this->audit->log(
            action: AuditAction::ORGANIZER_CREATED,
            actor: $actor,
            targetType: 'organizer',
            targetId: $organizer->id,
            metadata: [
                'document_type' => $organizer->document_type?->value,
                // Nunca o documento em si na auditoria (CLAUDE.md §9/§21) — só o tipo.
            ],
        );

        return $organizer;
    }

    /**
     * Mesmo padrão de slug do módulo Events: nome + sufixo aleatório quando
     * colide, nunca id sequencial no slug público (CLAUDE.md §6).
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;

        while (Organizer::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
