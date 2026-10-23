<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Migration\Application\Processors;

use Scholaris\Core\Http\HttpException;

/** Rattache une erreur d'écriture à la ligne du fichier qui l'a provoquée (le lot entier est alors annulé par la transaction). */
final class AtLine
{
    private function __construct()
    {
    }

    public static function wrap(int $line, HttpException $e): HttpException
    {
        return new HttpException($e->status, $e->title, 'Ligne ' . $line . ' : ' . $e->getMessage() . ' Aucune donnée n\'a été écrite.', $e->type, $e->errors, $e->headers);
    }
}
