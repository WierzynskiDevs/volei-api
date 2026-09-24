<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Events\Domain\Enums\RegistrationFieldKey;
use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Notifications\Application\NotificationDispatcher;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Registrations\Application\DTO\RegistrationData;
use App\Modules\Registrations\Domain\Enums\GroupStatus;
use App\Modules\Registrations\Domain\Enums\RegistrationStatus;
use App\Modules\Registrations\Domain\Exceptions\DuplicateRegistrationException;
use App\Modules\Registrations\Domain\Exceptions\EventFullException;
use App\Modules\Registrations\Domain\Exceptions\EventNotAcceptingRegistrationsException;
use App\Modules\Registrations\Domain\Exceptions\MissingRequiredRegistrationFieldException;
use App\Modules\Registrations\Domain\Exceptions\PartnerNotAvailableException;
use App\Modules\Registrations\Domain\LevelCompatibility;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Registrations\Infrastructure\Models\RegistrationGroup;
use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Criação de inscrição (BRIEF §21, ADR 0001, ADR 0003, aditivo §17).
 *
 * Consumidor: `/inscricao/{slug}` no volei-app, com os três modos que a tela já
 * oferece.
 *
 * Ordem das garantias, e por que é esta ordem:
 *
 *  1. Estado e janela do evento — fora da transação, porque não depende de lock
 *     e falhar cedo evita abrir transação para nada.
 *  2. Parceiro resolvido — idem.
 *  3. Transação com `SELECT ... FOR UPDATE` na linha do evento: duplicidade,
 *     contagem de vaga e insert acontecem todos sob o mesmo lock. É isto que
 *     torna impossível a última vaga sair duas vezes (CLAUDE.md §8).
 *  4. Auditoria depois do commit — auditar dentro da transação registraria
 *     inscrição que pode ser desfeita por rollback.
 *
 * Evento gratuito confirma na hora: não existe pagamento para esperar, e a tela
 * tem o botão "Confirmar inscrição gratuita". Evento pago nasce
 * `PENDING_PAYMENT` com reserva — a confirmação vem do webhook, nunca do retorno
 * do checkout (CLAUDE.md §14).
 */
