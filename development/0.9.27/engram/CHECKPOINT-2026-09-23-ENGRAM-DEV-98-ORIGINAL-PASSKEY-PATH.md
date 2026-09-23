# MIRAGE DEV-98 — Original KiCom Passkey-Store-Anbindung korrigiert

Stand: 23.09.2026. **Entwicklung, keine produktive Änderung und kein Installationspaket.**

## Tatsächlich gefundene und behobene Integrationssperre

Der ursprüngliche native KiCom-0.9.37-Administrationscode verwendet bei den bereits funktionierenden Passkey-Freigaben den festen First-Party-Speicher `__DIR__.'/var/dev_zone/passkeys'` mit `kicom.rurtalbahn.info` und `https://kicom.rurtalbahn.info`. Die originale Funktion `kicomEngramServerRuntime()` lädt die geprüfte private Hosting-Konfiguration, liefert aber **kein** Feld `passkey_store`. Die bisherige DEV95-Aktivdatenbank-Vorbereitungsroute verwendete fälschlich `(string)$runtime['passkey_store']`; damit war die tatsächliche Admin-Passkey-Bestätigung trotz aller isolierten Fake-Passkey-Tests nicht zuverlässig ausführbar.

Im neuen Branch `work/kicom-engram-dev98-original-passkey-path` ist `dev95/NATIVE-original-admin-active-db-passkey-upgrade.patch` auf den EXAKTEN bereits ursprünglichen KiCom-Passkey-Konstruktor umgestellt. Bestehende Passkeys werden wiederverwendet; keine neue Registrierung, keine freie clientseitige Pfadangabe, keine Änderung der Original-Passkey-Prüfung, kein OAuth-Scope-Upgrade. Die neue native Route ruft weiterhin Original-Adminsession, CSRF und die echte ursprüngliche `KiComPasskeyBridge`-Verifikation auf, bevor eine private Migration überhaupt möglich wird.

## Nachweisbare Prüfschritte

- Im aktuell bereitgestellten Originalanhang `KiCom-0.9.37-Mirage-MCP-META-FIX-VOLLSTAENDIG.zip` SHA256 `0c6e02c64d44d603cb229f562d189f1b798bd78c2188fd2907e6b5cbafdb5ea7` wurden `admin.php`, `lib.php` und `modules/dev/PasskeyBridge.php` DIREKT gelesen. Vier lokale Assertions bestanden: Original-ZIP-SHA; ursprünglicher feststehender Passkey-Speicher wird an mehreren vorhandenen Original-Administrationsstellen verwendet; Original-Runtime liefert keine `passkey_store`-Konfiguration; Original-Brücke hat `verifyAssertion`. Keine privaten Credential-Dateien gelesen.
- GitHub Actions `test-kicom-dev98-original-passkey.yml`, Run `35913853568`, Job `107360218329`, abgeschlossen **success**; das neue committed `dev98/test_original_passkey_store.py` prüft den Native-Admin-Patch und seine First-Party Passkey-/Session-/CSRF-Verkabelung. URL: https://github.com/cschymura/kicom-update-mirror/actions/runs/35913853568.
- CI stellt NICHT den 0.9.37-Originalanhang als Datei zur Verfügung: dort werden 6 Source-/Contract-Assertions ausgeführt, optional lokal `--original` ermöglicht bytegenauen Original-ZIP-Abgleich. Weder echte WebAuthn-Signatur im Browser noch echter ORIGINAL-PHP-HTTP-End-to-End-Test wurde behauptet.

## Noch offen

DEV97 `stage_native_one_tree.py` und die Patch-Eingabeprüfung sind committed, jedoch ein vollständiger Zusammenschluss aller Originalquellcode-Patches mit `patch --fuzz=0` und MANIFEST/Genome/Updater/Rollback **noch nicht nachgewiesen**. Keine weiteren isolierten Teilupdates. Die Original-ZIP liegt im aktuellen Conversation-Runtime-Dateisystem; im neuen Chat ihren tatsächlichen Pfad und SHA erneut prüfen. Das Repo enthält diese Original-ZIP derzeit nicht; GitHub CI kann sie ohne explizit autorisierte private Artefaktübergabe nicht selbst rekonstruieren. Der DEV95-Test für den First-Party-Admin-HTTP-Ablauf verwendet eine ausdrücklich FAKE-Passkey-Komponente; der hier behobene Fehler zeigt, warum dieser Test keine reale WebAuthn-Integration beweist.

**Nächste echte Arbeit:** Ein native one-tree staging mit realem Original, korrekter Flat-Modul-Anbindung und vollständigen PHP PDO SQLite + originalem Passkey-/OAuth-/MCP-Protokoll; erst nach vollständiger Manifest-/Genome-/Recovery-/Rollback-Prüfung EIN komplettes Installationspaket zur separaten Freigabe. Bis dahin bestehendes produktives KiCom unverändert.
