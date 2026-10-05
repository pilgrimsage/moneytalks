<?php

use App\Services\Interpretation\AmountNormalizer;

beforeEach(fn () => $this->n = new AmountNormalizer);

it('extracts the numbers a user types', function (string $text, array $expected) {
    expect($this->n->extract($text))->toBe($expected);
})->with([
    'plain' => ['spent 250 on vegetables', ['250']],
    'decimal' => ['paid 1200.50 for fuel', ['1200.5']],
    'rupee sign' => ['₹500 petrol', ['500']],
    'rs prefix' => ['rs. 750 groceries', ['750']],
    'k suffix' => ['rahul se 2k liya', ['2000']],
    'decimal k' => ['gave 1.5k', ['1500']],
    'K upper' => ['salary 45K', ['45000']],
    'lakh' => ['saved 2 lakh', ['200000']],
    'decimal lakh' => ['bonus 1.5 lakh', ['150000']],
    'lac' => ['car 1.2 lac', ['120000']],
    'L suffix' => ['bike 2L', ['200000']],
    'crore' => ['flat 1 crore', ['10000000']],
    'indian grouping' => ['rent ₹1,20,000', ['120000']],
    'western grouping' => ['paid 1,234,567', ['1234567']],
    'devanagari digits' => ['४५० की सब्जी', ['450']],
    'ordinal dates are not amounts' => ['Netflix 649 on the 5th', ['649']],
    'several numbers' => ['3 items 250 each', ['3', '250']],
    'no numbers' => ['teen sau pachaas ka uber', []],
    'k inside a word is not a suffix' => ['5 kilo sabji 200', ['5', '200']],
    'l inside a word is not a suffix' => ['2 lunch 150', ['2', '150']],
]);

it('cross-checks the model\'s amount against the text', function (string $amount, string $text, ?bool $expected) {
    expect($this->n->matches($amount, $text))->toBe($expected);
})->with([
    'exact' => ['250', 'spent 250 on vegetables', true],
    'trailing zeros ignored' => ['250.00', 'spent 250 on vegetables', true],
    'k expanded' => ['2000', 'rahul se 2k liya', true],
    'lakh expanded' => ['200000', 'saved 2 lakh', true],
    'devanagari' => ['450', '४५० की सब्जी', true],
    'model invented a different amount' => ['2500', 'spent 250 on vegetables', false],
    'model summed numbers' => ['750', '3 items 250 each', false],
    'no digits in text: cannot verify' => ['350', 'aaj teen sau pachaas ka uber', null],
]);

it('never uses floats: large and precise values stay exact', function () {
    expect($this->n->extract('9999999999.99 and 0.07'))->toBe(['9999999999.99', '0.07'])
        ->and($this->n->canonical('007.500'))->toBe('7.5')
        ->and($this->n->canonical('0'))->toBe('0');
});
