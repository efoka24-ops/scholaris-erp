<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Requests;

use Scholaris\Modules\Socle\Messaging\Domain\Channel;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateException;
use Scholaris\Modules\Socle\Messaging\Domain\TemplateRenderer;

/** Création (clé, langue, canal figés) ou modification (objet et texte seulement) d'un modèle. */
final class TemplateRequest extends ValidatedRequest
{
    public function __construct(private readonly bool $update = false)
    {
    }

    protected function fields(): array
    {
        $editable = [
            'subject' => Field::string(200)->nullable(),
            'body' => Field::string(2000)->required(),
        ];
        if ($this->update) {
            return $editable;
        }

        return [
            'template_key' => Field::string(60, 2, '/^[a-z][a-z0-9_.]*$/')->required(),
            'locale' => Field::enum(['fr', 'en'])->required(),
            'channel' => Field::enum(Channel::all())->required(),
        ] + $editable;
    }

    protected function crossCheck(array $values, array $errors): array
    {
        foreach (['body', 'subject'] as $field) {
            if (isset($values[$field]) && is_string($values[$field])) {
                try {
                    TemplateRenderer::assertBodyAllowed($values[$field]);
                } catch (TemplateException $e) {
                    $errors[$field] = [$e->getMessage() . ' Variables permises : ' . implode(', ', TemplateRenderer::ALLOWED) . '.'];
                }
            }
        }

        return $errors;
    }
}
