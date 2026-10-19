<?php

namespace Modules\Admin\Actions\Overview\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Overview\ShowProjectOverviewRequest;
use Modules\Admin\Services\Overview\ProjectOverviewService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowProjectOverviewAction
{
    public function __invoke(
        ShowProjectOverviewRequest $request,
        Tenant $tenant,
        Project $project,
        ProjectOverviewService $projectOverviewService,
        Responder $responder,
    ): Response {
        return $responder->json([
            'data' => $projectOverviewService->summarize($project, $request->includesPipeline($project), now()->toImmutable()),
        ]);
    }
}
