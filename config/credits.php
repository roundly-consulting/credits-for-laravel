<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Models\Credit;

return [

    /*
    |--------------------------------------------------------------------------
    | Credit Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model used to store individual credit records. Override this
    | with your own subclass of the package model if you need to customise the
    | behaviour or table.
    |
    */

    'model' => Credit::class,

    /*
    |--------------------------------------------------------------------------
    | Key Type (outbound — the models a credit points at)
    |--------------------------------------------------------------------------
    |
    | The key type used for the polymorphic `creditable` column — the model that
    | holds the credits. It must match your creditable models' primary key:
    | "bigint" (the Laravel default), "uuid" or "ulid". Anything unrecognized
    | falls back to "bigint".
    |
    | This is your CREDITABLE model's key type, not the credits table's own — see
    | "primary_key_type" below. The two are independent: a host with uuid users
    | holding credits stored under a bigint credits id is perfectly ordinary. It
    | is fixed when the migration first runs, so choose it before publishing.
    |
    | Supported: "bigint", "uuid", "ulid"
    |
    */

    'key_type' => env('CREDITS_KEY_TYPE', 'bigint'),

    /*
    |--------------------------------------------------------------------------
    | Primary Key Type (inbound — the credits table's own id)
    |--------------------------------------------------------------------------
    |
    | The primary-key strategy of the credits table itself: "bigint" (the Laravel
    | default), "uuid" or "ulid". Anything unrecognized falls back to "bigint".
    |
    | This is the key OTHER packages' polymorphic columns point at. A morph column
    | (`likeable_id`, `reportable_id`, ...) defaults to an unsigned bigint, so on a
    | strict engine such as PostgreSQL a non-bigint credits id cannot be related to
    | polymorphically. Change this only if every morph target in your application
    | shares the same key type — see "Key types" in the README.
    |
    | It is fixed when the migration first runs, so choose it before publishing the
    | migrations.
    |
    */

    'primary_key_type' => env('CREDITS_PRIMARY_KEY_TYPE', 'bigint'),

    /*
    |--------------------------------------------------------------------------
    | Allow Overdraft
    |--------------------------------------------------------------------------
    |
    | When false (the default), a deduction that would drive the balance below
    | the configured minimum is rejected with an InsufficientCreditsException.
    | Set this to true to permit negative balances globally. Individual calls
    | can always opt in via the `allowOverdraft` argument.
    |
    */

    'allow_overdraft' => env('CREDITS_ALLOW_OVERDRAFT', false),

    /*
    |--------------------------------------------------------------------------
    | Minimum Balance
    |--------------------------------------------------------------------------
    |
    | The floor enforced when overdraft is disallowed. A deduction may not take
    | the balance below this value. Defaults to zero.
    |
    */

    'minimum_balance' => 0,

    /*
    |--------------------------------------------------------------------------
    | Default Bucket
    |--------------------------------------------------------------------------
    |
    | Credits can be partitioned into named buckets (for example "promotional"
    | and "purchased") that hold isolated balances on the same entity. When a
    | call omits the bucket, this value is used for both reads and writes, so a
    | balance query with no bucket returns this bucket's balance only — it does
    | not sum across every bucket. Existing single-pool usage lands here.
    |
    */

    'default_bucket' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Scale
    |--------------------------------------------------------------------------
    |
    | Credits are stored as whole integers to avoid floating-point drift. To
    | represent fractional credits, treat the stored value as minor units and
    | set this to the number of decimal places those minor units represent
    | (for example 2 means a stored value of 150 is displayed as 1.50). Only
    | the display helpers (`displayCredits()`, `displayCreditsBalance()`)
    | read it; the ledger itself never multiplies or divides by it. Defaults
    | to 0 (whole units).
    |
    */

    'scale' => 0,

    /*
    |--------------------------------------------------------------------------
    | Rounding Mode
    |--------------------------------------------------------------------------
    |
    | The default rounding mode applied by the display helpers when a requested
    | display scale is smaller than the stored scale and digits must be dropped.
    | Name one of PHP 8.4's native \RoundingMode cases in snake_case:
    |
    |   half_away_from_zero — halves away from zero (default; was PHP_ROUND_HALF_UP)
    |   half_towards_zero   — halves toward zero (was PHP_ROUND_HALF_DOWN)
    |   half_even           — halves to the nearest even, banker's rounding
    |   half_odd            — halves to the nearest odd
    |   towards_zero, away_from_zero, positive_infinity, negative_infinity
    |
    | An unknown value throws InvalidMoneyConfiguration on first use.
    | Individual calls may override this with a \RoundingMode argument.
    |
    */

    'rounding' => env('CREDITS_ROUNDING', 'half_away_from_zero'),

    /*
    |--------------------------------------------------------------------------
    | Modifiable Resolvers
    |--------------------------------------------------------------------------
    |
    | A list of closures invoked by the `credits:modify` command. Each closure
    | receives a `$modify` callback that applies the requested credit change to
    | a Creditable entity.
    |
    | Example:
    | function (Closure $modify): void {
    |     User::query()->each(fn (User $user) => $modify($user));
    | },
    |
    */

    'modifiable' => [
        //
    ],

];
