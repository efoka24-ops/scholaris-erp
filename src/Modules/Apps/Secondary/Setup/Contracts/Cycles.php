<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Setup\Contracts;

/** Vocabulaire fermé des profils : cycles, enseignements, propriété, sections (codes stables, stockés). */
final class Cycles
{
    public const FIRST = 'first';
    public const SECOND = 'second';
    public const ALL = [self::FIRST, self::SECOND];

    public const TEACHING_GENERAL = 'general';
    public const TEACHING_TECHNICAL = 'technical';
    public const TEACHING_KINDS = [self::TEACHING_GENERAL, self::TEACHING_TECHNICAL];

    public const OWNERSHIPS = ['public', 'private_secular', 'private_faith'];
    public const SECTIONS = ['fr', 'en'];

    private function __construct()
    {
    }
}
