<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Traits\HasCredits;

/**
 * A creditable owner that lives on a second connection (`tenant`), the way a multi-tenant
 * host pins its models. The packaged ledger model names no connection, so the ledger rows
 * belong on the owner's connection — reads included.
 *
 * @property int $id
 * @property string|null $name
 */
final class TenantUser extends Model implements Creditable
{
    use HasCredits;

    protected $connection = 'tenant';

    protected $table = 'users';

    protected $guarded = [];
}
