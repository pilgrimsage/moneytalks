<?php

use App\Support\Text;

it('normalises case, punctuation and whitespace', function () {
    expect(Text::normalize('  Sabji!!  '))->toBe('sabji')
        ->and(Text::normalize('Tea/Coffee'))->toBe('tea coffee')
        ->and(Text::normalize("Ghar  ka\tKharcha"))->toBe('ghar ka kharcha');
});

it('keeps Devanagari letters and vowel signs intact', function () {
    expect(Text::normalize('सब्जी'))->toBe('सब्जी')
        ->and(Text::normalize('घर का खर्चा।'))->toBe('घर का खर्चा');
});

it('computes multibyte edit distance', function () {
    expect(Text::distance('vegtables', 'vegetables'))->toBe(1)
        ->and(Text::distance('सब्जी', 'सब्जी'))->toBe(0)
        ->and(Text::similarity('abc', 'abc'))->toBe(1.0);
});
