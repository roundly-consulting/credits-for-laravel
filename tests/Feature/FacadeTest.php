<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Facades\Credits;

it('documents its root, is fakeable and reaches every action', function (): void {
    expect(Credits::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});
