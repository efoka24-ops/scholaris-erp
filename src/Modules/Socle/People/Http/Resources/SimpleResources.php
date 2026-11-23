<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\People\Http\Resources;

/** Ressources simples du module, une liste blanche par type. */
final class SimpleResources
{
    private static function make(string ...$fields): Resource
    {
        return new class (array_values($fields)) extends Resource {
            /** @param list<string> $names */
            public function __construct(private readonly array $names)
            {
            }

            protected function fields(): array
            {
                return $this->names;
            }
        };
    }

    public static function contact(): Resource
    {
        return self::make('id', 'person_id', 'kind', 'label', 'value', 'created_at');
    }

    public static function identifier(): Resource
    {
        return self::make('id', 'person_id', 'kind', 'establishment_id', 'value', 'created_at');
    }

    public static function document(): Resource
    {
        return self::make('id', 'person_id', 'kind', 'status', 'size_bytes', 'mime_type', 'expires_on', 'created_at');
    }

    public static function consent(): Resource
    {
        return self::make('id', 'person_id', 'purpose', 'granted_at', 'revoked_at');
    }

    public static function enrollment(): Resource
    {
        return self::make('id', 'person_id', 'establishment_id', 'structure_id', 'academic_year_id', 'status', 'internal_number', 'completeness', 'documents_due_on', 'validated_at', 'created_at', 'updated_at');
    }

    public static function history(): Resource
    {
        return self::make('id', 'person_id', 'enrollment_id', 'school_name', 'level_label', 'year_label', 'outcome', 'source', 'created_at');
    }

    public static function relation(): Resource
    {
        return self::make('id', 'kind', 'as', 'person');
    }
}
