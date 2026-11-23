<?php

declare(strict_types=1);

namespace Scholaris\Modules\Socle\Documents\Console;

use Scholaris\Core\Config\Config;
use Scholaris\Modules\Socle\Documents\Application\DocumentJobService;
use Scholaris\Modules\Socle\Documents\Infrastructure\WorkerLauncher;

/**
 * `documents:process-jobs [--max-jobs=N] [--chunk=N] [--max-seconds=S] [--workers=N] [--slot=K]` : traite la file de
 * génération en lot puis pré-rend les PDF des bulletins (C-118, NF-S2).
 *  - sans option de durée : un passage, comme avant ;
 *  - `--max-seconds=S` : passes successives jusqu'à vider la file, au plus S secondes ;
 *  - `--workers=N` : lance N processus indépendants en arrière-plan (créneaux 1 à N) et rend la main aussitôt ; si
 *    l'hébergement interdit de lancer un processus, traite la file lui-même dans la limite de `--max-seconds` ;
 *  - `--slot=K` : processus du créneau K, qui s'arrête aussitôt si ce créneau est déjà occupé (au plus N processus).
 * Plusieurs processus se partagent la file sans doublon (verrou par tâche et par bulletin, UPDATE conditionnel).
 */
final class ProcessDocumentJobsCommand
{
    private const USAGE = "Usage : artisan documents:process-jobs [--max-jobs=N] [--chunk=N] [--max-seconds=S] [--workers=N] [--slot=K]\n";

    public function __construct(
        private readonly DocumentJobService $jobs,
        private readonly WorkerLauncher $launcher,
        private readonly Config $config,
    ) {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        $maxJobs = 10;
        $chunk = null;
        $maxSeconds = 0;
        $workers = 0;
        $slot = 0;
        foreach ($args as $arg) {
            if (preg_match('/^--(max-jobs|chunk|max-seconds|workers|slot)=(\d{1,5})$/', $arg, $m) !== 1) {
                fwrite(STDERR, self::USAGE);

                return 2;
            }
            $value = (int) $m[2];
            match ($m[1]) {
                'max-jobs' => $maxJobs = max(1, $value),
                'chunk' => $chunk = max(1, $value),
                'max-seconds' => $maxSeconds = $value,
                'workers' => $workers = min(16, max(1, $value)),
                default => $slot = min(16, max(1, $value)),
            };
        }

        if ($workers > 0) {
            $launched = 0;
            for ($k = 1; $k <= $workers; $k++) {
                $arguments = ['documents:process-jobs', '--slot=' . $k];
                if ($maxSeconds > 0) {
                    $arguments[] = '--max-seconds=' . $maxSeconds;
                }
                if (!$this->launcher->launch($arguments)) {
                    break;
                }
                $launched++;
            }
            if ($launched > 0) {
                echo "Documents : {$launched} processus lancé(s) en arrière-plan.\n";

                return 0;
            }
            echo "Documents : lancement en arrière-plan impossible, traitement dans ce processus.\n";
        }

        $lock = null;
        if ($slot > 0) {
            $lock = $this->slot($slot);
            if ($lock === null) {
                echo "Documents : Créneau {$slot} déjà occupé, rien à faire.\n";

                return 0;
            }
        }
        try {
            $stats = $this->jobs->drain($maxJobs, $chunk, $maxSeconds);
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        echo "Documents : {$stats['jobs']} tâche(s) traitée(s), {$stats['done']} terminée(s), {$stats['failed']} en échec, {$stats['chunks']} tranche(s), "
            . "{$stats['rendered']} bulletin(s) pré-rendu(s), {$stats['render_failed']} rendu(s) en échec.\n";

        return $stats['failed'] > 0 ? 1 : 0;
    }

    /** @return resource|null verrou exclusif du créneau, ou null s'il est déjà pris par un autre processus */
    private function slot(int $slot)
    {
        $path = (string) $this->config->get('documents.worker_lock_path', 'storage/cache');
        $dir = preg_match('#^([A-Za-z]:)?[\\\\/]#', $path) === 1 ? $path : dirname(__DIR__, 5) . '/' . $path;
        if (!is_dir($dir)) {
            @mkdir($dir, 0o770, true);
        }
        $handle = @fopen($dir . '/documents-worker-' . $slot . '.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }
}
