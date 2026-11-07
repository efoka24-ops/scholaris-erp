<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Domain;

use InvalidArgumentException;

/** Contrôle d'une pièce téléversée : type réel (signature du contenu, jamais le nom ni le type annoncés par le client) et taille. */
final class UploadPolicy
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @return array{mime: string, ext: string} */
    public static function inspect(string $content): array
    {
        $size = strlen($content);
        if ($size === 0) {
            throw new InvalidArgumentException('Le fichier est vide.');
        }
        if ($size > self::MAX_BYTES) {
            throw new InvalidArgumentException('Le fichier dépasse 5 Mo. Réduisez-le puis réessayez.');
        }
        if (str_starts_with($content, '%PDF-')) {
            return ['mime' => 'application/pdf', 'ext' => 'pdf'];
        }
        if (str_starts_with($content, "\xFF\xD8\xFF")) {
            return ['mime' => 'image/jpeg', 'ext' => 'jpg'];
        }
        if (str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
            return ['mime' => 'image/png', 'ext' => 'png'];
        }

        throw new InvalidArgumentException('Type de fichier refusé. Formats acceptés : PDF, JPG ou PNG.');
    }
}
