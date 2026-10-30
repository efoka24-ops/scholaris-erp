<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Academic\Domain;

/** Régime d'une filière (EX-ACA-02) : un seul, jamais modifié (RG-ORG-7). */
enum Regime: string
{
    case Lmd = 'lmd';
    case Bts = 'bts';
    case Hnd = 'hnd';
    case Certificate = 'certificate';

    /** RG-ORG-8, RG-ACA-2 : seule une filière LMD a une université de tutelle par année. */
    public function requiresTutelage(): bool
    {
        return $this === self::Lmd;
    }

    /** Maquette en crédits (LMD, certificat) ou en matières et coefficients rattachées à l'examen national (BTS, HND). */
    public function isNationalExam(): bool
    {
        return $this === self::Bts || $this === self::Hnd;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
