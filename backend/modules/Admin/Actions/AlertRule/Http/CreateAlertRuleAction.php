<?php

namespace Modules\Admin\Actions\AlertRule\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\AlertRule\StoreAlertRuleRequest;
use Modules\Admin\Resources\AlertRule\AlertRuleResource;
use Modules\Admin\Services\AlertRule\AlertRulePersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateAlertRuleAction
{
    public function __invoke(
        StoreAlertRuleRequest $request,
        Tenant $tenant,
        Project $project,
        AlertRulePersistenceService $alertRulePersistenceService,
        Responder $responder,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $rule = $alertRulePersistenceService->create($project, $request->ruleAttributes(), $request->definition(), $user);
        $rule->load(['currentRevision', 'revisions']);

        return $responder->resource(AlertRuleResource::make($rule), Response::HTTP_CREATED);
    }
}
