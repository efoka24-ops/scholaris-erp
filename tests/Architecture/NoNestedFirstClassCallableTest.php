<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Défaut du moteur PHP 8.1 (version du serveur) : quand une exception est levée pendant l'évaluation des arguments d'un appel
 * C0, par un appel C1 dont les arguments contiennent un callable de première classe (`X::m(...)`, `$o->m(...)`, `f(...)`), le
 * nettoyage des appels inachevés lit mal la pile et PHP plante (segmentation fault) au lieu de propager l'exception. Cas réel :
 * `$this->json(Resources::tutelage($this->readable(..., AuthorizationService::scope(...))))` avec un identifiant inexistant
 * (404 attendu) tuait le processus.
 *
 * Cause : pour libérer les arguments déjà passés à C0, le moteur les compte en remontant les instructions et apparie début et
 * fin d'appel ; la conversion d'un callable de première classe commence comme un appel sans se terminer comme lui : le compte se
 * décale et PHP libère des valeurs qui n'existent pas.
 *
 * Règle : un callable de première classe ne figure jamais dans le deuxième argument (ou un suivant) d'un appel lui-même imbriqué
 * dans les arguments d'un autre appel (ou d'un `new`). Il suffit de sortir l'appel intérieur dans une variable locale.
 * La règle vaut pour tout `src/`, sans exception.
 */
final class NoNestedFirstClassCallableTest extends TestCase
{
    public function testAucunCallableDePremiereClasseDansUnAppelImbrique(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            foreach (self::offendingLines((string) file_get_contents($file->getPathname())) as $line) {
                $offenders[] = $relative . ':' . $line;
            }
        }

        self::assertSame([], $offenders, "Callable de première classe dans un appel imbriqué (plantage de PHP 8.1 si l'appel intérieur lève une exception) ; sortir l'appel intérieur dans une variable :\n" . implode("\n", $offenders));
    }

    public function testLeDetecteurReconnaitLeMotifDangereux(): void
    {
        $code = <<<'PHP'
            <?php
            $a = $this->json(Resources::tutelage($this->readable($request, 't', $id, [], AuthorizationService::scope(...))));
            $b = new ArrayObject(thrower(1, strlen(...)));
            $c = R::wrap([$o->m(1, $o->n(...))]);
            $d = R::wrap(fn () => R::wrap(thrower(1, S::scope(...))));
            $ok1 = $this->readable($request, 't', $id, [], AuthorizationService::scope(...));
            $ok2 = R::two(S::scope(...), thrower());
            $ok3 = R::wrap(thrower(1, fn ($r) => S::scope(...)));
            $ok4 = array_map(S::scope(...), $rows);
            $ok5 = $this->json(['data' => array_map(S::scope(...), $rows)]);
            $e = HttpException::conflict('x' . implode(', ', array_map(K::label(...), $kinds)));
            $f = R::two(1, 2, usort($rows, S::compare(...)));
            PHP;

        self::assertSame([2, 3, 4, 5, 11, 12], self::offendingLines($code));
    }

    /** @return list<int> */
    private static function offendingLines(string $code): array
    {
        $lines = [];
        foreach ((new ParserFactory())->createForHostVersion()->parse($code) ?? [] as $node) {
            self::walk($node, $lines);
        }
        sort($lines);

        return array_values(array_unique($lines));
    }

    /** @param list<int> $lines */
    private static function walk(Node $node, array &$lines): void
    {
        foreach (self::arguments($node) as $argument) {
            self::scanOuterArgument($argument, $lines);
        }
        foreach (self::children($node) as $child) {
            self::walk($child, $lines);
        }
    }

    /**
     * Dans un argument d'un appel C0 : un appel C1 dont le deuxième argument ou un suivant contient un callable de première
     * classe. Dans le premier argument de C1, le compte faussé reste inférieur au vrai (fuite, sans plantage) : la forme
     * courante `$this->json(array_map(X::m(...), $rows))` reste permise.
     *
     * @param list<int> $lines
     */
    private static function scanOuterArgument(Node $node, array &$lines): void
    {
        if (self::isFunctionBody($node)) {
            return;
        }
        foreach (self::arguments($node) as $position => $argument) {
            if ($position > 0 && self::containsFirstClass($argument)) {
                $lines[] = $node->getStartLine();

                return;
            }
        }
        foreach (self::children($node) as $child) {
            self::scanOuterArgument($child, $lines);
        }
    }

    private static function containsFirstClass(Node $node): bool
    {
        if (self::isFunctionBody($node)) {
            return false;
        }
        if ($node instanceof Node\Expr\CallLike && $node->isFirstClassCallable()) {
            return true;
        }
        foreach (self::children($node) as $child) {
            if (self::containsFirstClass($child)) {
                return true;
            }
        }

        return false;
    }

    /** Corps de fonction séparé (fermeture, fonction fléchée, classe anonyme) : sa propre pile d'appels. */
    private static function isFunctionBody(Node $node): bool
    {
        return $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Stmt\Class_;
    }

    /** @return list<Node> arguments d'un appel ordinaire (pas d'un callable de première classe) */
    private static function arguments(Node $node): array
    {
        if (!$node instanceof Node\Expr\CallLike || $node->isFirstClassCallable()) {
            return [];
        }

        return array_values($node->getRawArgs());
    }

    /** @return list<Node> */
    private static function children(Node $node): array
    {
        $children = [];
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item instanceof Node) {
                    $children[] = $item;
                }
            }
        }

        return $children;
    }
}
