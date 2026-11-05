<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Organisation\Domain;

use InvalidArgumentException;

/**
 * Contrôle et normalisation d'un logo téléversé : le type est établi par le CONTENU (jamais par le nom ni par le type annoncé
 * par le navigateur), seuls PNG et JPEG sont acceptés, 1 Mo au plus, puis l'image est décodée et ré-encodée (ce qui retire
 * toute donnée annexe : métadonnées, texte, code caché) et ramenée à 300 px de large au plus.
 */
final class LogoProcessor
{
    public const MAX_BYTES = 1048576;
    public const MAX_WIDTH = 300;
    /** Au-delà, le décodage coûterait trop de mémoire (bombe de décompression). */
    private const MAX_PIXELS = 25000000;
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    private function __construct()
    {
    }

    /**
     * @return array{content: string, mime: string, extension: string, width: int, height: int}
     * @throws InvalidArgumentException message affichable à la personne
     */
    public static function process(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Le logo doit peser 1 Mo au plus.');
        }
        $mime = self::detectMime($bytes);
        $info = $mime === null ? false : @getimagesizefromstring($bytes);
        if ($mime === null || $info === false || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidArgumentException('Le logo doit être une image PNG ou JPEG valide.');
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width * $height > self::MAX_PIXELS) {
            throw new InvalidArgumentException('Les dimensions du logo sont trop grandes.');
        }
        if (!function_exists('imagecreatefromstring')) {
            // Sans GD, impossible de ré-encoder : on n'accepte que ce qui respecte déjà la taille attendue.
            if ($width > self::MAX_WIDTH) {
                throw new InvalidArgumentException('Le logo doit faire 300 pixels de large au plus.');
            }

            return ['content' => $bytes, 'mime' => $mime, 'extension' => self::TYPES[$mime], 'width' => $width, 'height' => $height];
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new InvalidArgumentException('Le logo doit être une image PNG ou JPEG valide.');
        }
        $targetWidth = min($width, self::MAX_WIDTH);
        $targetHeight = max(1, (int) round($height * $targetWidth / $width));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            throw new InvalidArgumentException('Le logo n\'a pas pu être traité.');
        }
        if ($mime === 'image/png') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        } else {
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        }
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $written = $mime === 'image/png' ? imagepng($canvas, null, 9) : imagejpeg($canvas, null, 88);
        $content = (string) ob_get_clean();
        if (!$written || $content === '') {
            throw new InvalidArgumentException('Le logo n\'a pas pu être traité.');
        }

        return ['content' => $content, 'mime' => $mime, 'extension' => self::TYPES[$mime], 'width' => $targetWidth, 'height' => $targetHeight];
    }

    private static function detectMime(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            $mime = 'image/png';
        } elseif (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            $mime = 'image/jpeg';
        } else {
            return null;
        }
        if (class_exists(\finfo::class)) {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if ($detected !== $mime) {
                return null;
            }
        }

        return $mime;
    }
}
