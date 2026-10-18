<?php

namespace Modules\Admin\Actions\AlertRule\Http;

use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\AlertRule\AlertRuleIndexRequest;
use Modules\Admin\Resources\AlertRule\AlertRuleResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListAlertRulesAction
{
    public function __invoke(
        AlertRuleIndexRequest $request,
        Tenant $tenant,
        Project $project,
        Responder $responder,
    ): Response {
        $rules = AlertRule::query()
            ->where('project_id', $project->getKey())
            ->with('currentRevision')
            ->orderBy('code')
            ->get();

        return $responder->resource(AlertRuleResource::collection($rules));
    }
}
