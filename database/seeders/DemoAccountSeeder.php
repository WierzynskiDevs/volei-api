<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Organizers\Domain\Enums\OrganizerStatus;
use App\Modules\Organizers\Domain\Enums\PaymentAccountStatus;
use App\Modules\Organizers\Infrastructure\Models\Organizer;
use App\Modules\Organizers\Infrastructure\Models\Plan;
use App\Modules\Users\Domain\Enums\PlayerLevel;
use App\Modules\Users\Domain\Enums\Role;
use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Domain\Services\PhoneProtector;
use App\Modules\Users\Infrastructure\Models\User;
use App\Modules\Users\Infrastructure\Models\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Contas de demonstração — espelham `src/lib/accounts.ts` do baseline.
 *
 * As sete contas do painel "Modo demonstração" da tela de login passam a
 * existir de verdade no banco, com o mesmo nome, e-mail, cidade e papéis. É o
 * que permite trocar o login mock por autenticação real sem mudar o que a
 * pessoa vê ao entrar.
 *
 * Além delas, cria os organizadores que os eventos do mock referenciam por
 * nome (`EventItem.organizer`) — no baseline eles não têm conta nenhuma
 * (docs/DIVERGENCES.md §7).
 *
 * SEGURANÇA: este seeder tem senha conhecida e NUNCA roda em produção — a
 * verificação abaixo é a garantia, não a disciplina de quem executa.
 * Idempotente: a chave é o e-mail, rodar de novo não duplica.
 */
