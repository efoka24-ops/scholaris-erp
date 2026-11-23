<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Sync\Http\Requests;

use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;

/** PP7 : corps `{"code": "123456"}` des routes de code personnel (définition et déverrouillage). */
final class PinRequest
{
    public function code(ServerRequestInterface $request): string
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
            if ($name !== 'code') {
                $errors[(string) $name] = ['Champ non autorisé.'];
            }
        }
        $code = $data['code'] ?? null;
        if (!is_string($code) || $code === '') {
            $errors['code'] = ['Le code personnel est obligatoire.'];
            throw HttpException::validation($errors);
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $code;
    }
}
