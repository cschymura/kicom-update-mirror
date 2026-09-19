# KiCom Membrane — Cross-UID Staging + Alternate-Path Tests

Datum: 2026-09-19
Repo: cschymura/kicom-update-mirror
Branch: work/kicom-0.9.27-pam
Vorheriger Checkpoint: development/0.9.27/membrane/CHECKPOINT-2026-09-19-POSITIVE-LOOPBACK.md

## Read-only Live-Baseline

Vor Beginn der Änderung wurden BOOTSTRAP, PROJECT_STATE, ARCHITECTURE, PROTOCOL, DECISIONS, CHANGELOG, NEXT, GENOME_STATUS, SQLITE_STATUS und UPDATE_STATUS abgerufen. Produktiv: KiCom 0.9.26, Genome kicom-0.9.26-g25r3, healthy/trusted/LKG OK, drift=0, unknown=0; SQLite WAL quick_check=ok; kein pending Update. SLACK_STATUS zeigt configured=false, MAIL_STATUS zeigt configured=true. Keine produktive Schnittstelle, Datenbank, Datei, Session, Mail, Slack-Nachricht oder Berechtigung wurde verändert. Kanonische PROJECT_STATE- und NEXT-Texte enthalten weiterhin ältere Versionierungsinformationen; kein stillschweigendes Überschreiben ohne autorisierten revisionsbewahrenden Memory-Pfad.

## Umgesetzter isolierter Entwicklungsfortschritt

1. fixture-isolated-transport.php: neuer localhost-only PHP-Testendpunkt, der unter dem dedizierten unprivilegierten Test-Principal kicom_inner_ci läuft. Er sendet synthetische GET-/POST-Payloadbytes zurück und führt einen hashgebundenen Receipt ausschließlich in seinem beschreibbaren Innenraum.
2. test-cross-uid-transport.php: ein Prozess mit dem getrennten Principal kicom_membrane_ci sendet synthetische GET-, Slack-artige, Mail-artige und binäre POST-Payloads an den KiCom-Innenraumprozess. Prüft exakte Payloadbytes, Digest, HTTP-Status und Content-Type. 8 Checks.
3. test-os-bypass-transport.sh: überprüft zusätzlich verweigerte Dateizugriffe des Innenraums über einen selbst angelegten Symlink zur Membran-Policy, chmod und rename; der Membran-Principal darf private Innenraumzustandsdaten nicht lesen. Prüft nach Transport die exakt vier Receipts und unveränderte Policy-/Recovery-Testanker. 6 Checks.
4. Im ersten CI-Lauf war der neue Testserver unter dem privaten GitHub-Checkout für getrennte UIDs unlesbar. Dieser Fehler wurde sichtbar als fehlgeschlagener Run 35447372120 gespeichert; es gab keinen Erfolg für diesen Code. Der Test kopiert jetzt ausschließlich synthetische, root-owned 0644-Testdateien in ein root-owned 0755-Verzeichnis außerhalb der privaten Checkout-Hierarchie. Der Client erhält NICHT Zugriff auf private GitHub-Arbeitsdateien, Credentials oder Produktionsdaten.
5. Der Code und die beiden neuen Tests wurden in den bestehenden GitHub Actions Workflow .github/workflows/test-0927-membrane.yml eingehängt und nach dem Fix frisch getestet. Alle Änderungen liegen ausschließlich im GitHub-DEV-Branch; keine Live-Installation oder Netzwerkumleitung.

## Verifiziertes CI-Ergebnis

GitHub Actions RUN_ID=35447458586; Job-ID=105908748493; conclusion=success. Im Checkout-Log verifizierter getesteter Commit acde30a21664d8674ac53b16f3e3a3a02d838da7.

28 Shadow + 24 R3-Quell- und Transportvertrag + 16 native R3-HTTP + 27 negative R3-POST + 16 Telemetrie-Failure-/Exactly-once + 20 positive lokale Loopback + 6 ursprüngliche OS-Principal-Isolation + 8 neue Cross-UID-Transport + 6 neue Bypass-/Receipt-Prüfungen = **151 bestandene isolierte Checks**.

CI-Link: https://github.com/cschymura/kicom-update-mirror/actions/runs/35447458586

## Exakt begrenzte Aussagekraft

Die Tests beweisen im Linux-CI-Labor eine reale Dateirechte-Trennung zwischen zwei nichtprivilegierten OS-Principals und belegen erfolgreiche SYNTHETISCHE Kommunikation zwischen ihren Prozessen bei unveränderter geschützter Policy und Recovery-Testanker. Die Prozesse teilen weiterhin denselben Host-/Netzwerk-Namespace. Der Innenraum kann daher nicht nachweislich daran gehindert werden, Netzwerkendpunkte direkt zu kontaktieren; es existiert weiterhin KEIN nicht umgehbarer, produktiver Membran-Policy-Enforcement-Point. Ein Dateirechte-Test schützt nicht vor Kernel-/Root-Kompromittierung.

Die bisherigen lokalen Slack-/Mail-/ZIP-Fixtures sind keine realen Slack-, SMTP-, IMAP-, Browser-/Passkey- oder Update-E2E-Transaktionen. Auf dem ALL-INKL-Produktivaccount sind getrennte PHP-Worker-Identitäten/FTP-Rechte nicht nachgewiesen. Ein Same-UID-Hosting würde eine unabhängige Host-Membran unter PHP-Verzeichnissen unmöglich machen. Keine produktive Freischaltung zulässig, solange Host-Principal-, Netzwerk-/Egress-, Recovery-/Trust-Root- und vollständige Kommunikationsparitäts-Nachweise fehlen.

## Konkreter nächster Schritt

In einer isolierten, separat administrierbaren Testumgebung mit ausdrücklicher Host-Isolation einen Egress-Controller unter separatem Principal samt Netzwerk-Namespace/ACL aufbauen, damit der Innenraum die Grenze für mutierende externe Aktionen nicht direkt umgehen kann. Kontrollierte erlaubte und gesperrte Testkommunikation, Controller-Ausfall und Timeout ohne Nachrichtenduplikation verifizieren. Dabei keine echten Slack-/Mail-Empfänger als Testziele verwenden. Erst danach die tatsächliche Produktions-Hosting-Eignung read-only klären und kompatible Paketierung/Backup/Healthcheck/Rollback vorbereiten. Original-KiCom-Auth, Genome, Update-Verifier, LKG, SQLite und geschützte menschliche Freigabegrenzen beibehalten. Für interne DEV-Arbeit keinen FreeOTP-Code verlangen.
