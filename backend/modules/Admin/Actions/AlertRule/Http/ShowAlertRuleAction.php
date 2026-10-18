<?php

namespace Modules\Admin\Actions\AlertRule\Http;

use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\AlertRule\ShowAlertRuleRequest;
use Modules\Admin\Resources\AlertRule\AlertRuleResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowAlertRuleAction
{
    public function __invoke(
        ShowAlertRuleRequest $request,
        Tenant $tenant,
        Project $project,
        AlertRule $alertRule,
        Responder $responder,
    ): Response {
        $alertRule->load([
            'currentRevision',
            'revisions' => static fn ($query) => $query->orderBy('revision'),
        ]);

        return $responder->resource(AlertRuleResource::make($alertRule));
    }
}
