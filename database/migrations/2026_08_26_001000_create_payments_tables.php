<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagamentos, webhooks e ledger (CLAUDE.md §7, §8, §9, §14 · ADR 0009).
 *
 * As três tabelas nascem juntas de propósito (ADR 0009 §7): §7.9 exige que todo
 * lançamento financeiro gere entrada no ledger, e o momento em que isso acontece
 * é a confirmação do pagamento. Criar `payments` sem `ledger_entries` abriria uma
 * janela com dinheiro confirmado e sem trilha.
 *
 * **Nenhuma das três tem soft delete.** CLAUDE.md §6 é explícito: registro
 * financeiro e de auditoria é append-only. `payments` aceita update de status
 * (é uma máquina de estados), mas nunca delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createPayments();
        $this->createWebhookEvents();
        $this->createLedgerEntries();
    }

    private function createPayments(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * A cobrança é 1:1 com a inscrição (ADR 0001: "payments fica 1:1 com
             * registrations, o que simplifica ledger, reembolso e auditoria").
             * RESTRICT: apagar inscrição com pagamento é erro, não cascata.
             */
            $table->foreignUuid('registration_id')->constrained('registrations')->restrictOnDelete();

            /*
             * Desnormalizados por necessidade: o dashboard financeiro filtra por
             * organizador e por evento, e um JOIN até `registrations → events`
             * em toda agregação seria caro. São imutáveis depois de gravados.
             */
            $table->foreignUuid('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignUuid('organizer_id')->constrained('organizers')->restrictOnDelete();
            /** Pagador individual (BRIEF §31, ADR 0001). */
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();

            $table->string('method', 24);
            $table->string('status', 32)->default('DRAFT');

            // ---- Dinheiro: SEMPRE bigint em centavos (CLAUDE.md §7.1) ----
            $table->bigInteger('gross_cents');

            /*
             * Taxa CONGELADA na criação da cobrança (§7.5). Mudança futura de
             * plano ou de taxa não altera pagamento existente, e recalcular
             * pagamento passado é proibido. Por isso as três colunas: a alíquota
             * percentual, a parte fixa e o resultado.
             */
            $table->integer('platform_fee_basis_points');
            $table->bigInteger('platform_fee_fixed_cents');
            $table->bigInteger('platform_fee_cents');

            /*
             * NULLABLE por decisão: a taxa do gateway só é conhecida quando o
             * Asaas responde (`gross − netValue`). NULL = desconhecida, que é
             * diferente de zero. Reproduzir a heurística do protótipo como se
             * fosse verdade é proibido (ADR 0004).
             */
            $table->bigInteger('asaas_fee_cents')->nullable();

            /*
             * Também nullable, e pela mesma razão: sem a taxa do gateway não há
             * líquido. Persistido em vez de calculado na leitura para que o
             * dashboard não precise repetir a aritmética do domínio.
             */
            $table->bigInteger('organizer_net_cents')->nullable();

            $table->bigInteger('refunded_cents')->default(0);

            // ---- Gateway ----
            $table->string('provider', 32);
            $table->string('provider_payment_id', 128)->nullable();
            /** Nosso id, enviado como `externalReference` (CLAUDE.md §28). */
            $table->string('external_reference', 64);

            $table->text('checkout_url')->nullable();
            $table->text('pix_payload')->nullable();

            /*
             * Idempotência (CLAUDE.md §8): `POST /registrations/{id}/payments`
             * exige header `Idempotency-Key`, e chave repetida devolve a MESMA
             * resposta sem criar novo efeito. O unique abaixo é o que garante.
             */
            $table->string('idempotency_key', 128);

            $table->timestampTz('due_at');
            /** Pago — a inscrição confirma aqui (ADR 0009 §2). */
            $table->timestampTz('confirmed_at')->nullable();
            /** Liquidado — o dinheiro fica disponível aqui. */
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('failure_reason')->nullable();

            /** Última reconciliação bem-sucedida contra o gateway (§14). */
            $table->timestampTz('reconciled_at')->nullable();

            $table->timestampsTz();

            $table->index('registration_id');
            $table->index('event_id');
            $table->index('organizer_id');
            $table->index('user_id');
            $table->index('status');
            $table->index(['organizer_id', 'status']);
            $table->index(['event_id', 'status']);
            $table->index('provider_payment_id');
            // Job de reconciliação varre por aqui.
            $table->index(['status', 'reconciled_at']);
        });

        /*
         * Idempotência por identidade + chave. Escopo no usuário para que a
         * chave de um cliente não colida com a de outro.
         */
        DB::statement('CREATE UNIQUE INDEX payments_idempotency_unique ON payments (user_id, idempotency_key)');

        /*
         * O id do gateway é único quando existe. Índice parcial porque ele nasce
         * NULL: a cobrança é gravada localmente antes de ir ao Asaas
         * (ADR 0009 §8 — nada de HTTP dentro de transação).
         */
        DB::statement('CREATE UNIQUE INDEX payments_provider_payment_unique ON payments (provider, provider_payment_id) WHERE provider_payment_id IS NOT NULL');

        DB::statement('CREATE UNIQUE INDEX payments_external_reference_unique ON payments (external_reference)');

        /*
         * Uma inscrição tem no máximo UMA cobrança viva. Índice parcial: uma
         * cobrança que falhou ou foi cancelada não pode impedir nova tentativa
         * de pagamento — senão o atleta perde a vaga por um cartão recusado.
         */
        DB::statement("CREATE UNIQUE INDEX payments_active_per_registration ON payments (registration_id) WHERE status NOT IN ('CANCELLED','FAILED','REFUNDED','CHARGEBACK')");

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('DRAFT','PENDING','CONFIRMED','RECEIVED','OVERDUE','FAILED','CANCELLED','REFUNDED','PARTIALLY_REFUNDED','CHARGEBACK'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('PIX','CREDIT_CARD','BOLETO'))");

        // Invariantes de dinheiro que são do banco, não da aplicação (§6).
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_gross_check CHECK (gross_cents >= 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_platform_fee_check CHECK (platform_fee_cents >= 0 AND platform_fee_cents <= gross_cents)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_platform_fee_bp_check CHECK (platform_fee_basis_points >= 0 AND platform_fee_basis_points <= 10000)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_platform_fee_fixed_check CHECK (platform_fee_fixed_cents >= 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_asaas_fee_check CHECK (asaas_fee_cents IS NULL OR asaas_fee_cents >= 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_refunded_check CHECK (refunded_cents >= 0 AND refunded_cents <= gross_cents)');

        /*
         * A identidade do §7.7 conferida pelo BANCO:
         *   gross − platform_fee − asaas_fee = organizer_net
         *
         * Vale só quando as duas colunas nullable estão preenchidas. É a última
         * linha de defesa: se um bug de aplicação gravar líquido errado, o
         * insert falha em vez de o organizador receber a menos.
         */
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_net_identity_check CHECK (organizer_net_cents IS NULL OR asaas_fee_cents IS NULL OR organizer_net_cents = gross_cents - platform_fee_cents - asaas_fee_cents)');

        // Coerência entre estado e instante.
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_confirmed_check CHECK (status NOT IN ('CONFIRMED','RECEIVED') OR confirmed_at IS NOT NULL)");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_received_check CHECK (status <> 'RECEIVED' OR received_at IS NOT NULL)");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_refunded_at_check CHECK (status <> 'REFUNDED' OR refunded_at IS NOT NULL)");

        /*
         * Barreira contra delete. `payments` é append-only quanto à existência:
         * update de status é o ciclo de vida, mas apagar cobrança apagaria
         * histórico financeiro.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payments_deny_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'payments não aceita DELETE: registro financeiro é append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payments_no_delete
                BEFORE DELETE ON payments
                FOR EACH ROW EXECUTE FUNCTION payments_deny_delete();

            CREATE TRIGGER payments_no_truncate
                BEFORE TRUNCATE ON payments
                FOR EACH STATEMENT EXECUTE FUNCTION payments_deny_delete();
        SQL);
    }

    private function createWebhookEvents(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('provider', 32);
            /** `id` do evento no gateway — chave de idempotência recomendada. */
            $table->string('provider_event_id', 128);
            $table->string('provider_event_type', 64);
            $table->string('provider_payment_id', 128)->nullable();

            /*
             * SET NULL: o evento pode chegar antes de conseguirmos casá-lo com
             * um pagamento (fora de ordem, ou `externalReference` ausente). O
             * evento é gravado de todo jeito — perder evento é perder dinheiro.
             */
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            /*
             * Payload completo para trilha e reprocessamento. Passa por
             * sanitização antes de gravar: CLAUDE.md §9 proíbe token, API key e
             * dado de cartão na trilha, e o payload do Asaas traz bloco de
             * cartão.
             */
            $table->jsonb('payload');

            $table->string('processing_status', 24)->default('PENDING');
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();

            $table->timestampsTz();

            $table->index('provider_payment_id');
            $table->index('payment_id');
            $table->index('processing_status');
            // Alerta de webhook pendente/falho (métrica mínima do §21).
            $table->index(['processing_status', 'received_at']);
        });

        /*
         * A trava de reentrega (CLAUDE.md §8 e §14). A doc do Asaas é explícita
         * que a entrega é "at least once" e que o mesmo evento pode chegar
         * repetido — este unique é o que absorve isso com 200 e sem novo efeito.
         */
        DB::statement('CREATE UNIQUE INDEX payment_webhook_events_unique ON payment_webhook_events (provider, provider_event_id)');

        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_status_check CHECK (processing_status IN ('PENDING','PROCESSING','PROCESSED','FAILED','IGNORED'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_webhook_events_deny_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'payment_webhook_events é append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payment_webhook_events_no_delete
                BEFORE DELETE ON payment_webhook_events
                FOR EACH ROW EXECUTE FUNCTION payment_webhook_events_deny_delete();

            CREATE TRIGGER payment_webhook_events_no_truncate
                BEFORE TRUNCATE ON payment_webhook_events
                FOR EACH STATEMENT EXECUTE FUNCTION payment_webhook_events_deny_delete();
        SQL);
    }

    private function createLedgerEntries(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('type', 32);

            /*
             * Valor COM SINAL: positivo entra, negativo sai (ver
             * `LedgerEntryType::expectedSign()`). Guardar sempre positivo e
             * inferir o sinal pelo tipo na leitura convidaria a somar errado.
             */
            $table->bigInteger('amount_cents');

            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignUuid('organizer_id')->constrained('organizers')->restrictOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained('events')->restrictOnDelete();

            /*
             * Taxa congelada também aqui: o lançamento tem de poder ser lido e
             * conferido sem depender da linha do pagamento nem do plano vigente.
             */
            $table->integer('platform_fee_basis_points')->nullable();

            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));

            /** Só data de criação: não existe update (append-only, §9). */
            $table->timestampTz('created_at');

            $table->index('type');
            $table->index('payment_id');
            $table->index('organizer_id');
            $table->index(['organizer_id', 'type']);
            $table->index(['event_id', 'type']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check CHECK (type IN ('GROSS_PAYMENT','PLATFORM_FEE','ASAAS_FEE','ORGANIZER_NET','REFUND','CHARGEBACK'))");

        /*
         * O sinal tem de bater com o tipo. Sem isto, um bug poderia gravar taxa
         * da plataforma positiva e inflar a receita do SaaS na agregação.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_sign_check CHECK (
                (type IN ('GROSS_PAYMENT','ORGANIZER_NET') AND amount_cents >= 0)
                OR
                (type IN ('PLATFORM_FEE','ASAAS_FEE','REFUND','CHARGEBACK') AND amount_cents <= 0)
            )
        SQL);

        /*
         * Um lançamento de cada tipo por pagamento. Impede o efeito duplicado
         * que um webhook reentregue causaria se a idempotência da aplicação
         * falhasse — é a última linha de defesa do §8 sobre o dinheiro.
         *
         * REFUND e CHARGEBACK ficam de fora: podem ser vários (estorno parcial
         * em parcelas), e é por lançamento novo que se corrige, nunca por update.
         */
        DB::statement("CREATE UNIQUE INDEX ledger_entries_once_per_payment ON ledger_entries (payment_id, type) WHERE payment_id IS NOT NULL AND type IN ('GROSS_PAYMENT','PLATFORM_FEE','ASAAS_FEE','ORGANIZER_NET')");

        // Append-only de verdade: nem update, nem delete, nem truncate (§9).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_entries_deny_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ledger_entries é append-only: % não é permitido. Erro se corrige com lançamento de estorno', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_entries_no_update
                BEFORE UPDATE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_entries_deny_mutation();

            CREATE TRIGGER ledger_entries_no_delete
                BEFORE DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_entries_deny_mutation();

            CREATE TRIGGER ledger_entries_no_truncate
                BEFORE TRUNCATE ON ledger_entries
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_entries_deny_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update ON ledger_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete ON ledger_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_truncate ON ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_entries_deny_mutation()');
        Schema::dropIfExists('ledger_entries');

        DB::unprepared('DROP TRIGGER IF EXISTS payment_webhook_events_no_delete ON payment_webhook_events');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_webhook_events_no_truncate ON payment_webhook_events');
        DB::unprepared('DROP FUNCTION IF EXISTS payment_webhook_events_deny_delete()');
        Schema::dropIfExists('payment_webhook_events');

        DB::unprepared('DROP TRIGGER IF EXISTS payments_no_delete ON payments');
        DB::unprepared('DROP TRIGGER IF EXISTS payments_no_truncate ON payments');
        DB::unprepared('DROP FUNCTION IF EXISTS payments_deny_delete()');
        Schema::dropIfExists('payments');
    }
};
