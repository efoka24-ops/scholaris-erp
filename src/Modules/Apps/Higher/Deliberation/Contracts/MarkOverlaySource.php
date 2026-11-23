<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Deliberation\Contracts;

/**
 * Source de notes complémentaire : sa feuille s'ajoute à celle des sources principales (entrées ajoutées à l'EC visé, unités
 * manquantes complétées, `unlocked` en union) sans jamais la remplacer ni exister seule. Les personnes qu'aucune source principale
 * ne connaît sont ignorées. Exemple : la note de soutenance de Higher\Internships, ajoutée à l'EC « stage/mémoire » de la maquette.
 *
 * Enregistrement : comme MarkSource, sous `higher.deliberation.mark_sources.<module>`.
 */
interface MarkOverlaySource extends MarkSource
{
}
