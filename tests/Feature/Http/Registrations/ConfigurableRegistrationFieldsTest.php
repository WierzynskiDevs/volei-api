<?php

declare(strict_types=1);

use App\Modules\Events\Infrastructure\Models\Event;
use App\Modules\Registrations\Infrastructure\Models\Registration;
use App\Modules\Users\Infrastructure\Models\User;

/*
 * Resposta aos campos configuráveis do evento na inscrição (Q19, ADR 0016).
 *
 * Cobre: exigência bloqueia sem o dado (422), valor é gravado só quando o
 * evento realmente coleta o campo, e — o ponto mais fácil de errar — dado
 * pessoal do capitão nunca vaza para a inscrição do parceiro convidado.
 */

beforeEach(function (): void {
    $this->athlete = User::factory()->player()->create();
});

describe('POST /api/v1/events/{slug}/registrations — campo exigido', function (): void {
    beforeEach(function (): void {
        $this->event = Event::factory()->registrationOpen()->create([
            'required_registration_fields' => ['SHIRT_SIZE'],
            'optional_registration_fields' => ['TEAM_NAME'],
        ]);
    });

    it('recusa sem o campo exigido — 422', function (): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", [
                'partner_mode' => 'INDIVIDUAL',
                'accept_rules' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REGISTRATION_FIELD_REQUIRED');
    });

    it('aceita e grava o valor quando o campo vem preenchido', function (): void {
        $response = $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", [
                'partner_mode' => 'INDIVIDUAL',
                'accept_rules' => true,
                'shirt_size' => 'G',
                'team_name' => 'Mendes / Alves',
            ])
            ->assertCreated()
            ->assertJsonPath('data.shirt_size', 'G')
            ->assertJsonPath('data.team_name', 'Mendes / Alves');

        $registration = Registration::findOrFail($response->json('data.id'));
        expect($registration->shirt_size->value)->toBe('G');
    });

    it('rejeita tamanho de camiseta fora do catálogo — 422', function (): void {
        $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$this->event->slug}/registrations", [
                'partner_mode' => 'INDIVIDUAL',
                'accept_rules' => true,
                'shirt_size' => 'XXXL', // não existe no enum
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    });
});

describe('POST /api/v1/events/{slug}/registrations — campo não coletado pelo evento', function (): void {
    it('ignora o valor enviado se o evento não pediu esse campo', function (): void {
        $event = Event::factory()->registrationOpen()->create([
            'required_registration_fields' => [],
            'optional_registration_fields' => [],
        ]);

        $response = $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$event->slug}/registrations", [
                'partner_mode' => 'INDIVIDUAL',
                'accept_rules' => true,
                'shirt_size' => 'M', // evento não coleta — não deve persistir
            ])
            ->assertCreated();

        $registration = Registration::findOrFail($response->json('data.id'));
        expect($registration->shirt_size)->toBeNull();
    });
});

describe('POST /api/v1/events/{slug}/registrations — dupla (PARTNER)', function (): void {
    it('não copia o dado pessoal do capitão para a inscrição do parceiro convidado', function (): void {
        $event = Event::factory()->registrationOpen()->create([
            'max_teams' => 8,
            'required_registration_fields' => [],
            'optional_registration_fields' => ['SHIRT_SIZE', 'TEAM_NAME'],
        ]);

        $partner = User::factory()->player()->create();

        $response = $this->actingAs($this->athlete)
            ->postJson("/api/v1/events/{$event->slug}/registrations", [
                'partner_mode' => 'PARTNER',
                'partner_user_id' => $partner->id,
                'accept_rules' => true,
                'shirt_size' => 'GG', // tamanho DO CAPITÃO
                'team_name' => 'Mendes / Alves',
            ])
            ->assertCreated();

        $captainRegistration = Registration::findOrFail($response->json('data.id'));
        $partnerRegistration = Registration::where('user_id', $partner->id)
            ->where('event_id', $event->id)
            ->firstOrFail();

        expect($captainRegistration->shirt_size->value)->toBe('GG')
            // Dado pessoal: o parceiro NÃO herda o tamanho do capitão.
            ->and($partnerRegistration->shirt_size)->toBeNull()
            // Dado da dupla (compartilhado): os dois têm o mesmo nome de time.
            ->and($captainRegistration->team_name)->toBe('Mendes / Alves')
            ->and($partnerRegistration->team_name)->toBe('Mendes / Alves');
    });
});
