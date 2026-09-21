# Mirage Engram DEV-46 — echtes JSON-RPC/MCP-Protokoll, intern lesend, CI GREEN

Stand 2026-09-21. Basis: DEV-45 Checkpoint `3ed1715d76aa1dc9967f8a07bf8d904b0dff5a11`, Zielbranch `work/kicom-0.9.27-pam`. Vor Änderungen Slack-Auftragspool und Branch auf Konflikte geprüft; DEV-46 für 30 Min reserviert. Christophs realer 0.9.29-Speichertest und Passkey-Zuordnung wurden nicht wiederholt.

## Tatsächlich entwickelte und gesicherte Dateien
- `development/0.9.27/engram/KiComEngramMcpProtocol.php`: interner, transportneutraler **JSON-RPC-2.0/MCP-Protokollhandler** für `initialize` (Version 2025-06-18), `notifications/initialized`, `ping`, `tools/list`, `tools/call` mit dem einzigen Werkzeug `engram_search`. Keine reine proprietäre Adapter-JSON-Nachricht mehr: die vom Client sichtbare Tool-Metadaten-/Aufrufhülle verwendet reale MCP-Methoden und -Resultate. Standardmäßig lesend, Anfrage-/Antwortgrößen begrenzt, synthetischer Besitzerkontext wird AUSSCHLIESSLICH serverseitig erzeugt. `engram_remember`/unfreigegebenes Schreiben ist nicht exponiert.
- Der Handler bindet `KiComEngramMcpRuntimeGate.php` aus DEV-44 ein, das VOR Methode/Toolauflistung den aktiven Host-/Owner-/Connectorzustand prüft und den internen Adapter/Store erst NACH Authentifizierung erstellt. Bei KiCom 0.9.29/0.9.30 `INACTIVE_PRIVATE_SCAFFOLD` wird schon die Methode `initialize` abgelehnt, ohne einen Speicher anzusprechen.
- `development/0.9.27/engram/test-engram-mcp-protocol.php`: synthetischer kompletter JSON-RPC-MCP-Aufruf -> DEV-44 Runtime-Gate -> DEV-43 JSON-Adapter -> DEV-42 Controller -> SQLite-Store -> minimal projizierte Rückgabe. Negative Fälle: fehlende/andere Connector-Identität, widerrufener Owner, inaktiver Host, unbekannte Methoden, verbotene Schreib-Tools, Owner-Spoof, ungültige Limits, ungültiges JSON, nicht zulässige Request-Keys, mehrfache Abfragen und SQLite-Gesundheit.
- `.github/workflows/test-0927-engram-mcp-protocol.yml`: originalen unveränderlichen R3-Release und Originalquellmanifest prüfen, PHP lint + kompletten synthetischen MCP-Durchlauf auf PHP mit pdo_sqlite ausführen.

## Exakter Testnachweis und behobener Fehler

Erster dedizierter CI-Lauf **35578088067** auf `d68b2bd973918bd20e63b36f4495b849873d0e86` **FAILED**: fehlerhaftes JSON wurde als interner Fehler statt JSON-RPC Parse error klassifiziert. Implementierung auf korrekten `-32700`-Fehler korrigiert und passende Negativprüfung geändert. Diese Fehlrunde nicht als grün ausgeben.

**Korrigierter Code-/Test-SHA `9a217077c341a1b95396b8ddc4de00d588321da3`**. Dedizierter GitHub Actions Lauf **35578157307**, Job **106264611440**, Abschluss **SUCCESS**, Original-R3-Release-/Manifest-Check und alle sieben PHP-Syntaxprüfungen bestanden, **36/36 synthetische Positiv-/Negativtests bestanden**. Nachweise: https://github.com/cschymura/kicom-update-mirror/actions/runs/35578157307 .

## Tatsächlicher Aktivierungsstand und nächster Engpass

Diese Lösung ist ein tatsächlicher MCP-Protokollbaustein, aber **noch kein von ChatGPT erreichbarer Streamable-HTTP-MCP-Server**: Es fehlen eine eigens authentifizierte First-Party-HTTPS-Route mit passender OAuth-/Client-Anmeldung, eine reale Host-/Alias-/PHP-UID-/Backup-Isolationsprüfung, verifizierte Aktivierung und die Registrierung des Plugins im tatsächlichen ChatGPT-Konto. Der rein synthetische `active`-Testzustand ist keine Serverfreischaltung. 0.9.30 ist nur ein bereitgestellter lokaler Installationskandidat mit lesender Hostdiagnose; keine Meldung von Christoph über dessen Live-Installation vorhanden. Keine private Speicherung/Offenlegung, kein Passkey/Sitzungstoken, keine produktive Installation oder Konfigurationsänderung in diesem DEV-Abschnitt.

Nächster sinnvoller Entwicklungsschritt: die echte KiCom-First-Party-HTTPS-Route + verifizierbare OAuth-Connector-Identität entwickeln und von der vorhandenen KiCom-Authentifizierung sauber trennen, ohne eine nicht authentifizierte Route zu öffnen. Anschließend den echten MCP-Client-End-to-End-Test und das native Release bauen, nach realer Hostprüfung und gesonderter Betreiberfreigabe. Den Benutzer nicht erneut durch SQLite-/Passkey-Nachweise schicken.
