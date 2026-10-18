<?php

namespace Modules\Admin\Actions\AlertRule\Http;

use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\AlertRule\StoreAlertRuleRevisionRequest;
use Modules\Admin\Resources\AlertRule\AlertRuleResource;
use Modules\Admin\Services\AlertRule\AlertRulePersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateAlertRuleRevisionAction
{
    public function __invoke(
        StoreAlertRuleRevisionRequest $request,
        Tenant $tenant,
        Project $project,
        AlertRule $alertRule,
        AlertRulePersistenceService $alertRulePersistenceService,
        Responder $responder,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $rule = $alertRulePersistenceService->createRevision($alertRule, $request->definition(), $user);
        $rule->load([
            'currentRevision',
            'revisions' => static fn ($query) => $query->orderBy('revision'),
        ]);

        return $responder->resource(AlertRuleResource::make($rule), Response::HTTP_CREATED);
    }
}
