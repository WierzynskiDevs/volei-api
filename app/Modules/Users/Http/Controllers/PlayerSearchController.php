<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Users\Domain\Enums\UserStatus;
use App\Modules\Users\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Busca de atletas para formar dupla.
 *
 * Consumidores: o seletor "Escolha o parceiro" em `/inscricao/{slug}` e a busca
 * de `/trocar-dupla` (aditivo §18).
 *
 * ## Por que não usa `UserResource`
 *
 * `UserResource` decide o que mostrar a partir de quem pergunta, e para
 * terceiros ainda inclui `phone_masked`. Aqui o contrato é mais estreito por
 * construção: **nome, nível e cidade**, nada mais. É o que a tela mostra
 * ("#12 · Avançado · Curitiba") e é o mínimo para escolher um parceiro.
 * Um payload que carregasse telefone mascarado transformaria uma busca por nome
 * num diretório de contatos (CLAUDE.md §12).
 *
 * ## Anti-enumeração
 *
 * Exige termo com pelo menos 2 caracteres e nunca lista "todos": sem isso o
 * endpoint viraria um dump paginado da base de usuários. Autenticação
 * obrigatória e rate limit na rota.
 */
final class PlayerSearchController
{
    private const int MIN_QUERY_LENGTH = 2;

    private const int MAX_RESULTS = 20;

    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->string('q'));

        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            // Lista vazia, não erro: a tela abre com o campo em branco e não
            // deve mostrar mensagem de falha antes de a pessoa digitar.
            return response()->json(['data' => []]);
        }

        $viewerId = $request->user()?->id;

        $players = User::query()
            ->where('status', UserStatus::ACTIVE->value)
            // Ninguém forma dupla consigo mesmo; tirar daqui evita a opção
            // aparecer só para ser recusada depois.
            ->when($viewerId !== null, fn ($q) => $q->whereKeyNot($viewerId))
            /*
             * `LIKE` com o termo escapado. `addcslashes` neutraliza `%` e `_`,
             * senão uma busca por "%" devolveria a base inteira — o curinga do
             * LIKE tem de ser tratado como texto (CLAUDE.md §11).
             */
            ->where('name', 'ILIKE', '%'.addcslashes($term, '%_\\').'%')
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'level', 'city', 'state']);

        return response()->json([
            'data' => $players->map(fn (User $player): array => [
                'id' => $player->id,
                'name' => $player->name,
                'level' => $player->level?->value,
                'city' => $player->city,
                'state' => $player->state,
            ])->all(),
        ]);
    }
}
