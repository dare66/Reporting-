<?php

namespace App\Domain\Semantic;

use App\Models\SemanticModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/** Loads tenant-scoped catalogs, memoised per request. */
class CatalogRepository
{
    /** @var array<string, Catalog> */
    private array $memo = [];

    public function get(string $keyOrId): Catalog
    {
        if (isset($this->memo[$keyOrId])) {
            return $this->memo[$keyOrId];
        }

        $model = SemanticModel::query()
            ->when(Str::isUuid($keyOrId), fn ($q) => $q->where('id', $keyOrId), fn ($q) => $q->where('key', $keyOrId))
            ->first() ?? throw (new ModelNotFoundException)->setModel(SemanticModel::class, [$keyOrId]);

        return $this->memo[$keyOrId] = Catalog::fromModel($model);
    }

    public function default(): Catalog
    {
        $model = SemanticModel::query()->orderBy('created_at')->firstOrFail();

        return $this->get($model->id);
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}
