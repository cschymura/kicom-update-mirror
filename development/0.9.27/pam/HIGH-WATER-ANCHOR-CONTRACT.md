# KiCom 0.9.27 — unabhängiger High-Water-Vertrauensanker: Implementierungsvertrag

Stand: 2026-09-19. NUR Entwicklungsentwurf, nicht als produktive Zusage oder bestehende KiCom-Fähigkeit zu lesen.

## Tatsächliche vorhandene KiCom-Schnittstellen (read-only verifiziert)

DESCRIBE meldet RELEASE_ARCHIVE für append-only install/update/source artefacts mit Hash/Metadaten und MEMORY_ARCHIVE für geschützte, nicht öffentlich lesbare ChatGPT-Memory-Snapshots. ARCHIVE_STATUS: configured=true, target_class=staging, policy=append-only-never-overwrite. MEMORY_ARCHIVE_STATUS: snapshots=0. AUTONOMY_STATUS: enabled=true, sessions=0. Es wurde KEINE verfügbare generische, authentifizierte, gegen Rollback geschützte High-Water-Publikations- und Abfrageschnittstelle festgestellt. Insbesondere sind das staging-Archiv, eine lokale Snapshot-Datei, ein GitHub-Commit oder ein neuer Memory-Snapshot nicht automatisch ein von Recovery unabhängiger Vertrauensanker. Credentials und Protected-Action-Berechtigungen werden aus diesen Beobachtungen nicht abgeleitet.

## Bedrohungsmodell

Ein teilweiser oder vollständiger lokaler Rücksetzvorgang kann native SQLite-Datenbank + WAL + SHM, das Snapshot-Verzeichnis, die Ledger-Dateien und jeden dort gemeinsam verwalteten lokalen High-Water-Zeiger auf einen früheren, intern konsistenten Stand bringen. Eine SHA-verkettete lokale Liste allein erkennt einen solchen gemeinsamen Rollback nicht. Ein gültiges, unabhängig gespeichertes und authentifiziertes monoton fortschreitendes High-Water-Signal muss diesen Rollback nachweisen können, ohne dass die Snapshot-Speicherinstanz selbst die frühere Vertrauensposition ersetzen darf.

## Minimale Anforderungen an einen künftigen unabhängigen Anker

- Eigener Vertrauens-/Speicherbereich außerhalb der zu reparierenden SQLite-, Snapshot-, Workspace- und gemeinsamen Backup-Rollback-Domäne; Recovery-Prozess benötigt ausschließlich einen eng begrenzten, signatur- oder gleichwertig authentifizierten **Lesezugang**.
- Revisions-/WORM- oder äquivalente vor gemeinsamer Rücksetzung geschützte Speicherung. Kein Löschen/Überschreiben früherer bestätigter Positionen. Der extern bestätigte letzte Sequenzwert muss monoton sein; Konflikte blockieren neue Sicherungen und die automatische Wiederherstellung.
- Authentifizierter Datensatz: format_version, environment_id, genome_lineage, sequence (int), exact snapshot_id, snapshot_sha256, manifest_sha256, ledger_entry_sha256, previous_anchor_digest, published_at, publisher_identity, verification_proof. Zielinstanz, Umgebung und Trust-Key-Rotation müssen unabhängig geprüft werden.
- Ein bloßes vom DEV-Caller übergebenes PHP-Array ist keine authentifizierte Quellinformation. KiComPamHighWaterVerifier::inspect vergleicht lediglich Werte und liefert ausdrücklich independent_anchor_authenticated_here=false sowie restore_permitted=false.
- Veröffentlichung und Recovery-Prüfung dürfen keine neuen Remote-Write-, Shell-, SQL-, Credential- oder Produktionsaktionen in PAM öffnen. Ein dedizierter eng begrenzter Publisher/Reader muss im bestehenden KiCom-Action-Boundary und unter dem vertrauenswürdigen Genome getestet werden.

## Vorgeschlagener Schreibablauf (noch nicht implementiert)

