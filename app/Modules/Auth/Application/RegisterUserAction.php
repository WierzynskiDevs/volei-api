<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Auth\Application\DTO\RegisterUserData;
use App\Modules\Auth\Domain\Exceptions\EmailAlreadyRegisteredException;
use App\Modules\Auth\Domain\Exceptions\PhoneAlreadyRegisteredException;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Domain\Services\PhoneProtector;
use App\Modules\Users\Infrastructure\Models\User;
use App\Modules\Users\Infrastructure\Models\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro de usuário (BRIEF §15).
 *
 * Invariantes garantidas aqui:
 *  - CPF não é coletado (LGPD, minimização).
 *  - Telefone nunca é gravado em claro: encrypted + hash (BRIEF §16).
 *  - Papel inicial é sempre PLAYER — papel enviado pelo cliente é ignorado
 *    (CLAUDE.md §10). SUPER_ADMIN nunca é auto-atribuível.
 *  - Consentimento é gravado com versão (evidência de finalidade).
 */
final readonly class RegisterUserAction
{
    public function __construct(
        private PhoneProtector $phoneProtector,
        private AuditLogger $audit,
    ) {}

    public function execute(RegisterUserData $data): User
    {
        $phoneHash = $data->phone !== null
            ? $this->phoneProtector->hash($data->phone)
            : null;

        try {
            $user = DB::transaction(function () use ($data, $phoneHash): User {
                $user = User::create([
                    'name' => $data->name,
                    'email' => $data->email,
                    'password' => $data->password,   // cast 'hashed' faz o hash
                    'phone_encrypted' => $data->phone !== null
                        ? $this->phoneProtector->normalize($data->phone)
                        : null,
                    'phone_hash' => $phoneHash,
                    'birth_date' => $data->birthDate,
                    'level' => $data->level,
                    'city' => $data->city,
                    'state' => $data->state,
                    'terms_accepted_at' => $data->acceptedAt,
                    'terms_version' => $data->termsVersion,
                    'privacy_accepted_at' => $data->acceptedAt,
                    'privacy_version' => $data->privacyVersion,
                ]);

                $user->forceFill(['status' => UserStatus::ACTIVE])->save();

                UserRole::create([
                    'user_id' => $user->id,
                    'role' => Role::default(),
                ]);

                return $user->load('roles');
            });
        } catch (QueryException $e) {
            // A unique constraint é a garantia real (CLAUDE.md §6): a checagem
            // prévia do FormRequest é UX e sofre de race condition entre dois
            // cadastros simultâneos com o mesmo e-mail.
            throw $this->translateUniqueViolation($e, $data);
        }

        $this->audit->log(
            action: AuditAction::USER_CREATED,
            actor: $user,
            targetType: 'user',
            targetId: $user->id,
            metadata: ['email' => $user->email, 'role' => Role::default()->value],
        );

        return $user;
    }

    private function translateUniqueViolation(QueryException $e, RegisterUserData $data): \Throwable
    {
        $message = $e->getMessage();

        if (str_contains($message, 'users_email_unique')) {
            return EmailAlreadyRegisteredException::for($data->email);
        }

        if (str_contains($message, 'users_phone_hash_unique')) {
            return PhoneAlreadyRegisteredException::create();
        }

        return $e;
    }
}
