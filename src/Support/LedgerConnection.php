<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;

/**
 * The one connection an owner's ledger lives on: the ledger model's own connection, else the
 * owner's (Eloquent hands a connection-less related model its parent's). The balance and total
 * reads, the ledger write, the owner lock and the transaction around them all run here, so a
 * read never answers from another database than the one the write lands in.
 *
 * @internal
 */
final class LedgerConnection
{
    public static function of(Model&Creditable $creditable): Connection
    {
        return $creditable->credits()->getRelated()->getConnection();
    }
}
