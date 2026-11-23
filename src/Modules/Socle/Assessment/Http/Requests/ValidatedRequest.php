<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Assessment\Http\Requests;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;

/**
 * Une classe par cas d'usage déclare ses champs. Tout champ inconnu est refusé : tenant_id, establishment_id,
 * statuts et montants calculés ne viennent jamais du client.
 */
abstract class ValidatedRequest
{
    /** @return array<string, Field> */
    abstract protected function fields(): array;

    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $errors
     * @return array<string, list<string>>
     */
    protected function crossCheck(array $values, array $errors): array
    {
        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    final public function validate(array $data, bool $partial = false): array
    {
        $fields = $this->fields();
        $errors = [];
        $values = [];

        foreach (array_keys($data) as $name) {
            if (!isset($fields[$name])) {
                $errors[(string) $name] = ['Champ non autorisé.'];
            }
        }
        foreach ($fields as $name => $field) {
            if (!array_key_exists($name, $data)) {
                if ($field->isRequired() && !$partial) {
                    $errors[$name] = ['Ce champ est obligatoire.'];
                }
                continue;
            }
            try {
                $values[$name] = $field->parse($data[$name]);
            } catch (InvalidArgumentException $e) {
                $errors[$name] = [$e->getMessage()];
            }
        }
        if ($errors === []) {
            $errors = $this->crossCheck($values, $errors);
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     * @throws HttpException
     */
    final public function fromHttp(ServerRequestInterface $request, bool $partial = false): array
    {
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            throw HttpException::badRequest('Le corps de la requête est vide : un objet JSON est attendu.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw HttpException::badRequest('Le corps de la requête doit être un objet JSON.');
        }

        return $this->validate($data, $partial);
    }
}
