<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Curriculum\Domain;

/**
 * Cycle d'une maquette (RG-MAQ-1) : brouillon (modifiable), publiée (projetée vers l'évaluation, jamais modifiée sur place),
 * remplacée (une version plus récente est publiée ; les étudiants inscrits sur elle y restent).
 */
enum CurriculumStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
}
