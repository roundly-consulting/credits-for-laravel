<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests\Fixtures;

use RoundlyConsulting\Credits\Models\Credit;

/**
 * A `credits.model` subclass that keeps the ledger on its own connection (`ledger`), while
 * the owners stay on the default one. The lock, the transaction and every read must follow
 * the ledger there, or the write runs outside the transaction that holds the lock.
 */
final class LedgerCredit extends Credit
{
    protected $connection = 'ledger';

    protected $table = 'credits';
}
