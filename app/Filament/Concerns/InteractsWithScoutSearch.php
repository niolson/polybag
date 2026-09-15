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
        $columns = array_keys($model->toSearchableArray());

        $prefixColumns = [];
        foreach ((new ReflectionMethod($model, 'toSearchableArray'))->getAttributes(SearchUsingPrefix::class) as $attribute) {
            $prefixColumns = array_merge($prefixColumns, Arr::wrap($attribute->getArguments()[0]));
        }

        static::applyGlobalSearchTerms($query, $search, $model->getTable(), $columns, $prefixColumns);
    }

    /**
     * The words of a search, as the constraints below match them.
     *
     * @return array<int, string>
     */
    protected static function globalSearchTerms(string $search): array
    {
        return preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Require every word of the search to match at least one of the columns.
     *
     * The same rules for any table: a resource that also searches a related
     * table applies them there with that table's columns.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $prefixColumns
     */
    protected static function applyGlobalSearchTerms(Builder $query, string $search, string $table, array $columns, array $prefixColumns): void
    {
        $terms = static::globalSearchTerms($search);

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
