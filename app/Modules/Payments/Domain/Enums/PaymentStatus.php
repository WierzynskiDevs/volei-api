<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Enums;

/**
 * Estado do pagamento no **nosso** domínio.
 *
 * Deliberadamente NÃO é o enum do Asaas. `CLAUDE.md` §14: nenhum tipo, campo ou
 * string do gateway atravessa a fronteira para `Domain/`. O Asaas tem 14 status
 * de cobrança (docs/asaas.md §3); aqui existem os que mudam alguma decisão nossa.
 *
 * A tradução Asaas → domínio vive em `AsaasPaymentProvider`, na infraestrutura.
 *
 * ## Os dois estágios que importam (ADR 0009 §2)
 *
 * `CONFIRMED` e `RECEIVED` são fatos diferentes, e a doc oficial é explícita:
 * confirmado = pago mas **saldo ainda não disponível**; recebido = **valor
 * disponível** na conta. No cartão de crédito a distância é de 32 dias por
 * parcela. Fundir os dois mostraria como disponível dinheiro que o gateway não
 * liberou.
 */
enum PaymentStatus: string
{
    /** Cobrança criada localmente, ainda não enviada ao gateway. */
    case DRAFT = 'DRAFT';

    /** No gateway, aguardando pagamento. */
    case PENDING = 'PENDING';

    /** Pago — a inscrição é confirmada. O saldo ainda não está disponível. */
    case CONFIRMED = 'CONFIRMED';

    /** Liquidado — o valor está disponível na conta do organizador. */
    case RECEIVED = 'RECEIVED';

    /** Vencido sem pagamento. */
    case OVERDUE = 'OVERDUE';

    /** Recusado pelo gateway (cartão negado, análise de risco reprovada). */
    case FAILED = 'FAILED';

    /** Cancelado antes de ser pago. */
    case CANCELLED = 'CANCELLED';

    case REFUNDED = 'REFUNDED';

    case PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';

    /** Contestado pelo pagador. O dinheiro sai da conta até a disputa resolver. */
    case CHARGEBACK = 'CHARGEBACK';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::PENDING => 'Aguardando pagamento',
            self::CONFIRMED => 'Pago',
            self::RECEIVED => 'Recebido',
            self::OVERDUE => 'Vencido',
            self::FAILED => 'Falhou',
            self::CANCELLED => 'Cancelado',
            self::REFUNDED => 'Estornado',
            self::PARTIALLY_REFUNDED => 'Estornado parcialmente',
            self::CHARGEBACK => 'Chargeback',
        };
    }

    /**
     * O pagamento vale como pago para efeito de **inscrição**?
     *
     * `CONFIRMED` já vale: o atleta pagou e tem a vaga (ADR 0009 §2). Esperar
     * `RECEIVED` deixaria quem paga com cartão 32 dias sem vaga confirmada.
     *
     * Estorno parcial continua valendo: parte do dinheiro voltou, mas a
     * participação não foi desfeita — quem decide isso é o organizador, pelo
     * cancelamento da inscrição.
     */
    public function confirmsRegistration(): bool
    {
        return match ($this) {
            self::CONFIRMED, self::RECEIVED, self::PARTIALLY_REFUNDED => true,
            default => false,
        };
    }

    /**
     * O dinheiro está **disponível** na conta do organizador?
     *
     * Só `RECEIVED`. É esta a resposta que o dashboard financeiro usa para
     * "líquido disponível" — e é por isso que ela não pode incluir `CONFIRMED`.
     */
    public function moneyIsAvailable(): bool
    {
        return $this === self::RECEIVED;
    }

    /** Já saiu do gateway sem retorno possível. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::CANCELLED, self::REFUNDED, self::CHARGEBACK => true,
            default => false,
        };
    }

    /** Ainda pode ser pago — a reserva de vaga continua valendo (ADR 0003). */
    public function isOpen(): bool
    {
        return match ($this) {
            self::DRAFT, self::PENDING, self::OVERDUE => true,
            default => false,
        };
    }

    /**
     * Transições válidas (CLAUDE.md §8: máquina de estado explícita).
     *
     * Note que `OVERDUE → CONFIRMED` é permitido: boleto e PIX vencidos podem
     * ser pagos depois. Recusar isso perderia pagamento real.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::PENDING, self::CANCELLED, self::FAILED],
            self::PENDING => [
                self::CONFIRMED, self::RECEIVED, self::OVERDUE,
                self::FAILED, self::CANCELLED,
            ],
            self::OVERDUE => [self::CONFIRMED, self::RECEIVED, self::CANCELLED, self::FAILED],
            // Confirmado avança para recebido, ou volta por estorno/chargeback.
            self::CONFIRMED => [
                self::RECEIVED, self::REFUNDED, self::PARTIALLY_REFUNDED, self::CHARGEBACK,
            ],
            self::RECEIVED => [self::REFUNDED, self::PARTIALLY_REFUNDED, self::CHARGEBACK],
            self::PARTIALLY_REFUNDED => [self::REFUNDED, self::CHARGEBACK],
            // Falha não é terminal: uma nova tentativa de cartão pode passar.
            self::FAILED => [self::PENDING, self::CONFIRMED, self::CANCELLED],
            self::CANCELLED, self::REFUNDED, self::CHARGEBACK => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /**
     * Estados que ainda sustentam a reserva de vaga, para uso em SQL.
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $s): string => $s->value,
            array_filter(self::cases(), fn (self $s): bool => $s->isOpen()),
        ));
    }

    /**
     * Grupos que as abas de `/admin/pagamentos` usam para filtrar.
     *
     * A tela filtra por conceito ("Pagos", "Falhos"), não por status um a um —
     * "Pagos" precisa reunir `CONFIRMED` e `RECEIVED`, que são fatos diferentes
     * (ADR 0009 §2) e aparecem juntos na mesma aba.
     *
     * O mapa vive aqui, e não na camada de consulta, porque é conhecimento de
     * domínio: quando entrar um status novo, é este `match` que falha em
     * revisão — uma lista solta na query só ficaria desatualizada em silêncio.
     *
     * @return list<self> vazio significa "sem filtro", não "nenhum resultado"
     */
    public static function group(string $group): array
    {
        return match (mb_strtolower($group)) {
            'paid' => [self::CONFIRMED, self::RECEIVED],
            'pending' => [self::DRAFT, self::PENDING, self::OVERDUE],
            'failed' => [self::FAILED, self::CANCELLED],
            'refunded' => [self::REFUNDED, self::PARTIALLY_REFUNDED],
            'chargeback' => [self::CHARGEBACK],
            default => [],
        };
    }
}
