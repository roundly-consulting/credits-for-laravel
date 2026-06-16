<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Traits\HasCredits;

/**
 * In-memory fixture model used to exercise the HasCredits trait in tests.
 *
 * @property int $id
 * @property string|null $name
 */
final class User extends Model implements Creditable
{
    use HasCredits;

    protected $table = 'users';

    protected $guarded = [];
}
