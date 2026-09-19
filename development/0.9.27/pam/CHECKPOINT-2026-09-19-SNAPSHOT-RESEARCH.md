# KiCom Entwicklungscheckpoint — 2026-09-19 — Snapshot-Recovery-Research und PAM-Guard

## Kanonische Beobachtung (zu Beginn des Entwicklungszyklus)

- BOOTSTRAP/HELLO: produktiv 0.9.25, kicom-0.9.25-g24; healthy=true, trusted=true, LKG OK, drift=0, unknown=0.
- SQLITE_STATUS: primary=true, journal_mode=wal, quick_check=ok, Schema 1. Keine unabhängige Vollprüfung der produktiven Datenbank vorgenommen.
- UPDATE_STATUS: 0.9.26 RED pending, source=pull:mirror. Der öffentliche Live-Status enthält weiterhin **keinen Hash des tatsächlichen Pending-Pakets**. Das separate öffentliche 0.9.26-R3-ZIP ist SHA-256 6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f; dies ist KEIN Beweis über das bereits bei KiCom vorgemerkte Paket.
- Der kanonische Live-Serverzustand ist gegenüber dem ggf. historisch veralteten PROJECT_STATE/NEXT-Versionseintrag führend; der Server wurde in diesem Zyklus nicht verändert.

## Vom Benutzer ausdrücklich vereinbarte Recherche- und Werkzeugpraxis

Bei technischem Nichtwissen/Unklarheit gezielt Webrecherche; bevorzugt offizielle/upstream Stellen, Universitäten, Behörden, Original-Open-Source-Repositories und deren Git-Historien. Verfügbare, autorisierte Plugins dürfen nach fachlichem Ermessen eingesetzt werden; deren Daten oder Zugriff begründen keine zusätzlichen KiCom-Berechtigungen. Dokumentiert in development/0.9.27/RESEARCH-AND-TOOLS-POLICY.md und hier im Übergabepunkt; diese Arbeitsvereinbarung gilt für die weitere Entwicklung.

Primärquellen in diesem Zyklus:
- https://www.sqlite.org/backup.html (Online Backup API und konsistente Snapshots)
- https://www.sqlite.org/wal.html (WAL als Teil des persistenten Datenbankzustands)
- https://www.php.net/manual/en/sqlite3.backup.php (PHP SQLite3::backup und Versionsvoraussetzungen)

## Technische Erkenntnis

Der originale 0.9.26-R3-Runtime-Code sortiert Metadateien in kicomSqliteSnapshotMetaFiles() per Dateiname absteigend. kicomSqliteSnapshot() baut die ID aus einem Sekundenzeitstempel und einem Zufallsanhang. Bei zwei Backups derselben Sekunde lässt sich die tatsächliche Reihenfolge NICHT aus dieser zufälligen Endung ableiten. Eine korrekt verifizierte Datenbankkopie beweist nur ihre Integrität, nicht die zeitliche Auswahl als neuester Wiederherstellungspunkt.

## Implementiert (nur GitHub-Entwicklung, keine Produktivänderung)

- development/0.9.27/pam/KiComPamSnapshotOrder.php: aus verifizierten Metadaten eindeutigen Kandidaten nur bei belegbarer Sekundenreihenfolge auswählen; gleiche Sekunde => SNAPSHOT_ORDER_AMBIGUOUS. Fehlende, doppelte, zeitlich widersprüchliche oder nicht verifizierte Metadaten blockieren die Auswahl. Unbelegte Subsekundenattribute werden nicht als Autorität akzeptiert.
- development/0.9.27/pam/KiComPamRecoveryGate.php: ausschließlich lesender, begrenzter KiCom-interner Recovery-Vorcheck. Nutzt die bestehende vertrauenswürdige Snapshot-Verzeichnis-/Prüffunktion, validiert Name, Verzeichnis, SHA und vollständige SQLite-Integrität; gibt ausschließlich einen exakt identifizierten Kandidaten oder eine explizite Verweigerung zurück. Keine Restore-/Install-/Autoritätsfunktion.
- test-snapshot-order.php: 11 isolierte Regressionstests einschließlich Gleichzeitigkeit, manipuliertem Prüfsummenbeleg und Legacy-Eindeutigkeit.
- test-r3-runtime.php + GitHub CI: 3 zusätzliche Runtime-Tests gegen zwei echte native R3-Snapshots und einen deterministischen gleichen-Sekunde-Testfall, einschließlich Ablehnung einer veränderten Snapshot-Prüfsumme. Die R3-Release-/Quellcodeprüfsummen bleiben unverändert.
- README.md ergänzt: neuen Teststand, offene Integrationsgrenzen und die Recherchevereinbarung.

## Exakte Testbelege

- Isolierte Tests: 32 Kern + 15 KCL + 13 Persistenz + 11 Snapshot-Reihenfolge = 71 bestanden; GitHub status/latest-ci.txt und zugehöriger immutable unit-run-35437876986.txt / Folge-Run prüfen.
- Runtime-Integration auf isolierter Kopie des echten R3-Codes: 44 bestanden; status/r3-runtime-ci.txt, GitHub-Run 35437893078, Trigger-/Tested-SHA 898d14fb55cc4f101883c7b54086ccb41f8df106, PRELIGHT/TEST success. Bei späteren Codeänderungen neueste CI-Datei und SHA erneut lesen.
- Diese Tests schließen keine produktive Installation, keinen produktiven Restore und keine Live-Datenbankmigration ein.

## Nächster Entwicklungsschritt

1. Sicheres 0.9.27-Runtime-Integrationskonzept für Recovery erstellen: eindeutige, vertrauenswürdig geschriebene neue Snapshot-Reihenfolge mit Legacy-Erkennung, prüfbarem Fallback und ausdrücklicher Unterbrechung bei unauflösbarer Gleichzeitigkeit. Die produktive R3-Recovery nicht ungetestet ersetzen; bestehende LKG-/Rollback-Daten niemals löschen.
2. In isoliertem Test die tatsächliche kicomSqliteRecover()-Entscheidungsgrenze anhand des Guards absichern und Tests für Stromausfall/fehlende Meta, WAL, mehrere Writer, Altbestände und Wiederherstellung erstellen.
3. PAM-Module für 0.9.27 erst nach geprüftem Elternrelease und manifestgebundener Vertrauensprüfung paketieren; weiterhin kein arbitrary shell/file/SQL/fetch-Zugriff, keine automatischen externen Berechtigungen.
4. Hash des produktiv vorgemerkten R3-ZIPs nur über einen bereits zulässigen lesenden Diagnose-/Updaterpfad prüfen. RED-/Produktionsfreigabe mit exakter Paketbindung, Backup, Healthcheck und Rollback ist separat erforderlich; für interne Entwicklung keinen FreeOTP-Code anfordern.

## Status

Entwicklung und Testergebnisse sind im GitHub-Branch work/kicom-0.9.27-pam dokumentiert. Produktives KiCom bleibt 0.9.25, die unveränderte native R3-Recovery ist **nicht** durch die neuen Diagnosemodule ersetzt worden.
