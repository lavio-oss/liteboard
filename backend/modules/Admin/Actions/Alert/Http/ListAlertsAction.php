<?php

namespace Modules\Admin\Actions\Alert\Http;

use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Alert\AlertIndexRequest;
use Modules\Admin\Resources\Alert\AlertResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListAlertsAction
{
    public function __invoke(
        AlertIndexRequest $request,
        Tenant $tenant,
        Project $project,
        Responder $responder,
    ): Response {
        $filters = $request->filters();
        $alerts = Alert::query()
            ->where('project_id', $project->getKey())
            ->when($filters['state'] === 'active', static fn ($query) => $query->whereNull('cleared_at'))
            ->when($filters['state'] === 'cleared', static fn ($query) => $query->whereNotNull('cleared_at'))
            ->when($filters['acknowledged'] === true, static fn ($query) => $query->whereNotNull('acknowledged_at'))
            ->when($filters['acknowledged'] === false, static fn ($query) => $query->whereNull('acknowledged_at'))
            ->when($filters['device_id'] !== null, static fn ($query) => $query->where('device_id', $filters['device_id']))
            ->when($filters['alert_rule_id'] !== null, static fn ($query) => $query->where('alert_rule_id', $filters['alert_rule_id']))
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->cursorPaginate($filters['limit']);

        return $responder->resource(AlertResource::collection($alerts));
    }
}
