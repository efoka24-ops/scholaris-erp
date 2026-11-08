<?php

declare(strict_types=1);

namespace Scholaris\Modules\Apps\Secondary\Councils\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Scholaris\Core\Http\HttpException;
use Scholaris\Core\Http\JsonResponder;
use Scholaris\Modules\Apps\Secondary\Councils\Application\ReportCardPreview;
use Scholaris\Modules\Apps\Secondary\Councils\Application\ReportCardSettings;
use Scholaris\Modules\Apps\Secondary\Councils\Application\RuleDraftService;
use Scholaris\Modules\Apps\Secondary\Support\Access;
use Scholaris\Modules\Apps\Secondary\Support\Controller;
use Scholaris\Modules\Apps\Secondary\Support\Store;

/** API du conseil de classe du secondaire (T900, T904) : brouillon de règles du niveau, paramètres et aperçu des bulletins. */
final class CouncilsController extends Controller
{
    public function __construct(
        JsonResponder $responder,
        Access $access,
        Store $store,
        private readonly RuleDraftService $drafts,
        private readonly ReportCardSettings $settings,
        private readonly ReportCardPreview $preview,
    ) {
        parent::__construct($responder, $access, $store);
    }

    public function ruleDraft(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $query = $request->getQueryParams();
        $classId = $query['class_id'] ?? null;
        $scope = $query['scope'] ?? 'level';
        if (!is_string($classId) || $classId === '' || strlen($classId) > 40) {
            throw HttpException::validation(['class_id' => ['Paramètre obligatoire (40 caractères au plus).']]);
        }
        if (!in_array($scope, ['level', 'class'], true)) {
            throw HttpException::validation(['scope' => ['« level » (niveau) ou « class » (classe, série propre).']]);
        }

        return $this->json($this->drafts->draft($principal, $establishmentId, $classId, $scope));
    }

    public function settings(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->settings->show($principal, $establishmentId));
    }

    public function presetSettings(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);

        return $this->json($this->settings->preset($principal, $establishmentId));
    }

    public function reportCard(ServerRequestInterface $request): ResponseInterface
    {
        [$principal, $establishmentId] = $this->context($request);
        $locale = $request->getQueryParams()['locale'] ?? null;

        return $this->json($this->preview->show($principal, $establishmentId, self::id($request, 'councilId'), self::id($request, 'personId'), in_array($locale, ['fr', 'en'], true) ? $locale : null));
    }
}
