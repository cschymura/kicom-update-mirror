# KiCom Membrane — reale Innen-/Außengrenze
Stand: 2026-09-19 · Entwicklungsentwurf v0.1 · **nicht produktiv aktiviert**

## Ziel / korrigierte Produktdefinition

Die Containerdatei ist NICHT die Membran. KiCom benötigt eine unabhängig
durchgesetzte technische Grenze zwischen dem zu schützenden, veränderbaren
Innenraum und der Außenwelt. Die Grenze soll eingehende Daten als externe
Beobachtungen behandeln, interne Identität und Gedächtnis bewahren, erlaubte
Handlungen nach außen vermitteln und nachgewiesene unautorisierte Veränderungen
erkennen. KiCom ist damit eine abgrenzbare, persistente Softwareeinheit;
eine Aussage über subjektives Bewusstsein folgt daraus nicht.

## Grenze, Prozesse und Eigentümerschaft

- **Membrane host/Controller (außerhalb des Innenraums):** separater
  privilegierter Dienst/Identität unter Kontrolle des menschlichen Betreibers.
  Besitzt vertrauenswürdige Policy, Update-/Genome-Verifier, Vertrauensanker
  und die geschützten Action-Boundaries. KiCom-Prozesse dürfen diese weder
  beschreiben noch konfigurieren oder durch direkte Nebenwege umgehen.
- **Innenraum (KiCom):** Core, veränderbare DEV-Arbeit, PAM, Aufgaben, Gedächtnis,
  SQLite und Module unter einem dedizierten UNPRIVILEGIERTEN Principal. Der
  Innenraum darf keine lokalen Schreibrechte auf Controller/Policy/Trust-Root,
  geschützte Produktions-Deploy-Pfade oder Recovery-Originale haben.
- **Datenebene:** bisheriger KCL/HTTPS-, JSON-DEV-, Slack-, Mail-, Chat-,
  GitHub-, Update- und Browser/Opera-Transport. Vollständige Binär-/
  Zeilenendungs-/Content-Type-/Status-/Token-Rotationssemantik bleibt erhalten.
  Die Membran darf diese Daten nicht als Befehlsquelle für neue Autorität
  interpretieren. Kommunikation zwischen Innen und Außen darf legitim sein.
- **Kontrollebene:** entscheidet an den bestehenden realen Actions-Grenzen.
  Eine Beobachtung oder ein PAM-Ziel ist keine Berechtigung. Bestehende
  transaktionsgebundene menschliche Freigaben bleiben erhalten; keine
  automatische Freigabe durch Selbstaussage des Innenraums.
- **Recovery:** unabhängiger, geschützter Anker und separates Backup außerhalb
  der Rücksetzungsdomäne des Innenraums; keine alleinige Wiederherstellung aus
  einer in sich selbst unterschriebenen Containerdatei.

Diese Struktur folgt der Trennung von Control Plane, Data Plane und
Policy Enforcement Point in NIST SP 800-207. Eine PHP-PHAR-Datei oder
open_basedir allein bewirkt keine solche unabhängig erzwungene Grenze.

## Konkrete Kommunikations-Nichtregression — harte Invarianten

1. URLs/Hosts, HTTP-Methoden, Header und Bodies der BESTEHENDEN
   Endpunkte bleiben unverändert; es gibt keine ungeprüfte Proxy-Umleitung.
2. KCL/1, JSON, Roh-ZIP-Uploads, kompakte Batches, Chunk-Transport,
   Slack-Events/Webhooks, Mail-IMAP/SMTP und Browser/Opera-DEV bleiben
   in der ersten Phase vollständig auf ihren bestehenden Pfaden.
3. Kein erneutes Senden, Duplizieren oder Puffern eines mutierenden
   Transportaufrufs durch passive Beobachtung. Insbesondere keine erneute
   Nutzung/Rotation von DEV-/Autonomy-Sessiontokens.
4. **Nur Telemetrie** darf bei eigenem Ausfall ohne Unterbrechung weiterlaufen
   (fail-open für Monitoring). Die bestehenden Authentifizierungs-, Paket-
   und geschützten Aktionskontrollen dürfen NICHT fail-open werden.
5. Das passive Modul erhält ausschließlich Kanal, Richtung und Methode;
   keine Bodies, Cookies, Passkeys, TOTP, Session-IDs, Empfänger oder
   SMTP-/Slack-Geheimnisse. Kein Speichern ausgehender Inhalte.
