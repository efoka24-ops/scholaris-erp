<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Orientation\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Councils\Orientation\Application\OrientationSheet;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Input;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** DOC-16 fiche d'orientation [orientation] : émission par le conseiller d'orientation (`secondary.orientation.manage`). */
final class OrientationSheetController extends Controller
{
    public function __construct(JsonResponder $responder, Access $access, Store $store, private readonly OrientationSheet $sheets)
    {
        parent::__construct($responder, $access, $store);
    }

    public function issue(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $this->access->require($principal, $establishmentId, OrientationSheet::ISSUERS);
        $input = Input::of($request);
        $year = $input->reference('academic_year_id');
        $locale = $input->enum('locale', ['fr', 'en'], false);
        $input->done();

        return $this->json($this->sheets->issue($principal, $establishmentId, self::id($request, 'personId'), (string) $year, $locale), 201);
    }
}