final readonly class CreateRegistrationAction
{
    public function __construct(
        private EventOccupancy $occupancy,
        private AuditLogger $audit,
        private NotificationDispatcher $notifications,
    ) {}

    public function execute(Event $event, User $actor, RegistrationData $data, CarbonImmutable $now): Registration
    {
        $this->assertEventAcceptsRegistrations($event, $now);
        $this->assertRequiredFieldsPresent($event, $data);

        $partner = $data->partnerMode->requiresPartner()
            ? $this->resolvePartner($data->partnerUserId, $actor)
            : null;

        [$registration, $partnerRegistration] = DB::transaction(
            fn (): array => $this->persist($event, $actor, $partner, $data, $now),
        );

        $this->audit->log(
            action: AuditAction::REGISTRATION_CREATED,
            actor: $actor,
            targetType: 'registration',
            targetId: $registration->id,
            metadata: [
                'event_id' => $event->id,
                'event_slug' => $event->slug,
                'partner_mode' => $data->partnerMode->value,
                'status' => $registration->status->value,
                'level_review' => $registration->level_review->value,
                'group_id' => $registration->group_id,
                'partner_registration_id' => $partnerRegistration?->id,
            ],
        );

        if ($partnerRegistration !== null) {
            $this->notifyPartnerInvited($event, $actor, $partnerRegistration);
        }

        return $registration;
    }

    /**
     * B4 (`docs/PLANO-CONTINUACAO-2026-09.md`). O parceiro sempre tem conta
     * própria com e-mail real — é encontrado por busca (ADR 0015 §"parceiro
     * já precisa ter conta"), nunca convidado por e-mail digitado à mão.
     */
    private function notifyPartnerInvited(Event $event, User $captain, Registration $partnerRegistration): void
    {
        $partnerRegistration->loadMissing('user');
        $partner = $partnerRegistration->user;

        if (! $partner instanceof User) {
            return;
        }

        $this->notifications->queueEmail(
            type: NotificationType::PARTNER_INVITED,
            recipient: $partner,
            subject: "Convite para jogar em dupla — {$event->name}",
            body: "{$captain->name} convidou você para jogar em dupla no evento \"{$event->name}\". "
                .'Acesse "Minhas inscrições" no BeacHub para aceitar ou recusar o convite.',
            metadata: ['event_id' => $event->id, 'registration_id' => $partnerRegistration->id],
        );
    }

    /**
     * Estado + janela declarada. As duas coisas valem: o status é a máquina de
     * estados, a janela é dado do evento (ADR 0002). Um evento que ficou
     * `REGISTRATION_OPEN` mas cujo `registration_close_at` já passou não pode
     * continuar vendendo vaga — o prazo foi publicado ao atleta.
     */
    private function assertEventAcceptsRegistrations(Event $event, CarbonImmutable $now): void
    {
        if (! $event->status->acceptsRegistrations()) {
            throw EventNotAcceptingRegistrationsException::inStatus($event->status);
        }

        if ($event->registration_open_at !== null && $event->registration_open_at->greaterThan($now)) {
            throw EventNotAcceptingRegistrationsException::windowNotOpen();
        }

        if ($event->registration_close_at !== null && $event->registration_close_at->lessThanOrEqualTo($now)) {
            throw EventNotAcceptingRegistrationsException::windowClosed();
        }
    }

    /**
     * Q19/ADR 0016. Validação de ESTADO (depende da configuração do evento),
     * por isso vive na action e não no FormRequest (CLAUDE.md §4.3) — mesmo
     * raciocínio de `assertEventAcceptsRegistrations`.
     */
    private function assertRequiredFieldsPresent(Event $event, RegistrationData $data): void
    {
        $missing = [];

        foreach ($event->requiredRegistrationFieldKeys() as $field) {
            $present = match ($field) {
                RegistrationFieldKey::EMERGENCY_CONTACT => $data->emergencyContactName !== null
                    && $data->emergencyContactPhone !== null,
                RegistrationFieldKey::SHIRT_SIZE => $data->shirtSize !== null,
                RegistrationFieldKey::TEAM_NAME => $data->teamName !== null,
                RegistrationFieldKey::DIETARY_RESTRICTION => $data->dietaryRestriction !== null,
            };

            if (! $present) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw MissingRequiredRegistrationFieldException::forFields($missing);
        }
    }

    /**
     * Só grava o que o evento realmente coleta (Q19/ADR 0016) — nunca o
     * cliente decide isso, e nunca um campo pessoal do capitão vaza para a
     * inscrição do parceiro.
     *
     * @return array<string, mixed>
     */
    private function registrationFieldValues(Event $event, RegistrationData $data, bool $applyPersonalFields): array
    {
        $values = [];

        if ($applyPersonalFields && $event->collectsRegistrationField(RegistrationFieldKey::EMERGENCY_CONTACT)) {
            $values['emergency_contact_name'] = $data->emergencyContactName;
            $values['emergency_contact_phone_encrypted'] = $data->emergencyContactPhone;
        }

        if ($applyPersonalFields && $event->collectsRegistrationField(RegistrationFieldKey::SHIRT_SIZE)) {
            $values['shirt_size'] = $data->shirtSize?->value;
        }

        if ($applyPersonalFields && $event->collectsRegistrationField(RegistrationFieldKey::DIETARY_RESTRICTION)) {
            $values['dietary_restriction'] = $data->dietaryRestriction;
        }

        // Nome do time é da dupla, não da pessoa — aplica às duas inscrições.
        if ($event->collectsRegistrationField(RegistrationFieldKey::TEAM_NAME)) {
            $values['team_name'] = $data->teamName;
        }

        return $values;
    }

    private function resolvePartner(?string $partnerUserId, User $actor): User
    {
        if ($partnerUserId === null || $partnerUserId === '') {
            throw PartnerNotAvailableException::notFound();
        }

        if ($partnerUserId === $actor->id) {
            throw PartnerNotAvailableException::isSelf();
        }

        $partner = User::query()->find($partnerUserId);

        if (! $partner instanceof User) {
            throw PartnerNotAvailableException::notFound();
        }

        if ($partner->status !== UserStatus::ACTIVE) {
            throw PartnerNotAvailableException::blocked();
        }

        return $partner;
    }

    /**
     * @return array{0: Registration, 1: Registration|null}
     */
    private function persist(
        Event $event,
        User $actor,
        ?User $partner,
        RegistrationData $data,
        CarbonImmutable $now,
    ): array {
        $event = $this->occupancy->lock($event->id);

        if ($this->hasActiveRegistration($event, $actor->id)) {
            throw DuplicateRegistrationException::forUser($event->slug);
        }

        if ($partner !== null && $this->hasActiveRegistration($event, $partner->id)) {
            throw DuplicateRegistrationException::forPartner($partner->name);
        }

        /*
         * Uma dupla consome UMA vaga, mesmo nascendo com duas inscrições — é
         * por isso que a checagem pede 1 e não `count($registrations)`.
         */
        if (! $this->occupancy->hasRoomFor($event, $now)) {
            throw EventFullException::forEvent($event->slug, (int) $event->max_teams);
        }

        $group = $partner !== null
            ? RegistrationGroup::query()->create(['event_id' => $event->id])
            : null;

        $registration = $this->buildRegistration(
            event: $event,
            user: $actor,
            group: $group,
            isCaptain: $group !== null,
            data: $data,
            now: $now,
            // O parceiro só entra em PENDING_ACCEPTANCE; quem se inscreveu segue
            // o fluxo normal de pagamento (ou confirma, se for gratuito).
            status: $this->initialStatusFor($event),
            applyPersonalFields: true,
        );

        $partnerRegistration = null;

        if ($partner !== null && $group !== null) {
            $partnerRegistration = $this->buildRegistration(
                event: $event,
                user: $partner,
                group: $group,
                isCaptain: false,
                data: $data,
                now: $now,
                status: RegistrationStatus::PENDING_ACCEPTANCE,
                /*
                 * Q19/ADR 0016: contato de emergência, camiseta e restrição
                 * alimentar são PESSOAIS — quem digitou foi o capitão, sobre
                 * si mesmo. Copiar para a inscrição do parceiro seria atribuir
                 * a ele o tamanho de camiseta de outra pessoa. O parceiro
                 * preenche os próprios dados ao aceitar o convite
                 * (`AcceptRegistrationInvitationAction` — pendência registrada,
                 * ainda não recebe estes campos).
                 */
                applyPersonalFields: false,
            );
        }

        $this->occupancy->syncCounter($event, $now);

        return [$registration, $partnerRegistration];
    }

    /**
     * Duplicidade sob lock. A unique parcial no banco é a última linha de
     * defesa; esta checagem existe para devolver 409 com código estável em vez
     * de deixar estourar violação de constraint como erro 500.
     */
    private function hasActiveRegistration(Event $event, string $userId): bool
    {
        return Registration::query()
            ->where('event_id', $event->id)
            ->where('user_id', $userId)
            ->whereIn('status', RegistrationStatus::blockingValues())
            ->exists();
    }

    private function initialStatusFor(Event $event): RegistrationStatus
    {
        return $event->isFree()
            ? RegistrationStatus::CONFIRMED
            : RegistrationStatus::PENDING_PAYMENT;
    }

    private function buildRegistration(
        Event $event,
        User $user,
        ?RegistrationGroup $group,
        bool $isCaptain,
        RegistrationData $data,
        CarbonImmutable $now,
        RegistrationStatus $status,
        bool $applyPersonalFields,
    ): Registration {
        $registration = new Registration;

        $registration->fill([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'group_id' => $group?->id,
            'is_captain' => $isCaptain,
            'partner_mode' => $data->partnerMode->value,
            // Snapshot: o atleta pode editar o nível depois (aditivo §16) e isso
            // não pode reescrever a base da decisão do organizador.
            'player_level' => $user->level?->value,
            'event_level' => $event->level_category->value,
            'accepted_rules_version' => $data->acceptedRules
                ? (string) config('saque.terms_version')
                : null,
            ...$this->registrationFieldValues($event, $data, $applyPersonalFields),
        ]);

        $registration->status = $status;

        /*
         * Análise de nível (aditivo §17). Não bloqueia nada: a inscrição segue
         * para pagamento em paralelo, exatamente como a tela promete ao atleta.
         */
        $registration->level_review = LevelCompatibility::evaluate($user->level, $event->level_category);

        if ($status === RegistrationStatus::CONFIRMED) {
            $registration->confirmed_at = $now;
        }

        if ($status === RegistrationStatus::PENDING_PAYMENT) {
            $registration->reserved_until = $now->addMinutes(
                (int) config('saque.payments.reservation_ttl_minutes'),
            );
        }

        try {
            $registration->save();
        } catch (UniqueConstraintViolationException) {
            // Duas requisições passaram pela checagem no mesmo instante e o
            // unique parcial pegou. Traduz para o mesmo 409 do caminho normal.
            throw DuplicateRegistrationException::forUser($event->slug);
        }

        if ($group !== null) {
            $group->status = GroupStatus::FORMING;
            $group->save();
        }

        $registration->setRelation('user', $user);
        $registration->setRelation('event', $event);

        return $registration;
    }
}
