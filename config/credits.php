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
    | Primary Key Type
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
    | (for example 2 means a stored value of 150 is displayed as 1.50). The
    | package never multiplies or divides by this value — formatting and
    | parsing happen in the host application; this key documents the chosen
    | convention so every consumer agrees on it. Defaults to 0 (whole units).
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
    | Use one of PHP's rounding constants:
    |
    |   PHP_ROUND_HALF_UP   — round halves away from zero (default)
    |   PHP_ROUND_HALF_DOWN — round halves toward zero
    |   PHP_ROUND_HALF_EVEN — round halves to the nearest even (banker's rounding)
    |   PHP_ROUND_HALF_ODD  — round halves to the nearest odd
    |
    | Individual calls may override this via the `rounding` argument.
    |
    */

    'rounding' => PHP_ROUND_HALF_UP,

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
