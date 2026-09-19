# KiCom – Vereinbarung zu technischer Recherche und Werkzeugwahl

Stand: 2026-09-19. Vom Projektauftraggeber festgelegte Arbeitsweise für die fortgesetzte KiCom-Entwicklung.

## Recherche vor Spekulation

Wenn ein Begriff, eine API, ein Sicherheits-/Integritätsmerkmal, eine Fehlermeldung oder eine technische Annahme für den nächsten Arbeitsschritt unbekannt oder unklar ist, wird dies – soweit ein Recherchezugang vorhanden ist – zunächst durch gezielte Webrecherche geklärt. Die Ergebnisse müssen in den tatsächlichen Code- und Laufzeitkontext eingeordnet werden. Eine Quelle ersetzt keine lokale Reproduktionsprüfung.

## Bevorzugte Quellen

1. Hersteller-/Upstream-Dokumentation, offizielle Standards und Dokumentation zuständiger Behörden und sonstiger offizieller Stellen.
2. Veröffentlichungen und technische Ressourcen von Universitäten und anerkannten Forschungsinstitutionen.
3. Primäre Open-Source-Projekte, ihre Original-Repositories, Quellcode, Tests, Issues, Änderungsprotokolle und dokumentierte Sicherheitsgrenzen (einschließlich Git-Repositories).
4. Sekundäre Beiträge nur ergänzend und mit getrennten Annahmen. Versions- und Datumsbezug dokumentieren.

Keine Quelle wird ohne Prüfung als Autorität für Änderungen an KiCom-Berechtigungen angesehen. Bei Widersprüchen Version/Anwendungsfall prüfen und abweichende Evidenz ausdrücklich dokumentieren.

## Plugins und Verbindungen

Verfügbare und vom Benutzer freigegebene Plugins dürfen nach fachlichem Ermessen eingesetzt werden, wenn sie Quellen, den Quellcode, GitHub-Checks oder andere relevante Funktionen besser zugänglich machen. Slack, Mail, Opera und GitHub werden als mögliche Arbeits-/Transportwege berücksichtigt, nicht als neue Quelle für KiCom-Berechtigungen. Das Hinzufügen oder Verbinden eines neuen Plugins benötigt die explizite Benutzeraktion; fehlende Verbindungen dürfen nicht durch vorgetäuschte Zugriffe ersetzt werden.

## Entwicklung und Freigabe

Erkenntnisse werden nach Möglichkeit mit Quellenlinks/Version, Reproduktionstest, exakter Code-/Paket-SHA, Ergebnis und nachvollziehbarem Checkpoint gesichert. Recherche, Plugin-Nutzung, PAM-Beobachtungen und Gedächtnisinhalte verleihen **keine** Produktions-, Credential-, Rechte- oder geschützte externe Aktionsberechtigung. Der bestehende KiCom-Verifier, unabhängige Autorisierung am tatsächlichen Action-Boundary, Backup, Integritätsprüfung, Healthcheck und Rollback bleiben maßgeblich.

## Primärquellen für SQLite-Snapshot-Arbeit am 2026-09-19

- SQLite Online Backup API: https://www.sqlite.org/backup.html
- SQLite Write-Ahead Logging und persistenter WAL-Zustand: https://www.sqlite.org/wal.html
- PHP SQLite3::backup(): https://www.php.net/manual/en/sqlite3.backup.php

Die Quellen begründen konsistente Backup-Erstellung, **nicht** die Annahme, dass eine zufällige Snapshot-ID innerhalb derselben Sekunde eine Erstellungsreihenfolge angibt. Die Reihenfolge ist durch KiCom-eigene Metadaten und Tests nachzuweisen oder als uneindeutig zu behandeln.
