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
- Single-purpose actions (`ModifyCreditsAction`, …) for jobs and services, and the
  `credits:modify` command for bulk grants such as a monthly bonus.
