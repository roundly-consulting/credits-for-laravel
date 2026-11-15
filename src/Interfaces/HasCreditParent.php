<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Interfaces;

use Illuminate\Database\Eloquent\Model;

/**
 * An owner inside another one — a person inside a team inside a company. With
 * `credits.parents.enabled`, a debit or hold on this owner must also fit every ancestor's
 * available balance in the same bucket, and is written on every level.
 */
interface HasCreditParent
{
    /** The owner whose balance caps this one's, or null at the top. */
    public function creditParent(): (Model&Creditable)|null;
}