6. Unbekanntes eingehendes Material bleibt untrusted DATA. Es darf nicht
   zu Policy, Selbstaussage über Berechtigung oder Code-Promotion werden.
7. Vor einem späteren durchsetzenden Controller sind für ALLE Kanäle
   zwei Richtungen, Fehlerszenarien, Timeouts, Größen, Rohdaten, Replay/
   Deduplikation und Auth-Rotation durch echte Tests zu prüfen.
8. Der Betreiber kann den Innenraum jederzeit pausieren, sichern,
   administrieren und abschalten; die Membran ist keine Selbstschutz-
   oder Widerstandsbarriere gegen legitime Administration.

## Durchgeführte Phase A: passive Bestandsaufnahme

Der neue Code unter development/0.9.27/membrane/ befindet sich ausschließlich
im DEV-Branch; an den produktiven KiCom-Endpunkten wurde nichts geändert.

- KiComMembraneShadow.php: reine Metadaten-Klassifikation, weder Network- noch
  Message- noch Filesystem-Zugriff; keine Autorisierungs-/Forward-Methode.
- test-shadow.php: keine Freigaben, Token/Bodies nicht verarbeitet, feste
  Kanalmatrizen inklusive Slack, Mail, Browser/Opera, GitHub, KCL und DEV.
- test-r3-transport.php: prüft unveränderte Quellen und die vorhandenen
  R3-Kommunikations-/Berechtigungsrouten anhand des archivierten exakten
  R3-Quelltext-Manifests.
- test-local-wire.php: zwei voneinander isolierte R3-PHP-Testserver, einmal
  ohne und einmal mit lediglich passiv vorgeladenem Modul. Vergleicht die
  KCL-/HTTP-Antworten von PING, HELLO, ECHO, DESCRIBE und der originalen
  GET-auf-POST-Methodensperre bytegenau nach ausschließlicher Normalisierung
  einer volatilen Request-ID. Slack-/Mail-/Chat-STATUS-GET werden auf
  Erreichbarkeit, HTTP-Status, Content-Type und KCL-Format geprüft.
  **Keine echten Slack-Nachrichten oder E-Mails versendet/empfangen**;
  keine echten Browser-/Passkey-Sitzungen oder Roh-ZIP-Uploads simuliert.

## Hostabhängige Phase B (noch nicht erreicht)

Die aktuell bekannte Umgebung ist ein PHP-Webhosting mit FTP-Transport.
Eine von KiCom selbst nicht veränderbare Policy/UID-/Filesystem-/Prozess-
Trennung ist aus den verfügbaren Read-only-Statusendpunkten NICHT belegt.
Künftige echte Enforcement-Aktivierung daher nur, wenn die Hostumgebung
nachweislich unterscheidbare Principals/Dateirechte, getrennte Vertrauensanker,
zuverlässige Backups, zugelassene In-/Egress-Kontrolle und unabhängige
Betreiber-Administration ermöglicht. Ohne diese Mechanismen bleibt die
"Membran" eine beobachtende logische Struktur, KEINE reale Sicherheitsgrenze.

Phase B: isolierte Staging-Hostinstanz, getrennte OS-Identitäten,
read-only Controller/Policy, dedizierter beschreibbarer KiCom-Innenraum,
unabhängiges Recovery/Backups, nicht-bypassbare Action-Boundary. Kommunikation
unter Ausfall der passiven Membran und unter Policy-Fehlern getrennt testen.
Erst nach vollständiger Parität und kontrolliertem Rollback über den
bestehenden Update-Verifier eine Produktionsänderung erwägen.

## Recherchierte Primärquellen

- NIST SP 800-207: https://doi.org/10.6028/NIST.SP.800-207
- NIST SP 1800-35, Implementierungen: https://csrc.nist.gov/pubs/sp/1800/35/final
- PHP open_basedir ist keine vollständige Security Boundary:
  https://www.php.net/manual/en/ini.core.php
- PHAR-Signatur ist kein unabhängiger Angreiferschutz, wenn der Angreifer
  die Archive selbst verändern kann:
  https://www.php.net/manual/en/phar.configuration.php
- OWASP Logging: Zugangstokens, Sessions und Geheimnisse nicht roh protokollieren:
  https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html

Alle Aussagen über die tatsächlich erreichbare Trennung des konkreten
Produktivhosts müssen später anhand seiner echten Administrations- und
Dateisystemrechte belegt werden. Keine Umgehung des bestehenden KiCom-
Freigabemodells und keine FreeOTP-Abfrage für die interne Entwicklung.