1. Ein gemeinsamer Lock schützt ALLE Snapshot-Erzeuger einschließlich manueller, täglicher, Evolution- und möglicher nachträglicher Recovery-Sicherungen; bestehende Alt-/Orphan-Backups müssen vor dem ersten neuen Write konservativ inventarisiert werden.
2. Authentifizierte unabhängige High-Water-Position laden und gegen aktuell registrierte lokale Sequenz und Vollinventar verifizieren. Bei Unklarheit keine neue Snapshot-Ausführung.
3. Native konsistente SQLite-Sicherung in einen neuen eindeutigen Zieldateinamen erstellen, vollständig validieren und die tatsächlichen Bytes/Metadaten hashbinden. Kein ungeschütztes Kopieren nur der DB-Datei bei aktivem WAL.
4. Dateiinhalte UND die für einen Stromausfall erforderlichen Directory-Einträge nachweisbar persistieren. Der DEV-Prototyp verwendet fsync() nur auf dem neuen Ledger-FILE; dies ist noch KEIN nachgewiesener Verzeichnis-Durability-Commit.
5. Ledger-Eintrag mit Sequenz, exaktem Snapshot-/Manifest-Hash und Vorgänger-Digest append-only und nachvollziehbar publizieren. Bei Teilfehler bleibt ein gesperrter, nicht automatisch akzeptierter Zustand erhalten.
6. Neuen unabhängigen High-Water-Anker unter dessen eigenem Schutz veröffentlichen und die Bestätigung mit exakter Position zurücklesen. Erst danach Snapshot als unabhängig bestätigte neueste Position melden. Fällt die Bestätigung aus, den lokalen Kandidaten NICHT automatisch zum letzten bekannten guten Recovery-Punkt erklären.

## Vorgeschlagener Wiederherstellungsablauf (noch nicht implementiert)

- Vor kicomSqliteDb(true), Quarantäne und jeder Dateioperation muss ein schreibfreier Preflight den unabhängigen Anker authentifizieren und die Kandidatenbytes/Manifest-/Ledger-/Zeit- und Umgebungslinie exakt verifizieren.
- Bei fehlendem, widersprüchlichem oder zurückgesetztem Anker; Legacy-Gleichzeitigkeit; Orphans; defektem Backup; fehlender sicherer Originalerhaltung: ABORT ohne Quarantäne und ohne Rekonstruktion aus Legacy-Mirror. Unveränderte Original-DB/WAL/SHM dürfen durch einen bloßen Preflight nicht verändert werden.
- Eine getrennt verifizierte und gequiescte Erhaltung der Original-DB/WAL/SHM einschließlich Dateizugehörigkeit und Hash ist vor jeder irreversiblen Operation erforderlich. Ein korrektes Backup der *beschädigten* Originaldateien darf nicht mit einer gültigen SQLite-Online-Sicherung verwechselt werden.
- Nur im unabhängigen, autorisierten Recovery-Kern kann nach exakt gebundenem Kandidaten ein zweiphasiger Restore erfolgen: validierte temporäre Ziel-DB, überprüfte Bytes und Integrität, atomare/rollbackfähige Umschaltung, Nachprüfung, dokumentiertes Ergebnis und gesicherter Rückfallstand. PAM darf diese Autorisierungsgrenze niemals selbst vergeben.

## Nachweis-/Prüfplan

A. Lokal stimmige, aber vollständig auf einen älteren Stand zurückgesetzte SQLite+Snapshot+Ledger-Bytes; externer Anker bleibt neu => Ablehnung.
B. Auch externe Ankerbehauptung lokal gefälscht/zurückgesetzt => Quell-Authentisierung muss verweigern, selbst wenn der reine Wertevergleich erfolgreich ist (test-high-water demonstriert diese Lücke mit independent_anchor_authenticated_here=false).
C. Gleiche-Sekunde-Legacy, fehlender Snapshot, beschädigte WAL-Datei, unregistrierte .sqlite ohne Manifest, Snapshot ohne erfolgreich publizierten Ledger, Ledger ohne bestätigten externen Anker => keine Quarantäne.
D. Gleichzeitig gestartete manuelle/tägliche/Evolution-Writer; fehlgeschlagenes fsync, ungesicherter Directory-Eintrag und unterbrochene Ankerbestätigung => kein geclaimter Recovery-Commit.
E. Getrennte Restore-Probe auf isoliertem R3/0.9.27-System, vollständiger Integritätscheck, nachgewiesene Erhaltung aller Originaldateien und kontrolliertes Rollback. Erst danach erwägen, eine ausführbare produktive Recovery-Änderung als signiertes/manifestgebundenes Paket zu erstellen.

## Primärdokumentation und bisherige Evidenz

- https://www.sqlite.org/backup.html — konsistente Online-Backups.
- https://www.sqlite.org/wal.html — WAL gehört zum persistenten Datenbankzustand.
- https://www.sqlite.org/lang_vacuum.html — VACUUM INTO, Unterbrechung und Ergebnis-Durabilität.
- https://www.php.net/manual/en/function.flock.php — Advisory Locking erfordert gemeinsamen Lock aller Writer.
- https://www.php.net/manual/en/function.fsync.php — fsync für PHP-Dateistreams ab 8.1.
- https://man7.org/linux/man-pages/man2/fsync.2.html — fsync-Datei garantiert nicht zwingend die Persistenz des Verzeichniseintrags.
- Aktueller DEV-Checkpoint: CHECKPOINT-2026-09-19-HIGH-WATER-CRASH.md.
