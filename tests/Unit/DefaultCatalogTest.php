<?php

use App\Domain\Finance\DefaultCatalog;
use App\Support\Text;

function catalogAliases(array $nodes): array
{
    $out = [];
    foreach ($nodes as $node) {
        foreach ($node['aliases'] ?? [] as $a) {
            $out[] = Text::normalize($a);
        }
        $out = array_merge($out, catalogAliases($node['children'] ?? []));
    }

    return $out;
}

it('has no duplicate category aliases (so seeding can never silently drop one)', function () {
    $all = array_merge(
        catalogAliases(DefaultCatalog::categories()['expense']),
        catalogAliases(DefaultCatalog::categories()['income']),
    );

    expect(array_diff_assoc($all, array_unique($all)))->toBe([]);
});

it('has no duplicate merchant aliases', function () {
    $all = [];
    foreach (DefaultCatalog::merchants() as $m) {
        foreach ($m['aliases'] as $a) {
            $all[] = Text::normalize($a);
        }
    }
    expect(array_diff_assoc($all, array_unique($all)))->toBe([]);
});

it('points every merchant at an existing category', function () {
    $names = [];
    $walk = function (array $nodes) use (&$walk, &$names) {
        foreach ($nodes as $name => $node) {
            $names[] = $name;
            $walk($node['children'] ?? []);
        }
    };
    $walk(DefaultCatalog::categories()['expense']);

    foreach (DefaultCatalog::merchants() as $merchant => $def) {
        expect($names)->toContain($def['default_category']);
    }
});
