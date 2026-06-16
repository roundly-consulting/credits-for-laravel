<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests\Fixtures;

use RoundlyConsulting\Credits\Models\Credit;

/**
 * Subclass used to exercise the `credits.model` override.
 */
class CustomCredit extends Credit
{
    protected $table = 'credits';
}
