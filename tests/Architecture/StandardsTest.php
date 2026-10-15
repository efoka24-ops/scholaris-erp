<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Règles d'architecture vérifiées automatiquement (voir constitution). */
final class StandardsTest extends TestCase
{
    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];
        if (!is_dir($directory)) {
            return $files;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function testToutFichierDeSrcDeclareLeTypageStrict(): void
    {
        $missing = [];
        foreach ($this->phpFiles(dirname(__DIR__, 2) . '/src') as $file) {
            if (!str_contains((string) file_get_contents($file), 'declare(strict_types=1);')) {
                $missing[] = $file;
            }
        }

        self::assertSame([], $missing, 'Fichiers sans declare(strict_types=1).');
    }

    public function testAucuneFonctionDeDebogageDansSrc(): void
    {
        $offenders = [];
        foreach ($this->phpFiles(dirname(__DIR__, 2) . '/src') as $file) {
            if (preg_match('/\b(var_dump|print_r|dd|dump)\s*\(/', (string) file_get_contents($file)) === 1) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders);
    }

    public function testLesApplicationsNeDependentQueDesContratsDuSocle(): void
    {
        $offenders = [];
        $pattern = '/Scholaris\\\\Modules\\\\Socle\\\\\w+\\\\(?!Contracts\\\\)/';
        foreach ($this->phpFiles(dirname(__DIR__, 2) . '/src/Modules/Apps') as $file) {
            if (preg_match($pattern, (string) file_get_contents($file)) === 1) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'Une application ne doit utiliser que les Contracts du socle.');
    }

    public function testUnModuleDuSocleNeDependPasDesInternesDUnAutreModule(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Modules/Socle/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $module = basename($moduleDir);
            foreach ($this->phpFiles($moduleDir) as $file) {
                if (preg_match_all('/Scholaris\\\\Modules\\\\Socle\\\\(\w+)\\\\(\w+)/', (string) file_get_contents($file), $matches, PREG_SET_ORDER) === 0) {
                    continue;
                }
                foreach ($matches as $m) {
                    if ($m[1] !== $module && $m[2] !== 'Contracts') {
                        $offenders[] = basename($file) . ' -> ' . $m[0];
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'Un module du socle ne doit utiliser que les Contracts des autres modules.');
    }

    public function testLeNoyauNeDependPasDesModulesSaufAuCablage(): void
    {
        $offenders = [];
        foreach ($this->phpFiles(dirname(__DIR__, 2) . '/src/Core') as $file) {
            if (str_contains($file, 'Container' . DIRECTORY_SEPARATOR . 'ContainerFactory.php')
                || str_contains($file, 'Console' . DIRECTORY_SEPARATOR . 'Application.php')) {
                continue;
            }
            if (str_contains((string) file_get_contents($file), 'Scholaris\\Modules\\')) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'Le noyau ne dépend que d\'interfaces ; seul le câblage connaît les modules.');
    }
}
