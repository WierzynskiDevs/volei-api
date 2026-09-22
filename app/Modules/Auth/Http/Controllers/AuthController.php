<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Enums\AuditAction;
use App\Modules\Auth\Application\RegisterUserAction;
use App\Modules\Auth\Domain\Exceptions\AccountBlockedException;
use App\Modules\Auth\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Modules\Users\Http\Resources\UserResource;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Autenticação por cookie de sessão httpOnly (ADR 0005).
 *
 * Controller magro (CLAUDE.md §4.3): valida, autoriza, chama uma action,
 * devolve Resource. Nenhuma regra de negócio aqui.
 */
final class AuthController
{
    /**
     * Hash bcrypt real (custo 12) de um valor aleatório descartado — nenhuma
     * senha corresponde a ele. Serve para que a verificação rode mesmo quando o
     * e-mail não existe, mantendo o tempo de resposta constante e impedindo que
     * o login vire oráculo de enumeração de contas (timing attack).
     */
    private const string DUMMY_HASH = '$2y$12$4cVbhztOg9M17Caxvprw0OcMRbK8hf6NukvTsxjd6.qJ2dzr5DPCe';

    public function __construct(private readonly AuditLogger $audit) {}

    public function register(RegisterRequest $request, RegisterUserAction $action): JsonResponse
    {
        $user = $action->execute($request->toData());

        // Cadastro já autentica: o baseline leva direto para o onboarding.
        Auth::login($user);
        $request->session()->regenerate();

        return UserResource::make($user->load('roles', 'organizer'))
            ->response()
            ->setStatusCode(201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = User::where('email', $request->string('email')->value())->first();

        /*
         * Hash::check é executado mesmo com usuário inexistente para que o
         * tempo de resposta não denuncie se o e-mail existe (timing attack).
         */
        $passwordMatches = Hash::check(
            (string) $request->string('password'),
            $user instanceof User ? $user->password : self::DUMMY_HASH,
        );

        if (! $user instanceof User || ! $passwordMatches) {
            throw InvalidCredentialsException::create();
        }

        // Só depois de a senha conferir é que revelamos o bloqueio.
        if ($user->isBlocked()) {
            throw AccountBlockedException::create();
        }

        Auth::login($user, remember: $request->boolean('remember'));
        $request->session()->regenerate();

        $this->audit->log(
            action: AuditAction::USER_LOGGED_IN,
            actor: $user,
            targetType: 'user',
            targetId: $user->id,
        );

        return UserResource::make($user->load('roles', 'organizer'))->response();
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            $this->audit->log(
                action: AuditAction::USER_LOGGED_OUT,
                actor: $user,
                targetType: 'user',
                targetId: $user->id,
            );
        }

        // Invalidação server-side: apagar cookie no cliente não encerra sessão.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($user->load('roles', 'organizer'))->response();
    }
}
