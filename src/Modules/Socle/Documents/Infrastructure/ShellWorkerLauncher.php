<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Infrastructure;

/**
 * Lancement détaché par l'interpréteur de commandes : `php artisan … > /dev/null 2>&1 &` (Linux) ou `start /B` (Windows).
 * Sur un hébergement mutualisé où `exec`, `popen` ou `proc_open` sont désactivés, renvoie faux : l'appelant traite alors
 * la file lui-même, dans sa limite de temps.
 */
final class ShellWorkerLauncher implements WorkerLauncher
{
    public function __construct(private readonly string $basePath, private readonly string $php = PHP_BINARY)
    {
    }

    public function launch(array $arguments): bool
    {
        $artisan = $this->basePath . '/artisan';
        if ($this->php === '' || !is_file($artisan)) {
            return false;
        }
        $command = escapeshellarg($this->php) . ' ' . escapeshellarg($artisan);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            if (!self::enabled('popen') || !self::enabled('pclose')) {
                return false;
            }
            $handle = popen('start "" /B ' . $command . ' > NUL 2>&1', 'r');
            if ($handle === false) {
                return false;
            }
            pclose($handle);

            return true;
        }
        if (!self::enabled('exec')) {
            return false;
        }
        $output = [];
        $code = 1;
        exec($command . ' > /dev/null 2>&1 &', $output, $code);

        return $code === 0;
    }

    private static function enabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists($function) && !in_array($function, $disabled, true);
    }
}
