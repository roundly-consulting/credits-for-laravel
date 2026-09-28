<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Traits\HasCredits;

/**
 * A uuid-keyed creditable owner — the host shape `credits.key_type = uuid` exists for.
 *
 * @property string $id
 * @property string|null $name
 */
final class UuidUser extends Model implements Creditable
{
    use HasCredits;
    use HasUuids;

    protected $table = 'uuid_users';

    protected $guarded = [];
}
