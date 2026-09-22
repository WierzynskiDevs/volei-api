<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Fuso do evento (ADR 0006).
 *
 * Este é o **único** lugar do código que escreve o nome do fuso. Controllers,
 * requests, seeds e testes leem daqui — a decisão do ADR só permanece
 * verdadeira enquanto a constante não se espalhar.
 *
 * `config('app.timezone')` permanece em UTC e NUNCA é usado para interpretar
 * a hora informada pelo organizador: o formulário coleta hora de parede, e
 * hora de parede sem fuso declarado é ambígua.
 */
final readonly class EventTimezone
{
    /**
     * Fase 1: fuso único fixo. Não há derivação por UF nem campo no formulário —
     * o Brasil não observa horário de verão desde 2019 e todos os estados dos
     * eventos existentes são UTC−3 (ADR 0006, alternativas consideradas).
     */
    public const string DEFAULT = 'America/Sao_Paulo';

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    public function __construct(public string $name) {}

    public function toDateTimeZone(): DateTimeZone
    {
        return new DateTimeZone($this->name);
    }

    /**
     * Converte a hora de parede do formulário para o instante em UTC.
     *
     * Esta conversão acontece UMA vez, na action de criação/edição. Depois
     * disso o sistema inteiro trabalha com o instante — nunca reinterpreta
     * a string original.
     *
     * @param  string  $date  data ISO do input type="date" — "2026-08-22"
     * @param  string  $time  hora do input type="time" — "08:30"
     */
    public function toUtc(string $date, string $time): CarbonImmutable
    {
        $moment = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $date.' '.substr($time, 0, 5),
            $this->toDateTimeZone(),
        );

        if (! $moment instanceof CarbonImmutable) {
            /*
             * O FormRequest já valida o formato, então chegar aqui significa
             * chamada interna com dado malformado (seeder, comando, migração
             * de dados). Falhar alto é melhor do que gravar um horário
             * silenciosamente errado — o instante do evento é o dado que
             * decide se as pessoas aparecem na quadra na hora certa.
             */
            throw new InvalidArgumentException(
                "Data/hora inválida para o fuso {$this->name}: \"{$date} {$time}\"."
            );
        }

        return $moment->utc();
    }
}
