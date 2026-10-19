<?php

namespace Modules\Admin\Models\Webhook;

use Carbon\CarbonImmutable;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Admin\Models\Project\Project;

/**
 * @property string $project_id
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property list<string> $event_types
 * @property list<string> $alert_rule_ids
 * @property bool $enabled
 * @property CarbonImmutable|null $secret_rotated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'url', 'event_types', 'alert_rule_ids', 'enabled'])]
#[Hidden(['secret'])]
final class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory, HasUuids;

    /** @return EloquentFactory<WebhookEndpoint> */
    protected static function newFactory(): EloquentFactory
    {
        return WebhookEndpointFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'event_types' => 'array',
            'alert_rule_ids' => 'array',
            'enabled' => 'boolean',
            'secret_rotated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
