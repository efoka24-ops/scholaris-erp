<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\PublicPrimary\Exams\Console;

use Scholaris\Modules\Apps\PublicPrimary\Exams\Application\BirthActAlertService;

/**
 * `public-primary:exam-birth-acts [AAAA-MM-JJ]` : NOT-08 aux familles des candidats sans acte de naissance vérifié, dès le mois
 * paramétré de l'année d'examen (janvier par défaut), une fois par candidat. À planifier chaque jour (clé de conteneur
 * `console.command.public-primary:exam-birth-acts`).
 */
final class ExamBirthActAlertsCommand
{
    public const NAME = 'public-primary:exam-birth-acts';

    public function __construct(private readonly BirthActAlertService $alerts)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $day = $args[0] ?? null;
        if ($day !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4)))) {
            fwrite(STDERR, "Usage : public-primary:exam-birth-acts [AAAA-MM-JJ]\n");

            return 2;
        }
        $result = $this->alerts->run($day);
        echo sprintf("Actes de naissance des candidats : %d alerte(s), %d message(s) ; %d client(s) sauté(s).\n", $result['alerted'], $result['messages'], $result['skipped_tenants']);

        return 0;
    }
}
