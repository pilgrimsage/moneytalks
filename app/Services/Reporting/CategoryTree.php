<?php

namespace App\Services\Reporting;

use App\Models\Category;

/** Category hierarchy helpers, always scoped to one user. */
class CategoryTree
{
    /** @return list<string> the category id plus every descendant id */
    public function withDescendants(string $userId, string $id): array
    {
        $parents = Category::where('user_id', $userId)->pluck('parent_id', 'id');
        $ids = [$id];
        for ($i = 0; $i < 5; $i++) {
            $new = array_values(array_diff($parents->filter(fn ($p) => in_array($p, $ids, true))->keys()->all(), $ids));
            if ($new === []) {
                break;
            }
            $ids = array_merge($ids, $new);
        }

        return $ids;
    }

    /** @return list<string> the category id plus every ancestor id (a Food budget covers a Vegetables expense) */
    public function withAncestors(string $userId, string $id): array
    {
        $parents = Category::where('user_id', $userId)->pluck('parent_id', 'id');
        $ids = [$id];
        for ($i = 0; $i < 5 && ($p = $parents[$id] ?? null) !== null; $i++) {
            $ids[] = $id = $p;
        }

        return $ids;
    }
}
