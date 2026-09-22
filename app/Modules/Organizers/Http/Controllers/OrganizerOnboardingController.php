<?php

declare(strict_types=1);

namespace App\Modules\Organizers\Http\Controllers;

use App\Modules\Organizers\Application\CreateOrganizerAction;
use App\Modules\Organizers\Http\Requests\CreateOrganizerRequest;
use App\Modules\Organizers\Http\Resources\OrganizerResource;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Onboarding do organizador (OPEN-QUESTIONS Q6, ADR 0014).
 *
 * Consumidor: `/onboarding` no volei-app, quando o usuário escolhe operar
 * como organizador. Sem policy própria — a única regra é "não ter organizador
 * ainda", e isso é estado, não papel; a `CreateOrganizerAction` recusa.
 */
final class OrganizerOnboardingController
{
    public function __construct(private readonly CreateOrganizerAction $createOrganizer) {}

    /** `POST /organizers` */
    public function store(CreateOrganizerRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $organizer = $this->createOrganizer->execute(
            actor: $user,
            name: trim((string) $request->string('name')),
            documentNumber: $request->documentNumber(),
            description: $request->filled('description') ? (string) $request->string('description') : null,
            contactEmail: $request->filled('contact_email') ? (string) $request->string('contact_email') : null,
            city: $request->filled('city') ? (string) $request->string('city') : null,
            state: $request->filled('state') ? (string) $request->string('state') : null,
        );

        return (new OrganizerResource($organizer))
            ->response()
            ->setStatusCode(201);
    }
}
