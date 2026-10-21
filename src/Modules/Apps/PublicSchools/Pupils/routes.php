<?php

declare(strict_types=1);

use Scholaris\Modules\Apps\PublicSchools\Pupils\Http\ClassController;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Http\DropoutController;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Http\PupilController;
use Scholaris\Modules\Apps\PublicSchools\Pupils\Http\TransferController;
use Scholaris\Modules\Apps\PublicSchools\Support\PublicRoutes;

return static function (PublicRoutes $routes): void {
    $routes->post('/classes', [ClassController::class, 'create']);
    $routes->get('/classes', [ClassController::class, 'index']);
    $routes->get('/classes/distribution-proposal', [ClassController::class, 'distributionProposal']);
    $routes->get('/classes/{id}', [ClassController::class, 'show']);
    $routes->patch('/classes/{id}', [ClassController::class, 'update']);
    $routes->put('/classes/{id}/homeroom', [ClassController::class, 'assignHomeroom']);
    $routes->get('/classes/{id}/homeroom-history', [ClassController::class, 'homeroomHistory']);
    $routes->get('/classes/{id}/headcount', [ClassController::class, 'headcount']);

    $routes->post('/pupils', [PupilController::class, 'register']);
    $routes->get('/pupils/without-birth-act', [PupilController::class, 'withoutBirthAct']);
    $routes->post('/pupils/without-birth-act/transmissions', [PupilController::class, 'transmit']);
    $routes->get('/pupils/without-birth-act/transmissions', [PupilController::class, 'transmissions']);
    $routes->get('/pupils/{id}', [PupilController::class, 'show']);
    $routes->patch('/pupils/{id}', [PupilController::class, 'update']);

    $routes->post('/transfers', [TransferController::class, 'request']);
    $routes->get('/transfers', [TransferController::class, 'index']);
    $routes->get('/transfers/{id}', [TransferController::class, 'show']);
    $routes->post('/transfers/{id}/accept', [TransferController::class, 'accept']);
    $routes->post('/transfers/{id}/complete', [TransferController::class, 'complete']);
    $routes->post('/transfers/{id}/refuse', [TransferController::class, 'refuse']);
    $routes->post('/transfers/{id}/exeat', [TransferController::class, 'exeat']);
    $routes->get('/transfers/{id}/dossier', [TransferController::class, 'dossier']);

    $routes->get('/dropouts', [DropoutController::class, 'index']);
    $routes->post('/dropouts', [DropoutController::class, 'create']);
    $routes->post('/dropouts/{id}/contact', [DropoutController::class, 'contact']);
    $routes->post('/dropouts/{id}/resolve', [DropoutController::class, 'resolve']);
};
