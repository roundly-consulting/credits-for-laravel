# Changelog

All notable changes to `credits-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

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
- `CreditsModified::$balance` was summed after the commit, without the lock, so it could
  include another writer's rows. It is now the balance the change produced, read under the
  owner lock inside the transaction.
- The README called the `Creditable` interface optional. Every API takes a `Model&Creditable`,
  so it is required.
