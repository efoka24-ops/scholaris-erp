<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Higher\Grades\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Modules\Apps\Higher\Academic\Http\Input;
use Scholaris\Modules\Socle\People\Contracts\AttachmentStore;

/**
 * Corps d'une demande de correction (API-12). Deux formes :
 * - multipart/form-data : champs score|absence, reason, query_id? et fichier `attachment` (PDF, JPG ou PNG, 5 Mo au plus) ;
 * - JSON (compatibilité) : `attachment_ref`, référence d'une pièce papier ou déjà classée, sans fichier.
 */
final class CorrectionUpload
{
    /** @return array{0: array<string, mixed>, 1: ?string} valeurs contrôlées et contenu du fichier (null en JSON) */
    public static function read(ServerRequestInterface $request): array
    {
        $files = $request->getUploadedFiles();
        $form = $request->getParsedBody();
        $multipart = $files !== [] || (is_array($form) && $form !== [] && trim((string) $request->getBody()) === '');
        if (!$multipart) {
            $input = Input::of($request);
            self::rules($input);
            $input->string('attachment_ref', 255);

            return [$input->done(), null];
        }

        $data = [];
        foreach (is_array($form) ? $form : [] as $field => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $data[(string) $field] = $field === 'score' && is_numeric(str_replace(',', '.', $value)) ? (float) str_replace(',', '.', $value) : $value;
        }
        $input = new Input($data);
        self::rules($input);
        $input->string('attachment_ref', 255, false);
        $content = self::file($files['attachment'] ?? null);

        return [$input->done(), $content];
    }

    private static function rules(Input $input): void
    {
        $input->number('score', 0, 100, 0.01, false);
        $input->string('absence', 12, false);
        $input->string('reason', 500);
        $input->reference('query_id', false, true);
    }

    private static function file(mixed $file): string
    {
        $tooBig = 'Le fichier dépasse 5 Mo. Réduisez-le puis réessayez.';
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $big = $file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

            throw HttpException::validation(['attachment' => [$big ? $tooBig : 'Joignez la pièce justificative (champ « attachment », PDF, JPG ou PNG, 5 Mo au plus).']]);
        }
        if (($file->getSize() ?? 0) > AttachmentStore::MAX_BYTES) {
            throw HttpException::validation(['attachment' => [$tooBig]]);
        }

        return (string) $file->getStream();
    }
}
