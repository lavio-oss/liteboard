<?php

namespace Modules\Admin\Actions\AlertRule\Http;

use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\AlertRule\ArchiveAlertRuleRequest;
use Modules\Admin\Resources\AlertRule\AlertRuleResource;
use Modules\Admin\Services\AlertRule\AlertRulePersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ArchiveAlertRuleAction
{
    public function __invoke(
        ArchiveAlertRuleRequest $request,
        Tenant $tenant,
        Project $project,
        AlertRule $alertRule,
        AlertRulePersistenceService $alertRulePersistenceService,
        Responder $responder,
    ): Response {
        $rule = $alertRulePersistenceService->archive($alertRule);
        $rule->load('currentRevision');

        return $responder->resource(AlertRuleResource::make($rule));
    }
}
