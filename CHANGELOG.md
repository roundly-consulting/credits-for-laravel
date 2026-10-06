# Changelog

All notable changes to `credits-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Changed

- Under `Credits::fake()`, a change, a `setTo()` delta or a total that does not fit the signed
  64-bit ledger now throws money's `RoundlyConsulting\Money\Exceptions\AmountOverflow`, as the
  real ledger does. A host test that overflowed under the fake used to get a `TypeError`; expect
  `AmountOverflow` instead.

### Fixed

- `Credits::fake()` refuses an int64 overflow exactly like the real ledger. A change whose
  resulting bucket balance does not fit (`add()` / `deduct()`, `modifyCredits()`,
  `modifyCreditsMoney()`, `Credits::modify()`), or a `setTo()` / `setCreditsTo()` whose delta
  does not fit, throws `AmountOverflow` with the real ledger's message and is not recorded.
  `balance()` and `total()` now add the fake's changes exactly, so a total across buckets past
  int64 throws `AmountOverflow`. The fake used to record the change, and the next balance read
  failed with a `TypeError`.

## 1.1.0 - 2026-10-06

### Added

- `InsufficientCreditsException::$minimum`: the `credits.minimum_balance` floor the guard
  enforced, so `$available - $minimum` is what could be spent. It is a new, defaulted fourth
  constructor argument; the existing properties are unchanged.

### Changed

- A change, a `setTo()` delta or a cross-bucket total that does not fit the signed 64-bit ledger
  now throws money's `RoundlyConsulting\Money\Exceptions\AmountOverflow` (the exception
  `modifyCreditsMoney()` already throws for a single oversized amount). Callers used to see a
  `TypeError`, a capped total or a `QueryException`; catch `AmountOverflow` where you handle very
  large balances.
- `CreditsModified` implements `ShouldDispatchAfterCommit`: inside `setCreditsTo()` or a host
  `DB::transaction()` it reaches listeners only once the outermost transaction commits, and never
  on a rollback. A listener that throws no longer rolls the change (or the host transaction)
  back; if you relied on that, check before making the change instead.
- With a non-zero `credits.minimum_balance`, the `InsufficientCreditsException` message names what
  can be spent above the floor ("… only 50 can be spent above the minimum balance of 50."), from
  the new `credits::messages.insufficient_above_minimum` line (English and Slovak). With no floor
  the wording is unchanged. Hosts that published the translations get the new line from the
  package until they add it to their copy.
- `credits:modify` requires `--amount` and refuses `--amount=0`: both now print an error and exit
  `1` before any resolver runs. A script that omitted `--amount` (or passed 0) must pass a
  non-zero amount.
- Documentation: the supported databases are stated — MySQL and PostgreSQL, SQLite for tests. SQL
  Server is not supported. The README hero image loads from its absolute URL, so it also renders
  on Packagist.

### Fixed

- One ledger connection: an owner on another database connection, or a `credits.model` with its
  own `$connection`, now reads its balance and total (`creditsBalance()`, `totalCreditsBalance()`,
  `Credits::balance()` / `total()`) from the connection its ledger rows are written to, and the
  owner lock and the transaction around a change run there too. Reads used to go to the default
  connection, so such an owner saw a balance of 0 and the overdraft guard could decide on another
  database's rows; a ledger model on its own connection was written outside the transaction that
  held the lock.
- A change whose resulting bucket balance does not fit the signed 64-bit ledger
  (`modifyCredits()`, `modifyCreditsMoney()`, `add()` / `deduct()`) or a `setCreditsTo()` whose
  delta does not fit is refused with `AmountOverflow` before anything is written. The row used to
  be committed and the call then failed with a `TypeError` (or, at the bottom of the range,
  succeeded with a wrong balance), leaving a bucket every later debit refused.
- A total across buckets past int64 (`totalCreditsBalance()`, `creditsBalanceForBuckets()`,
  `Credits::total()`, `buckets([...])->balance()`) throws `AmountOverflow` instead of returning
  `PHP_INT_MAX` (PostgreSQL / MySQL) or throwing a `QueryException` (SQLite).
- A point-in-time `$at` in another timezone than `app.timezone` (`creditsBalance($at)`,
  `totalCreditsBalance($at)`, `Credits::balance()` / `total()`, `Credit::query()->upTo($at)`) is
  now compared as an instant. It used to be bound by its wall clock, so 11:30 in Bratislava
  counted a row written at 10:00 UTC, and the fake answered the opposite way.
- A change that is rolled back — a host transaction around `modifyCredits()` that fails, or a
  failed `setCreditsTo()` — no longer reaches `CreditsModified` listeners; the event used to fire
  inside the still-open transaction.
- `php artisan about` renders `INVALID` for a broken `credits.model`, `credits.key_type`,
  `credits.primary_key_type` or `credits.allow_overdraft` instead of failing the whole command
  with an `InvalidConfigurationException`, like the other credits rows.
- An `InsufficientCreditsException` under a minimum balance no longer reports the raw balance as
  available: with a floor of 50, a refused debit of 60 on a balance of 100 read "only 100 are
  available". The fake's exception matches the real one.
- `Credits::fake()` compares a point-in-time `$at` at second precision, like the real ledger's
  `created_at`: a change made later in the same second now counts at `$at` on the fake too.
- `Credit::factory()` builds rows in `credits.default_bucket` (it used the migration's `'default'`
  column default) and builds the configured `credits.model`, so a host subclass's `factory()`
  returns the subclass instead of the packaged `Credit`.
- `credits:modify` without `--amount` (or with `--amount=0`) no longer writes a 0-amount ledger row
  and fires `CreditsModified` for every resolved entity while reporting success.

## 1.0.1 - 2026-10-04

### Changed

- Maintenance: `composer.json` `homepage` and `support.docs` now point to the documentation site.

### Fixed

- Slovak (`sk`) translations now ship alongside English for every language file.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- Credits and wallet balances on any Eloquent model via the `HasCredits` trait and the
  `Creditable` contract, stored as an immutable, time-stamped ledger.
- `modifyCredits()` to grant or deduct, `setCreditsTo()` for an exact balance, and
  `creditsBalance()` / `hasCredits()` — current or as of any point in time.
- Overdraft protection: a deduction below `minimum_balance` throws
  `InsufficientCreditsException`, guarded by a row lock so concurrent deductions can't both
  pass; allow overdraft per call or globally.
- Named buckets (for example `promotional` and `purchased`) with isolated balances, plus totals
  across buckets (`creditsBalanceForBuckets()`, `totalCreditsBalance()`).
- Fractional credits through a configurable `scale`, with `displayCredits()` /
  `displayCreditsBalance()` rendering exact decimal strings and a configurable rounding mode.
- Currency-denominated buckets backed by money-for-laravel: `modifyCreditsMoney()`,
  `creditsBalanceMoney()` and `formatCreditsBalance()` for store credit or custom units such
  as loyalty points.
- Ledger query scopes (`grants()`, `deductions()`, `upTo()`, `forCreditable()`, `bucket()`) and a
  `CreditsModified` event after every change.
- A `Credits` facade (global alias `Credits`) over an injectable `CreditsManager`.
  `Credits::for($owner)` returns an immutable scope with `bucket()`, `allowOverdraft()`,
  `add()` / `deduct()` / `modify()` / `setTo()`, `balance()` / `has()` / `total()`,
  `buckets([...])->balance()` / `has()`, and the Money forms `money()` / `addMoney()` /
  `deductMoney()` / `modifyMoney()` / `format()` / `formatMoney()`. `Credits::format()` and
  `Credits::currency()` need no model. The flat verbs `modify()` / `setTo()` / `balance()` /
  `total()` take the owner as their first argument.
- `Credits::fake()` returns `CreditsFake`, a `CreditsManager` subtype. It writes no rows and
  fires no events, keeps an in-memory balance and still enforces the overdraft guard. It
  records changes made through the facade, the `HasCredits` trait and `credits:modify`, and
  asserts `assertAdded()`, `assertDeducted()`, `assertSet()`, `assertNothingAdded()`,
  `assertNothingDeducted()`, `assertNothingSet()` and `assertNothingModified()`.
- Single-purpose actions with one `execute()` each: `ModifyCreditsAction`,
  `SetCreditsAction`, `GetCreditsBalanceAction`, `GetCreditsTotalAction`,
  `FormatCreditsAction` and `ResolveBucketCurrencyAction`. The `credits:modify` command
  handles bulk grants such as a monthly bonus.

### Changed

- Every `HasCredits` method delegates to `CreditsManager` (through `Credits::for($this)`), and
  so does `credits:modify`.
- Multi-bucket and all-bucket sums moved from `GetCreditsBalanceAction::forBuckets()` /
  `forAllBuckets()` to `GetCreditsTotalAction` (`Credits::total()`).
- `ResolveBucketCurrencyAction::denominated()` is removed. The scope's Money methods throw
  `BucketNotDenominatedException` instead.

### Fixed

- `setCreditsTo()` read the balance without a lock and outside a transaction. Two racing calls
  could each apply the full delta, so two concurrent "set to 500" calls on a balance of 100
  ended at 900. It now takes the owner lock and reads the balance under a row lock, inside one
  transaction with the write.
- Under REPEATABLE READ or SERIALIZABLE, racing debits could both pass the overdraft guard
  (two -60 on 100 ended at -20) and racing `setCreditsTo()` calls could both apply their
  delta. The owner row was only locked, so the waiter read the balance from the snapshot it
  took before the wait. Every change now bumps the owner's row in a new `credit_locks` table
  (created by the same migration) and reads the ledger under a row lock, so racing changes
  serialise on every isolation level; a Postgres snapshot conflict is retried.
- `allow_overdraft` was read with a loose `(bool)` cast, so `CREDITS_ALLOW_OVERDRAFT=off` (or
  `no`, `false`) turned overdraft **on**, and `=1` overdrew while `about` reported `BLOCKED`.
  The guard, the fake and `about` now read it as a boolean (`1`/`true`/`on`/`yes`).
- `credits:modify` stopped at the first entity with insufficient credits: the entities before
  it were charged, the rest were not, and no summary was printed. It now reports each refused
  entity, finishes the run, and exits non-zero when any was refused. The `--allow-overdraft`
  help now says "below `credits.minimum_balance`" rather than "below zero".
- `CreditsModified::$balance` was summed after the commit, without the lock, so it could
  include another writer's rows. It is now the balance the change produced, read under the
  owner lock inside the transaction.
- The README's configuration section left out `key_type` (`CREDITS_KEY_TYPE`), the setting
  uuid- or ulid-keyed creditable models need, and its `about` list left out the two key-type
  rows. Both are documented now, along with the migration being forward-only.
- The README called the `Creditable` interface optional. Every API takes a `Model&Creditable`,
  so it is required.
