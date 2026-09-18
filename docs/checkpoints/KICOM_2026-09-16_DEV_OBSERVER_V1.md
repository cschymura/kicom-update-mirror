# KiCom DEV Observer v1 — 2026-09-16

## Why this exists
Repeated live troubleshooting showed that KiCom had too little self-observability. The operator had to provide screenshots and repeatedly reinstall packages, even though the long-term KiCom goal is to minimize human deployment work.

## Observer v1
The candidate now contains a bounded observability layer:

- `experimental/dev-zone/DevObserver.php`
- `experimental/dev-zone/runtime/doctor.php`
- `experimental/dev-zone/dev-observer-selftest.php`
- `experimental/dev-zone/runtime/dev-expansion.php` emits sanitized operation traces.

## Public doctor endpoint
After installation, `https://kicom.rurtalbahn.info/dev/doctor.php` returns sanitized JSON only. It includes:

- existence, byte size and SHA-256 for the required DEV/Expansion source files;
- PHP version and required extension presence;
- sandbox deployment-resource availability/class/writability/healthcheck marker;
- recent sanitized expansion trace entries;
- no DEV bearer, OTP, passkey private material, passwords or absolute hosting paths.

## Operation tracing
Each expansion request receives a generated `operation_id`. The trace records bounded stages such as BEGIN, AUTH, BINDINGS and FINISH plus the final result code. This allows the next troubleshooting iteration to begin from a machine-readable trace instead of screenshots or assumptions.

## Validation
On candidate head `337331cf5ccdf6f0adef0e37a89b0c3227f41923`:

- KiCom DEV Observer v1 run `35148281824`: SUCCESS.
- KiCom DEV zone checks run `35148281745`: SUCCESS.
- KiCom Expansion Cell v1 run `35148282055`: SUCCESS.

## Packaging rule
No partial `dev/` repair archives are to be used on this host. The recovery/install archive must contain the complete DEV code tree. Runtime state remains outside the code tree through `kicomVarDir()` and is not included in the restore archive.

## Next live step
One complete DEV restore containing Observer v1 is installed once. From then on, troubleshooting uses `/dev/doctor.php` and operation traces before any further mutation.
