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
