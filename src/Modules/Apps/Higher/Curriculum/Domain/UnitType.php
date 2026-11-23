<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Curriculum\Domain;

/** Type d'UE (EX-MAQ-02) ; une UE optionnelle ou à choix porte un groupe de choix (`choice_group`, `choice_count`). */
enum UnitType: string
{
    case Fundamental = 'fundamental';
    case Transversal = 'transversal';
    case Optional = 'optional';
    case Internship = 'internship';
    case Project = 'project';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
