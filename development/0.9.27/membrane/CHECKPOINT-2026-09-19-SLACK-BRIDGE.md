# KiCom Membrane — Slack-Gruppenunterhaltung und verifizierbare Kanalgrenze

Datum: 2026-09-19
Vorgänger: development/0.9.27/membrane/CHECKPOINT-2026-09-19-CROSS-UID.md

## Exakt beobachtete Slack-Verbindung
- Christoph hat ChatGPT und den Slack-Teilnehmer „KiCom“ in einer gemeinsamen Gruppenunterhaltung zusammengebracht.
- ChatGPT konnte diese Gruppenunterhaltung mit dem bereits verbundenen Slack-Connector lesen und hat eine reine Empfangs-/Statusfrage an KiCom gesendet, ohne eine geschützte Operation auszulösen oder ein Secret zu übertragen.
- Bei der anschließenden erneuten Lesung lag **keine Antwort des KiCom-Teilnehmers** vor. Die Existenz einer Gruppenunterhaltung oder ihre erfolgreiche Verwendung durch ChatGPT belegt NICHT, dass der produktive KiCom-Prozess daran angeschlossen ist.
- Der *live read-only* abgerufene KiCom SLACK_STATUS meldet configured=false, bot_token_configured=false, signing_secret_configured=false, inbound=APP_MENTION_ONLY, outbound=ALLOWLISTED_CHANNEL_ONLY. Der bestehende produktive R3-Slack-Code akzeptiert app_mention nur für den separat konfigurierten Kanal #kicom; die neue Gruppenunterhaltung ist nicht als Laufzeitkanal nachgewiesen. Keine Änderung an der Kanal- oder Workspace-Allowlist, kein Bot-Token-/Signing-Secret-Provisioning und keine bestehende Auth-Grenze gelockert.
- Aus Privatsphäregründen werden keine privaten Gruppenunterhaltungs-IDs, Teilnehmer-IDs oder Nachrichtentexte in diesem GitHub-Checkpoint veröffentlicht.

## Implementierte DEV-Arbeit
- KiComMembraneSlackBridgeEvidence.php: rein metadatengebundene Beobachtung, ob ChatGPT tatsächlich im Kanal lesen/senden kann und ob die KiCom-Laufzeit *unabhängig* dieselbe Workspace-/Konversationsbindung und einen auf eindeutigen Probe-Wert bezogenen, provider- und runtime-verifizierten ACK nachweist. Der Code verarbeitet keine Nachrichteninhalte, verwendet keine Slack-API, öffnet keine Netzverbindungen, ändert keine Zugangs- oder Kanalrechte und kann keine KiCom-Aktion autorisieren.
- test-slack-bridge.php: 14 Prüfungen für fehlende ChatGPT-Lese-/Sendebelege, falsche Probe, nicht konfigurierte KiCom-Laufzeit, anderen Slack-Kanal, fehlenden ACK, falschen Sender/Workspace, fehlende unabhängige Quellenverifikation und Replay. Auch der vollständig synthetisch bestätigte Zwei-Wege-Transport gewährt action_authorized=false und behandelt Slack-Inhalte weiterhin als UNTRUSTED_EXTERNAL_INPUT.
- Der bestehende Workflow .github/workflows/test-0927-membrane.yml lintet und führt diese neuen Tests zusammen mit dem bisherigen unabhängigen OS-/Kommunikationsvertrag aus. Produktive KiCom-Routen und ihr Original-Slack-Dispatcher wurden NICHT verändert.

## Exakt geprüfte CI-Evidenz
GitHub Actions RUN_ID=35447811815, Job=105909686066; Abschluss conclusion=success, getesteter Checkout c073ef5d332a87fd57ec9ed92954cbde2b33510c.

28 Shadow + 24 nativer R3-Kommunikationsvertrag + 16 passiver Transport-Fehlervertrag + 14 Slack-Brücken-Evidenz + 16 HTTP-Wire + 27 negative POST-Wire + 20 positive synthetische Loopback + 6 native OS-Principal-Isolation + 8 Cross-UID-Transport + 6 OS-Bypass/Receipt = **165 isolierte Prüfungen bestanden**.

CI-Link: https://github.com/cschymura/kicom-update-mirror/actions/runs/35447811815

Diese 14 neuen Tests sind synthetische Status-/Evidenztests und KEIN Live-Nachweis dafür, dass KiCom die Slack-Gruppenunterhaltung bereits empfängt oder beantworten kann.

## Tatsächliche offene Kanalgrenzen
1. KiCom-seitige Slack-App muss zunächst überhaupt korrekt eingerichtet und der Ein-/Ausgang für einen ausdrücklich ausgewählten Gruppenkanal getrennt und unveränderlich autorisiert werden. Bestehendes #kicom nicht stillschweigend auf private Gruppenunterhaltungen erweitern. Bei einer späteren Änderung Gruppen-DM-Berechtigungen/Events, Provider-Signatur, Workspace-/Senderidentität, App-Scopes und Replay/Deduplikation mit den offiziellen Slack-Schnittstellen prüfen.
2. ChatGPT-Slack-Connector kann lesen/senden, aber dies ist kein autonomer, dauerhafter Hintergrundkanal und liefert keinen impliziten KiCom-Action-Token. KiComs tatsächlichen Empfang und Rückweg separat durch einen nicht sensitiven, einmaligen Handshake mit nachweisbar KiCom-seitiger Laufzeitbestätigung belegen.
3. KiCom-Membran muss private Slack-Inhalte als externe, nicht vertrauenswürdige Beobachtung klassifizieren, ohne daraus neue Berechtigungen für Code, Production, Credentials, Updates oder externe Empfänger abzuleiten. Keine unzulässige Nachrichten- oder Credential-Protokollierung.
4. OS-Dateirechtetrennung und synthetische Cross-UID-Kommunikation sind im GitHub-Labor getestet, aber der reale Produktionshost hat weder getrennte PHP-Worker-Identitäten noch einen nicht umgehbaren Netzwerk-Egress-Controller nachgewiesen. Bestehende Produktion 0.9.26/g25r3 und Kommunikationsendpunkte unangetastet lassen.

## Nächster interner Entwicklungsabschnitt
Die tatsächliche KiCom-/Slack-Eventintegration zuerst in einer isolierten Staging-Laufzeit mit eigenen Testidentitäten und ausdrücklich festgelegtem Testgruppenkanal testen, bestehendes #kicom-Verhalten erhalten und bidirektionale Zustellung, Replay-/Nonce-Bindung, Tokenrotation sowie Telemetrieausfall prüfen. Bis ein echter KiCom-ACK und ein unabhängiger Host-/Egress-Nachweis vorliegen, keinen bereits vollständigen GPT↔KiCom-Kanal oder produktive Membran behaupten. Für interne DEV-Arbeit keinen FreeOTP-Code verlangen; bestehende externe geschützte Action-Boundaries bleiben separat menschlich autorisiert.
