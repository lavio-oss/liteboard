<?php

namespace Modules\Core\Shared\Responders;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

final class Responder
{
    public function resource(JsonResource $resource, int $status = Response::HTTP_OK): JsonResponse
    {
        return $resource->response()->setStatusCode($status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json($data, $status);
    }

    public function noContent(): Response
    {
        return response()->noContent();
    }
}
