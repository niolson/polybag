<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use ReflectionMethod;

/**
 * Overrides Filament's default global search to split search terms into
 * words and require ALL words to match at least one searchable column.
 *
 * Uses the model's toSearchableArray() to determine which columns to search,
 * and respects SearchUsingPrefix for prefix-only matching.
 */
trait InteractsWithScoutSearch
{
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $model = new (static::getModel());
        $table = $model->getTable();
        $columns = array_keys($model->toSearchableArray());

        $prefixColumns = [];
        foreach ((new ReflectionMethod($model, 'toSearchableArray'))->getAttributes(SearchUsingPrefix::class) as $attribute) {
            $prefixColumns = array_merge($prefixColumns, Arr::wrap($attribute->getArguments()[0]));
        }

        $terms = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($terms)) {
            $query->whereRaw('0 = 1');

            return;
        }

        // Each term must match at least one searchable column
        foreach ($terms as $term) {
            $query->where(function (Builder $q) use ($term, $columns, $prefixColumns, $table): void {
                foreach ($columns as $column) {
                    $pattern = in_array($column, $prefixColumns) ? $term.'%' : '%'.$term.'%';
                    $q->orWhere("{$table}.{$column}", 'like', $pattern);
                }
            });
        }
    }
}
