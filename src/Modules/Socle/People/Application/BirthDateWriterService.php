<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Application;

use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Socle\People\Contracts\BirthDateWriter;

/** Réalisation de BirthDateWriter sur PersonService (journal et contrôles de People). */
final class BirthDateWriterService implements BirthDateWriter
{
    public function __construct(
        private readonly PersonService $people,
        private readonly PeopleClock $clock,
    ) {
    }

    public function record(string $tenantId, string $personId, string $birthDate, ?string $birthPlace = null): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        if ($date === false || $date->format('Y-m-d') !== $birthDate || $birthDate >= $this->clock->today()) {
            throw HttpException::validation(['birth_date' => ['Date de naissance invalide (AAAA-MM-JJ, passée).']]);
        }
        $values = ['birth_date' => $birthDate];
        if ($birthPlace !== null && trim($birthPlace) !== '') {
            $values['birth_place'] = mb_substr(trim($birthPlace), 0, 120);
        }
        $this->people->update($tenantId, $personId, $values);
    }
}
