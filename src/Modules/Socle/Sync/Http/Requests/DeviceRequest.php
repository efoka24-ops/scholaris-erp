<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;

/** Inscription d'un appareil : seul le libellé vient du client (la clé est générée par le serveur). */
final class DeviceRequest
{
    public const LABEL_MAX = 120;

    /** @return array{label: string} */
    public function fromHttp(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            throw HttpException::badRequest('Le corps de la requête est vide : un objet JSON est attendu.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }

        $errors = [];
        foreach (array_keys($data) as $name) {
            if ($name !== 'label') {
                $errors[(string) $name] = ['Champ non autorisé.'];
            }
        }
        $label = $data['label'] ?? null;
        if (!is_string($label) || trim($label) === '' || mb_strlen(trim($label)) > self::LABEL_MAX) {
            $errors['label'] = ['Le libellé est obligatoire (' . self::LABEL_MAX . ' caractères au plus).'];
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return ['label' => trim((string) $label)];
    }
}