final class DemoAccountSeeder extends Seeder
{
    /**
     * Senha única de todas as contas de demonstração.
     *
     * Atende à política real (mínimo 8, letras e números): as contas semeadas
     * autenticam pelo mesmo caminho que qualquer outra, sem exceção no fluxo
     * de login.
     */
    public const string PASSWORD = 'saque123456';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'DemoAccountSeeder não roda em produção: cria contas com senha conhecida.'
            );
        }

        $now = CarbonImmutable::now();

        // --- Atletas (accounts.ts) ---------------------------------------
        $this->user(
            name: 'Ana Ribeiro',
            email: 'ana@beachub.com',
            phone: '+55 48 99111-1234',
            city: 'Florianópolis',
            state: 'SC',
            level: PlayerLevel::ADVANCED,
            roles: [Role::PLAYER],
            now: $now,
        );

        $this->user(
            name: 'João Mendes',
            email: 'joao@beachub.com',
            phone: '+55 41 98222-4477',
            city: 'Curitiba',
            state: 'PR',
            level: PlayerLevel::INTERMEDIATE,
            roles: [Role::PLAYER],
            now: $now,
        );

        $this->user(
            name: 'Marina Costa',
            email: 'marina@beachub.com',
            phone: '+55 81 99333-8890',
            city: 'Recife',
            state: 'PE',
            level: PlayerLevel::ADVANCED,
            roles: [Role::PLAYER],
            now: $now,
        );

        // Conta SUSPENSA no baseline: existe para exercitar o bloqueio real.
        $this->user(
            name: 'Rafael Prado',
            email: 'rafael@beachub.com',
            phone: '+55 84 98444-2210',
            city: 'Natal',
            state: 'RN',
            level: PlayerLevel::BEGINNER,
            roles: [Role::PLAYER],
            now: $now,
            status: UserStatus::BLOCKED,
        );

        // --- Super admin --------------------------------------------------
        $this->user(
            name: 'Super Admin',
            email: 'admin@beachub.com',
            phone: '+55 11 99555-0000',
            city: 'São Paulo',
            state: 'SP',
            level: null,
            roles: [Role::SUPER_ADMIN],
            now: $now,
        );

        // --- Organizadores -------------------------------------------------
        // Os dois primeiros são contas do painel de demonstração; os demais
        // existem porque os eventos do mock os citam pelo nome.
        $this->organizer(
            organizerName: 'Arena Norte Beach',
            userName: 'Arena Norte Beach',
            email: 'contato@arenanorte.com.br',
            phone: '+55 41 33999-0090',
            city: 'Curitiba',
            state: 'PR',
            planCode: 'PRO',
            now: $now,
        );

        // Conta com DOIS papéis no baseline (ORGANIZER + PLAYER): é ela que
        // faz a tela /escolher-perfil ter razão de existir.
        $this->organizer(
            organizerName: 'Circuito Litoral',
            userName: 'Circuito Litoral',
            email: 'producao@circuitolitoral.com',
            phone: '+55 48 33111-1177',
            city: 'Florianópolis',
            state: 'SC',
            planCode: 'PREMIUM',
            now: $now,
            extraRoles: [Role::PLAYER],
        );

        $this->organizer(
            organizerName: 'Praia Mole Sports',
            userName: 'Praia Mole Sports',
            email: 'contato@praiamolesports.com.br',
            phone: '+55 48 33222-2200',
            city: 'Florianópolis',
            state: 'SC',
            planCode: 'PRO',
            now: $now,
        );

        $this->organizer(
            organizerName: 'Santos Beach Club',
            userName: 'Santos Beach Club',
            email: 'contato@santosbeachclub.com.br',
            phone: '+55 13 33333-3300',
            city: 'Santos',
            state: 'SP',
            planCode: 'FREE',
            now: $now,
        );

        $this->organizer(
            organizerName: 'Duna Beach Arena',
            userName: 'Duna Beach Arena',
            email: 'contato@dunabeach.com.br',
            phone: '+55 84 33444-4400',
            city: 'Natal',
            state: 'RN',
            planCode: 'FREE',
            now: $now,
        );

        $this->organizer(
            organizerName: 'Camburi Beach',
            userName: 'Camburi Beach',
            email: 'contato@camburibeach.com.br',
            phone: '+55 27 33555-5500',
            city: 'Vitória',
            state: 'ES',
            planCode: 'FREE',
            now: $now,
        );
    }

    /**
     * @param  array<int, Role>  $roles
     */
    private function user(
        string $name,
        string $email,
        string $phone,
        string $city,
        string $state,
        ?PlayerLevel $level,
        array $roles,
        CarbonImmutable $now,
        UserStatus $status = UserStatus::ACTIVE,
    ): User {
        $protector = app(PhoneProtector::class);

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => self::PASSWORD,   // o cast 'hashed' faz o hash
                'phone_encrypted' => $protector->normalize($phone),
                'phone_hash' => $protector->hash($phone),
                'birth_date' => null,
                'avatar_path' => null,
                'level' => $level?->value,
                'city' => $city,
                'state' => $state,
                'terms_accepted_at' => $now,
                'terms_version' => (string) config('saque.terms_version'),
                'privacy_accepted_at' => $now,
                'privacy_version' => (string) config('saque.privacy_version'),
            ]);
        }

        // `status` está fora do $fillable de propósito (bloquear conta é ação
        // administrativa auditada, não efeito de update de perfil).
        $user->forceFill(['status' => $status])->save();

        foreach ($roles as $role) {
            UserRole::query()->firstOrCreate(['user_id' => $user->id, 'role' => $role]);
        }

        return $user->load('roles');
    }

    /**
     * @param  array<int, Role>  $extraRoles
     */
    private function organizer(
        string $organizerName,
        string $userName,
        string $email,
        string $phone,
        string $city,
        string $state,
        string $planCode,
        CarbonImmutable $now,
        array $extraRoles = [],
    ): Organizer {
        $user = $this->user(
            name: $userName,
            email: $email,
            phone: $phone,
            city: $city,
            state: $state,
            level: null,
            roles: [Role::ORGANIZER, ...$extraRoles],
            now: $now,
        );

        $planId = Plan::query()->where('code', $planCode)->value('id');

        if ($planId === null) {
            throw new RuntimeException(
                "Plano {$planCode} não existe. Rode o PlanSeeder antes do DemoAccountSeeder."
            );
        }

        $organizer = Organizer::query()->where('user_id', $user->id)->first();

        if (! $organizer instanceof Organizer) {
            $organizer = Organizer::create([
                'user_id' => $user->id,
                'name' => $organizerName,
                'slug' => Str::slug($organizerName),
                'description' => null,
                'contact_email' => $email,
                'contact_phone_encrypted' => $phone,
                'city' => $city,
                'state' => $state,
                'plan_id' => $planId,
            ]);
        }

        /*
         * Conta de recebimento vinculada: sem isto nenhum evento pago poderia
         * ser publicado (docs/OPEN-QUESTIONS.md Q6) e a demonstração pararia
         * na primeira tela. O provider é o fake (ADR 0004) — não existe conta
         * real de gateway por trás deste identificador.
         */
        $organizer->forceFill([
            'status' => OrganizerStatus::REGULAR,
            'payment_account_status' => PaymentAccountStatus::LINKED,
            'payment_account_external_id' => 'fake_'.$organizer->slug,
        ])->save();

        return $organizer;
    }
}
