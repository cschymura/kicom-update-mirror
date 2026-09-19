# KiCom 0.9.27 — Recovery-Entscheidungsautomat (19.09.2026)

## Live-Stand (ausschließlich gelesen)

BOOTSTRAP / GENOME_STATUS / SQLITE_STATUS / UPDATE_STATUS wurden in diesem Durchgang erneut geprüft. Produktiv: KiCom 0.9.26, Genome kicom-0.9.26-g25r3; healthy=true, trusted=true, lkg_ok=true, drift_count=0, unknown_count=0. SQLite primary, WAL, quick_check=ok, natives Schema 1. Kein Update vorgemerkt. Die Ausführung beschränkte sich auf GitHub-Entwicklung und isolierte CI; keine Live-DB-, Recovery-, Genome-, Release- oder Login-/Credential-Änderung.

Der bisherige kanonische Text nennt teilweise noch g25r2/alte Installationsprioritäten; er wurde nicht ohne nachgewiesenen revisionsbewahrenden autorisierten KiCom-Memory-Schreibpfad verändert. Der verifizierte Live-Status ist maßgeblich.

## Implementierung dieses Entwicklungszyklus

1. KiComPamRecoveryDecision.php: deterministischer, rein lesender Entscheidungs-/Abbruchautomat. Erwartet einen exakt identifizierten und lediglich zur Prüfung vorgeschlagenen Snapshot-Kandidaten, vor/nach dem Check separat erfasste Original-DB/WAL/SHM-Fingerprints, den Vergleich mit einer extern **behaupteten** High-Water-Position und einen separaten Original-Erhaltungsnachweis. Stoppt bei fehlendem/inkonsistentem Kandidaten, Legacy-Reconciliation, fehlenden/mutierten Originaldateien, abweichender High-Water-Behauptung, fehlender unabhängiger Authentisierung und unbewiesener Originalerhaltung. Rückgaben sind ausschließlich Diagnose-/Review-Zustände; restore_permitted=false und automatic_recovery_permitted=false in **jedem** Fall, auch wenn der Aufrufer alle Beweismarker auf true setzt. PAM kann damit weder die Echtheit einer Anchor-Behauptung herstellen noch native Recovery ausführen.
2. test-recovery-decision.php: 16 isolierte Regressionstests einschließlich neuer/geänderter/fehlender WAL, beschädigter/ersetzter DB/SHM, Legacy-/fehlender Kandidat, falscher Hash/fehlende externe Anchor-Aussage und vorgetäuschter vollständiger Berechtigung.
3. test-r3-runtime.php: vier zusätzliche Tests verwenden die tatsächlich im isolierten originalen 0.9.26-R3-Runtime-Code erzeugten Snapshot- und DB/WAL/SHM-Beobachtungen für den neuen Entscheidungsautomaten. Selbst bei scheinbar vollständig bestätigtem Fall bleibt ausschließlich REVIEW_REQUIRED_AT_PROTECTED_RECOVERY_BOUNDARY, keine Restorerlaubnis.
4. Beide GitHub-Workflows um neue Moduldatei, Lint, Unit- und R3-Tests ergänzt. README.md entsprechend aktualisiert. Testberichte bleiben pro CI-Lauf erhalten, Trigger-SHA und getestete SHA werden verglichen.

## Nachgewiesene Tests

- Isolierte Tests: 32 core + 22 KCL + 13 persistence + 11 legacy snapshot order + 17 sequencer + 16 high-water + 13 original-file inventory + 16 recovery-decision = **140 bestanden**. CI_RESULT=success, RUN_ID=35441892342, TRIGGER_SHA=TESTED_SHA=73056190a236155dbd8be7ae3e5b36d9a9879295, development/0.9.27/pam/status/latest-ci.txt (einschließlich PAM_RECOVERY_DECISION_TESTS_PASSED=16).
- Exakte R3-Caller-Inventur **10 bestanden**; R3-Runtime-Integration **67 bestanden**. CI_RESULT=success, RUN_ID=35441898023, TRIGGER_SHA=TESTED_SHA=08b451569d844de52345bb39939e106f2b4fe079, R3_ZIP_SHA256=6e93e7b176ce429a922cb5e5906e90046f68c38fb3a8fb1b5cabcd529b56dd1f; development/0.9.27/pam/status/r3-runtime-ci.txt.
- Gesamt: **217 bestandene isolierte DEV-Prüfungen**. Die Berichte belegen keine echte produktive Restore-Probe und keine gesicherte Hochverfügbarkeit des Live-Systems. Ein späterer Commit auf ausführbarem Code erfordert neue Tests passend zu dessen SHA.

## Noch explizit offen

- Unveränderte produktive native R3-Recovery führt diesen neuen Guard noch NICHT aus; der Guard kann daher aktuell keine destruktive Operation in der Live-Recovery unterbrechen.
- Der High-Water-Prüfer vergleicht eine Caller-Behauptung, authentifiziert aber keinen unabhängigen, gegen gemeinsame Rücksetzung geschützten Trust-Root. Eine behauptete independent_anchor_authenticated_here=true wird niemals selbst zur Berechtigung.
- Ein Fingerprint ist keine quiescte und dauerhaft gesicherte Kopie der möglicherweise beschädigten Originaldateien. Eine tatsächliche Preservation-Prozedur samt DB/WAL/SHM und crash-sicherer Veröffentlichung fehlt.
- Keine 0.9.27-Produktionspaketierung/Genome-Promotion; bestehender Verifier, LKG, Backup-/Rollback- und geschützte Autorisierungsgrenzen bleiben unberührt.

## Nächster sichere Entwicklungsabschnitt

1. Einen isolierten, nichtdestruktiven Original-Dateisatz-Erhaltungsablauf nur im nachweislich quiescten Zustand entwickeln und testen, einschließlich Abbruch bei offenen Writer-Sessions, fehlender Kopie, SHM-/WAL-Mismatch und vorzeitigem Prozessabbruch. Erst nach vollständiger Sicherung und unabhängiger Verifizierung Originaldateien überhaupt als geschützt kennzeichnen.
2. Einen echten separaten, authentifizierten High-Water-Publisher/Reader als eigenen Trust-Root prüfen; keine Mail-/Slack-/GitHub- oder lokale Staging-Datei automatisch als Vertrauensanker interpretieren. Recovery-Entscheidung darf aus PAM-Review nie eine ausführbare Berechtigung ableiten.
3. Erst nach getestetem unverändertem nativen R3-Recovery-Integrationspunkt VOR Quarantäne, sicherem Abbruch ohne ursprüngliche Dateimutationen, verifizierter Package-/Genome-Metadaten- und Rollback-Kette eine 0.9.27-Promotion erwägen. Keine FreeOTP-Anforderung für interne Entwicklung; geschützte externe/PROD-Aktion nur innerhalb vorhandener transaktionsgebundener Berechtigung.

Nutzervereinbarung zu Quellen/Webrecherche und verfügbaren Plugins: development/0.9.27/RESEARCH-AND-TOOLS-POLICY.md. Bisheriges High-Water-Konzept: development/0.9.27/pam/HIGH-WATER-ANCHOR-CONTRACT.md.
