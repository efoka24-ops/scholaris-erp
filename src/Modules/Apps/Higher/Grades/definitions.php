<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\get;

use Scholaris\Modules\Apps\Higher\Grades\Application\GradesClock;
use Scholaris\Modules\Apps\Higher\Grades\Console\GradingRemindersCommand;
use Scholaris\Modules\Apps\Higher\Grades\Contracts\GradeBook;
use Scholaris\Modules\Apps\Higher\Grades\Domain\IpesDefaults;
use Scholaris\Modules\Apps\Higher\Grades\Infrastructure\PdoGradeBook;

/**
 * Liaisons des notes (fusionnées par Higher/definitions.php) : carnet de notes offert aux autres sous-modules (Deliberation,
 * Teaching), sonde de notes de Curriculum (RG-MAQ-1), commande planifiée des relances NOT-11, et déclaration de la configuration
 * IPES du moteur d'évaluation (`higher.grades.assessment_settings` : clés `assessment.*` et `higher.grades.*` et leurs valeurs par
 * défaut, à écrire dans les paramètres des établissements `higher` par le paramétrage initial). Les gardes de publication
 * (RG-DEL-3) s'enregistrent sous `higher.grades.publication_guards.<module>`.
 */
return [
    GradeBook::class => get(PdoGradeBook::class),
    PdoGradeBook::class => autowire(),
    GradesClock::class => autowire(),
    'higher.curriculum.grade_probes.grades' => [PdoGradeBook::class],
    'higher.grades.assessment_settings' => IpesDefaults::SETTINGS,
    'console.command.' . GradingRemindersCommand::NAME => autowire(GradingRemindersCommand::class),
];
