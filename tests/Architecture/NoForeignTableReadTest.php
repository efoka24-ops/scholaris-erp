<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Un module ne lit jamais les tables `users` (Identity) ni `people` (People) : il passe par leurs contrats
 * (Identity\Contracts\UserPersonLink, People\Contracts\BirthInfo, PersonDirectory…). Liste d'exceptions : vide.
 */
final class NoForeignTableReadTest extends TestCase
{
    private const OWNERS = ['Identity', 'People'];
    private const EXCEPTIONS = [];

    public function testAucunModuleHorsIdentityEtPeopleNeLitUsersOuPeople(): void
    {
        $offenders = [];
        $root = dirname(__DIR__, 2) . '/src/Modules';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            $relative = substr($path, strlen(str_replace(DIRECTORY_SEPARATOR, '/', $root)) + 1);
            $parts = explode('/', $relative);
            if (in_array($parts[1] ?? '', self::OWNERS, true) || in_array($relative, self::EXCEPTIONS, true)) {
                continue;
            }
            if (preg_match('/\b(?:FROM|JOIN)\s+(?:users|people)\b/i', (string) file_get_contents($path), $match) === 1) {
                $offenders[] = $relative . ' : ' . $match[0];
            }
        }

        self::assertSame([], $offenders, 'Lecture directe des tables users/people hors Identity et People : utilisez leurs contrats.');
    }
}
