# KiCom PAM — R3-Runtime-Kompatibilität — Checkpoint 2026-09-19

## Ausgangszustand

Live-KiCom über BOOTSTRAP/HELLO: 0.9.25. GENOME_STATUS: kicom-0.9.25-g24, healthy/trusted/LKG OK, drift=0, unknown=0. SQLITE_STATUS: primary SQLite in WAL mode und quick_check=ok. UPDATE_STATUS: pending_version 0.9.26, risk=red, source=pull:mirror; die Live-Statusantwort enthält weiterhin KEINEN SHA-256 der bereits vorgemerkten ZIP-Datei. Keine produktive Änderung vorgenommen.

Der öffentliche Feed zeigt auf das separat archivierte R3-ZIP SHA-256 6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f. Dies identifiziert NICHT automatisch die im KiCom-Updater bereits vorgemerkte Datei. Der vorherige gleichversionierte ZIP-Bestand hat eine andere Prüfsumme und bleibt historisch erhalten.

## Durchgeführte, isolierte technische Arbeit

- Neue Testdatei development/0.9.27/pam/test-r3-runtime.php mit expliziter Beschränkung auf einen festen CI-temporären Runtime-Pfad. Keine Ausführung gegen Produktionsdaten.
- Neue GitHub Actions Pipeline .github/workflows/test-0927-pam-r3.yml. Sie verifiziert vorab EXAKT die R3-ZIP-SHA und jeden durch MANIFEST.sha256 gebundenen R3-Quelltextpfad. Anschließend kopiert sie source/0.9.26-r3 in eine temporäre Runtime und simuliert mit 0.9.25-KCL-Daten den kanonischen 0.9.26-Synchronisationspfad.
- Die Test-Runtime öffnet mit dem unveränderten R3-lib.php ihr natives SQLite-PDO. PAM v2 erstellt seine fünf eigenen pam_*-Tabellen, ohne schema_migrations oder bestehende native Tabellen zu verändern. PAM-Observation, Task und Checkpoint wurden erfolgreich gespeichert.
- Native kicomSqliteHealth(true) ist vor und nach PAM OK. Der unveränderte native kicomSqliteSnapshot erstellt erfolgreich einen SHA-gebundenen Snapshot; kicomSqliteLatestSnapshot prüft diesen. Der Snapshot enthält anschließend PAM v2, die bisherigen nativen Strukturen und ist nach separater SQLite-integrity_check-Prüfung gesund.
- GitHub-CI-Ergebnis: development/0.9.27/pam/status/r3-runtime-ci.txt, RUN_ID 35436936790, getesteter Commit dfe043a586317b8f76dfffd7875a16583371d62e, Ergebnis success, 15/15 Runtime-Integrationstests bestanden.
- Die bisherigen isolierten PAM-Kern/KCL/Persistenztests waren 60/60 erfolgreich. Quelle: development/0.9.27/pam/status/latest-ci.txt; für aktuelle Evidenz immer Ergebnis UND tatsächliche Trigger-SHA prüfen.
- Dokumentation im Branch aktualisiert: development/0.9.27/pam/README.md.

## Was dieses Ergebnis nicht belegt

Keine Installation der 0.9.26 R3 oder 0.9.27 auf dem Live-Server; keine echte Live-SQLite-Migration, kein Live-Backup/Restore, kein neuer Genome-Trustanker, keine produktive PAM-Ausführung und keine RED-Freigabe. Die Tests gelten für die isolierte Kopie des R3-Runtimes auf GitHub Actions. Auch eine erfolgreiche ZIP/Manifest-Prüfung der öffentlichen R3-Version beweist nicht den Inhalt des bisherigen Live-Pending-Updates.

## Nächster sicherer Entwicklungsschritt

1. Release-Kandidat in KiComs EXISTIERENDEM Updatepfad anhand der tatsächlichen Pending-ZIP-SHA eindeutig identifizieren; wenn die vorhandene Schnittstelle sie nicht ausgibt, nur die normale, nachvollziehbare Update-Supersession-/Prüffunktion verwenden und alte Metadaten behalten. Keine Installation mit bloßem Versionsvergleich.
2. Im getrennten Entwicklungszweig PAM-Observer/Action-Loop an die tatsächliche, intern allowlistete KiCom-Ausführung anbinden, ohne neue Befugnisse zu erzeugen. Idempotenz, Reconciliation statt blindem Retry und überprüfbare Belege erhalten.
3. Vor produktiver Promotion von 0.9.27: exact-Package-SHA/Manifest/Genome-Lineage, echte Umgebungs- und Backupprüfung, unveränderter Release-Verifier, Vor-/Nach-Healthchecks und Rollback. Bis dahin nur Entwicklungszweig ändern.
4. RED/Produktionsgrenzen menschlich autorisiert und hash-gebunden halten; keinen FreeOTP-Code für interne Entwicklung fordern. Den regelmäßigen Nachtschichtlauf mit genau diesem Checkpoint fortsetzen.
