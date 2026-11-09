<?php

use App\Enums\GridGrade;

it('compares grades from best to worst', function () {
    expect(GridGrade::A->isAtLeast(GridGrade::B))->toBeTrue()
        ->and(GridGrade::B->isAtLeast(GridGrade::B))->toBeTrue()
        ->and(GridGrade::BPlus->isAtLeast(GridGrade::A))->toBeFalse()
        ->and(GridGrade::C->isAtLeast(GridGrade::CPlus))->toBeFalse()
        ->and(GridGrade::APlus->isAtLeast(GridGrade::C))->toBeTrue();
});
