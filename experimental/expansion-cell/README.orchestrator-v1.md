# Expansion Cell v1 — operator flow

Current candidate flow:

1. Parent federation identity exists locally.
2. Operator supplies target HTTPS base URL, remote web root and temporary FTP/FTPS access.
3. `KiComExpansionService::execute()` creates a one-time expansion transaction.
4. A target-specific child package is built in temporary local storage.
5. `KiComExpansionFtpDeployer` uploads it into `kicom/` on the authorized target webspace.
6. The transient local package is destroyed after the deploy attempt.
7. Parent calls child `bootstrap.php`; child creates its own local identity and returns an enrollment proof.
8. Parent accepts the one-time enrollment and sends a signed activation envelope to `federation.php`.
9. Child deletes bootstrap enrollment material and becomes active.
10. Parent immediately sends a signed federation tick and verifies the signed reply.
11. Ongoing communication uses signed HTTPS; FTP/FTPS is no longer needed.

A real external deployment test is still pending. Production KiCom 0.9.15 is not modified by this candidate.
