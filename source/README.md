# KiCom release source snapshots

Every published KiCom release must keep both:

1. the immutable install ZIP under `releases/<version>/`, and
2. a sanitized, release-derived source snapshot under `source/<version>/`.

Runtime state under `var/` and `stage/` is never copied into a source snapshot (protective `.htaccess` files may remain).

A new release build must be reproducible from the immediately preceding immutable release or its source snapshot, must publish its own source snapshot in the same commit as the release, and must preserve the release SHA-256 in `SOURCE-SNAPSHOT.json`.

This policy exists so future development never depends on extracting binary ZIP data through an LLM transport.
