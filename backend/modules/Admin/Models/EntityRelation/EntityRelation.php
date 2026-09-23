<?php

namespace Modules\Admin\Models\EntityRelation;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Admin\Models\Project\Project;

#[Fillable([
    'project_id',
    'source_type',
    'source_id',
    'target_type',
    'target_id',
    'relation_type',
])]
final class EntityRelation extends Model
{
    public const ENTITY_TYPE_ASSET = 'asset';

    public const RELATION_TYPE_CONTAINS = 'contains';

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'source_id' => 'integer',
            'target_id' => 'integer',
        ];
    }
}
