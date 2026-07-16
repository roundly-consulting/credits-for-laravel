<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests\Fixtures;

use RoundlyConsulting\Credits\Tests\TestCase;

/**
 * The suite's base case with `credits.model` already pointed at {@see CustomCredit}
 * BEFORE the providers boot.
 *
 * Boot order is the whole point: the providers hang observers and relationship wiring on
 * whatever `credits.model` names at boot. A `config()->set()` inside the test body reads
 * back correctly but leaves every listener on the packaged Credit — which is precisely the
 * shape that let media #28 ship, and precisely what the test this replaces
 * (`Unit/Models/CustomCreditModelTest.php`, a runtime `set` plus an `instanceof`) could
 * never have caught.
 *
 * Note the `array_merge(parent::configBeforeBoot(), …)`: dropping it would silently discard
 * whatever the base case wires, the same decapitation an un-parented `defineEnvironment()`
 * override causes one level up. It is empty today — that is not a reason to omit it.
 *
 * @see TestCase
 */
abstract class SwappedCreditTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'credits.model' => CustomCredit::class,
        ]);
    }
}
