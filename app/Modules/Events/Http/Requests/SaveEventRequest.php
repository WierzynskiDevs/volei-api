<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Application\DTO\EventData;
use App\Modules\Events\Domain\Enums\AgeCategory;
use App\Modules\Events\Domain\Enums\EventType;
use App\Modules\Events\Domain\Enums\GenderCategory;
use App\Modules\Events\Domain\Enums\LevelCategory;
use App\Modules\Events\Domain\Enums\Modality;
use App\Modules\Events\Domain\Enums\RegistrationFieldKey;
use App\Modules\Events\Domain\EventTimezone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação de criação e edição de evento (ADR 0002).
 *
 * Allowlist estrita (CLAUDE.md §11): campos que são consequência de ação —
 * `status`, `slug`, `organizer_id`, `teams_registered_count`, `published_at` —
 * não aparecem aqui e não têm efeito se enviados.
 *
 * Validação de **forma**, não de estado (CLAUDE.md §4.3): "o horário de término
 * é depois do início" é forma; "este organizador pode publicar" é estado e vive
 * na action.
 */
final class SaveEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorização real é da Policy, chamada no controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],

            'venue_name' => ['required', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],

            // Hora de parede; a conversão para UTC é da action (ADR 0006).
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],

            'registration_open_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'registration_close_at' => ['nullable', 'date_format:Y-m-d H:i'],

            /*
             * Dinheiro em centavos, sempre inteiro (CLAUDE.md §7).
             * O teto de R$ 100.000,00 não é regra de negócio: é sanidade contra
             * erro de digitação com centavos (13000 vs 1300000).
             */
            'registration_fee_cents' => ['required', 'integer', 'min:0', 'max:10000000'],

            // null = sem teto de duplas (docs/DIVERGENCES.md §6).
            'max_teams' => ['nullable', 'integer', 'min:1', 'max:512'],

            'courts' => ['required', 'integer', 'min:1', 'max:64'],
            'min_games' => ['required', 'integer', 'min:1', 'max:32'],
            'format' => ['nullable', 'string', 'max:120'],

            'modality' => ['required', Rule::enum(Modality::class)],
            'gender_category' => ['required', Rule::enum(GenderCategory::class)],
            'level_category' => ['required', Rule::enum(LevelCategory::class)],
            'age_category' => ['nullable', Rule::enum(AgeCategory::class)],
            'event_type' => ['required', Rule::enum(EventType::class)],

            'prize_description' => ['nullable', 'string', 'max:255'],

            'rules' => ['nullable', 'array', 'max:40'],
            'rules.*' => ['required', 'string', 'max:500'],

            // Q19/ADR 0016 — catálogo fechado (allowlist real, CLAUDE.md §11):
            // Rule::enum/Rule::in recusam qualquer valor fora de RegistrationFieldKey.
            'required_registration_fields' => ['nullable', 'array', 'max:4'],
            'required_registration_fields.*' => ['required', Rule::enum(RegistrationFieldKey::class)],
            'optional_registration_fields' => ['nullable', 'array', 'max:4'],
            'optional_registration_fields.*' => ['required', Rule::enum(RegistrationFieldKey::class)],

            // Configuração operacional (ADR 0013 §2, S8a) — dado inerte nesta
            // fatia, consumidor real chega em S9.
            'days_count' => ['nullable', 'integer', 'min:1', 'max:30'],
            'match_duration_min' => ['nullable', 'integer', 'min:1', 'max:240'],
            'best_of_sets' => ['nullable', 'integer', Rule::in([1, 3, 5])],
            'points_per_set' => ['nullable', 'integer', 'min:1', 'max:99'],
            'tiebreak_points' => ['nullable', 'integer', 'min:1', 'max:99'],

            // Shape fechado pela ADR 0008 §4 — nunca saco de chaves livre.
            'scoring_rules' => ['nullable', 'array'],
            'scoring_rules.win' => ['required_with:scoring_rules', 'integer'],
            'scoring_rules.loss' => ['required_with:scoring_rules', 'integer'],
            'scoring_rules.champion' => ['required_with:scoring_rules', 'integer'],
            'scoring_rules.runnerUp' => ['required_with:scoring_rules', 'integer'],
            'scoring_rules.third' => ['required_with:scoring_rules', 'integer'],
            'scoring_rules.participation' => ['required_with:scoring_rules', 'integer'],

            // Só é exigida quando a mudança é material em evento publicado —
            // quem decide isso é a UpdateEventAction, com o estado em mãos.
            'justification' => ['nullable', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date_format' => 'Informe uma data válida.',
            'start_time.date_format' => 'Informe um horário de início válido.',
            'end_time.date_format' => 'Informe um horário de término válido.',
            'state.regex' => 'A UF deve ter duas letras (ex.: PR).',
            'registration_fee_cents.integer' => 'O valor da inscrição deve estar em centavos.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $state = $this->input('state');

        if (is_string($state)) {
            $this->merge(['state' => mb_strtoupper(trim($state))]);
        }
    }

    /**
     * Coerências entre campos. Duplicam o CHECK constraint de propósito: o
     * banco garante a invariante, isto dá ao organizador uma mensagem por campo
     * em vez de um erro 500 de constraint violada.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $start = (string) $this->string('start_time');
            $end = (string) $this->string('end_time');

            if ($start !== '' && $end !== '' && $end <= $start) {
                $validator->errors()->add('end_time', 'O término deve ser depois do início.');
            }

            $opensAt = $this->input('registration_open_at');
            $closesAt = $this->input('registration_close_at');

            if (is_string($opensAt) && is_string($closesAt) && $closesAt < $opensAt) {
                $validator->errors()->add(
                    'registration_close_at',
                    'O encerramento das inscrições deve ser depois da abertura.',
                );
            }

            // Um campo é obrigatório OU opcional, nunca as duas coisas — a
            // ambiguidade não tem resposta certa na hora de exibir a tela.
            $required = (array) $this->input('required_registration_fields', []);
            $optional = (array) $this->input('optional_registration_fields', []);
            $overlap = array_intersect($required, $optional);

            if ($overlap !== []) {
                $validator->errors()->add(
                    'optional_registration_fields',
                    'Um campo não pode ser obrigatório e opcional ao mesmo tempo: '.implode(', ', $overlap).'.',
                );
            }
        });
    }

    public function toData(): EventData
    {
        /** @var array<int, string> $rules */
        $rules = array_values(array_filter(
            (array) $this->input('rules', []),
            static fn (mixed $rule): bool => is_string($rule) && trim($rule) !== '',
        ));

        return new EventData(
            name: trim((string) $this->string('name')),
            venueName: trim((string) $this->string('venue_name')),
            city: trim((string) $this->string('city')),
            state: (string) $this->string('state'),
            date: (string) $this->string('date'),
            startTime: (string) $this->string('start_time'),
            endTime: (string) $this->string('end_time'),
            registrationFeeCents: $this->integer('registration_fee_cents'),
            maxTeams: $this->filled('max_teams') ? $this->integer('max_teams') : null,
            courts: $this->integer('courts'),
            minGames: $this->integer('min_games'),
            modality: Modality::from((string) $this->string('modality')),
            genderCategory: GenderCategory::from((string) $this->string('gender_category')),
            levelCategory: LevelCategory::from((string) $this->string('level_category')),
            ageCategory: $this->filled('age_category')
                ? AgeCategory::from((string) $this->string('age_category'))
                : AgeCategory::ADULT,
            eventType: EventType::from((string) $this->string('event_type')),

            // Fase 1: fuso único, lido do domínio (ADR 0006). O cliente não
            // envia fuso e não tem como influenciar este valor.
            timezone: EventTimezone::default(),

            description: $this->filled('description') ? (string) $this->string('description') : null,
            registrationOpenAt: $this->filled('registration_open_at')
                ? (string) $this->string('registration_open_at')
                : null,
            registrationCloseAt: $this->filled('registration_close_at')
                ? (string) $this->string('registration_close_at')
                : null,
            format: $this->filled('format') ? (string) $this->string('format') : null,
            prizeDescription: $this->filled('prize_description')
                ? (string) $this->string('prize_description')
                : null,
            rules: $rules,
            requiredRegistrationFields: $this->has('required_registration_fields')
                ? $this->fieldKeysFrom('required_registration_fields')
                : null,
            optionalRegistrationFields: $this->has('optional_registration_fields')
                ? $this->fieldKeysFrom('optional_registration_fields')
                : null,
            daysCount: $this->filled('days_count') ? $this->integer('days_count') : null,
            matchDurationMin: $this->filled('match_duration_min') ? $this->integer('match_duration_min') : null,
            bestOfSets: $this->filled('best_of_sets') ? $this->integer('best_of_sets') : null,
            pointsPerSet: $this->filled('points_per_set') ? $this->integer('points_per_set') : null,
            tiebreakPoints: $this->filled('tiebreak_points') ? $this->integer('tiebreak_points') : null,
            scoringRules: $this->filled('scoring_rules') ? $this->scoringRulesFromInput() : null,
        );
    }

    public function justification(): ?string
    {
        return $this->filled('justification') ? (string) $this->string('justification') : null;
    }

    /** @return array{win: int, loss: int, champion: int, runnerUp: int, third: int, participation: int} */
    private function scoringRulesFromInput(): array
    {
        /** @var array<string, mixed> $rules */
        $rules = (array) $this->input('scoring_rules', []);

        return [
            'win' => (int) $rules['win'],
            'loss' => (int) $rules['loss'],
            'champion' => (int) $rules['champion'],
            'runnerUp' => (int) $rules['runnerUp'],
            'third' => (int) $rules['third'],
            'participation' => (int) $rules['participation'],
        ];
    }

    /** @return list<RegistrationFieldKey> */
    private function fieldKeysFrom(string $key): array
    {
        /** @var array<int, string> $values */
        $values = (array) $this->input($key, []);

        return array_values(array_map(
            fn (string $v): RegistrationFieldKey => RegistrationFieldKey::from($v),
            $values,
        ));
    }
}
