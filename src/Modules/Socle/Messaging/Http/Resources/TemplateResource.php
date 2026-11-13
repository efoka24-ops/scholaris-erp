<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Messaging\Http\Resources;

use Scholaris\Modules\Socle\Messaging\Infrastructure\TemplateRepository;

final class TemplateResource extends Resource
{
    protected function fields(): array
    {
        return ['id', 'template_key', 'locale', 'channel', 'subject', 'body', 'variables', 'created_at', 'updated_at'];
    }

    protected function jsonFields(): array
    {
        return ['variables'];
    }

    public function toArray(array $row): array
    {
        return ['scope' => ($row['tenant_id'] ?? null) === TemplateRepository::PLATFORM ? 'platform' : 'establishment'] + parent::toArray($row);
    }
}
