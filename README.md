# Nova Suche für Shopware 6

🇬🇧 [English version](README.en.md)

Schnelles **Suchfenster** für Shopware 6.7: Ein Klick ins Suchfeld öffnet ein großes Fenster mit Produktbildern, Filtern und Sortierung – ohne externen Suchdienst.

Die Suche **lernt dabei mit**: Was Kunden suchen, anklicken, in den Warenkorb legen und kaufen, verbessert die Reihenfolge der Treffer. Suchbegriffe ohne Treffer (z. B. „bremsbelag ente“) wertet auf Wunsch **Claude** einmalig aus – die geprüfte Umschreibung („bremsbelag 2cv“) wird dauerhaft gespeichert und gilt ab dann für alle Kunden, ohne weitere KI-Kosten.

## Funktionen

**Beim Öffnen (noch nichts getippt)**
- **Zuletzt gesucht** – die letzten Suchbegriffe des Besuchers als Chips, einzeln entfernbar oder „Alles löschen“ (nur im Browser gespeichert, mit derselben Zustimmung wie „Zuletzt angesehen“)
- **Beliebte Suchanfragen** – automatisch aus echten Suchen ermittelt (und/oder von Hand gepflegt); ein Klick startet die Suche
- **Zuletzt angesehen** – die Produkte, die der Besucher zuletzt angeschaut hat
- **Aktuelle Highlights** – von Hand gewählt oder automatisch: **Bestseller** (in den meisten Bestellungen – 100 Schrauben in einer Bestellung zählen als ein Kauf), **beliebt in der Suche** oder **neu** (jeweils 90 Tage, stündlich aktualisiert; gewählte Produkte stehen vorne). Produkte ohne Bild werden ausgeblendet (Schalter „Nur Produkte mit Bild“)

**Beim Tippen**
- **Produktkarten mit Bild**, Name, Variante, Preis (inkl. „Ab“-Preis, Streichpreis und Rabatt-Markierung)
- **Filter „Marken“** mit Trefferanzahl, **Preisfilter** mit Schieberegler und Eingabefeldern und wahlweise **Eigenschaften** (z. B. Fahrzeugmodell)
- **Auf der Karte:** Bewertungssterne, Hinweis „Derzeit nicht lieferbar“ und Button **„In den Warenkorb“** (bei Produkten ohne Variantenauswahl; öffnet den Warenkorb wie gewohnt)
- **Keine Treffer:** eigener Text je Shop (z. B. „Teil anfragen“) und **Produktempfehlungen**
- **Sortierung** (Relevanz, Preis, Name … – wie in Shopware eingestellt)
- **Passende Kategorien** als Schnellzugriff, z. B. „Bremsanlage 2CV6“
- **Automatisches Nachladen** weiterer Produkte beim Scrollen
- Suchwörter werden hervorgehoben

**Bedienung**
- Enter öffnet die volle Ergebnisseite, Esc oder ✕ schließt das Fenster
- ↓/↑ durch die Treffer, Fokus bleibt im Fenster
- **Smartphone:** Fenster über den ganzen Bildschirm, zwei Produktspalten, Filter hinter einem „Filter“-Button, Lupen-Symbol öffnet das Fenster direkt
- Passt sich automatisch an das Theme an (Farben, Eckenradien)

**Dynamische Lieferzeiten:** Ist das Plugin „Dynamische Monats-Lieferzeiten“ installiert und dort „Versandtermin in Produkt-Listings anzeigen“ aktiv, zeigt jede Suchkarte kompakt den nächsten Versandtermin („Versand am Mo, 02.11.2026“). Ohne das Plugin passiert nichts.

## Schnell – auch ohne OpenSearch

- Nutzt Shopwares eigene Produktsuche (MySQL oder – falls vorhanden – OpenSearch)
- Berechnet nur Marken- und Preisfilter; Eigenschafts-, Bewertungs- und Versandfilter werden für das Fenster abgeschaltet
- Beim Nachladen werden gar keine Filter berechnet
- Antworten sind **HTTP-Cache-fähig** und werden zusätzlich im Browser zwischengespeichert
- Anfragen werden beim Tippen gebündelt (Verzögerung einstellbar), veraltete Anfragen abgebrochen
- Ca. 15 KB JavaScript, Inhalte werden erst beim ersten Öffnen geladen

## Lernen aus dem Kundenverhalten

| Signal | Wie erfasst | Wirkung |
|---|---|---|
| Suche | Suchfenster und Suchergebnisseite – Zwischenstände beim Tippen („ra“, „rah“, „rahmen“) zählen nicht, nur der fertige Begriff | Beliebte Suchanfragen, Begriffe ohne Treffer |
| Korrektur | Suche ohne Klick, gleich danach ähnliche Schreibweise mit Klick („vergser“ → „vergaser“) | Ab 2 Beobachtungen wird „vergser“ automatisch als „vergaser“ gesucht |
| Klick auf ein Ergebnis | Suchfenster und Suchergebnisseite | +1 Punkt für das Produkt bei diesem Begriff |
| In den Warenkorb nach einer Suche | überall im Shop | +3 Punkte |
| Kauf nach einer Suche | beim Bestellabschluss | +5 Punkte |

**Bessere Platzierung:** Erreicht ein Produkt bei einem Suchbegriff den eingestellten Mindest-Lernwert (Standard 3 Punkte), rückt es bei genau diesem Begriff in der Sortierung „Relevanz“ nach oben – im Suchfenster und auf der Suchergebnisseite. Die Trefferliste selbst bleibt unverändert (es tauchen keine unpassenden Produkte auf), Einzelklicks verzerren nichts. Seit 1.12.0 zählt jedes Signal je Besucher (IP), Begriff und Produkt höchstens einmal am Tag, und ein Produkt rückt erst nach oben, wenn die stündliche Prüfung bestätigt hat, dass die Suche es bei diesem Begriff auch ohne Lernen findet – erfundene Klicks auf fremde Produkte bleiben so wirkungslos. Die Stärke wächst logarithmisch und ist auf das Doppelte der besten Shopware-Bewertung begrenzt.

**Tippfehler lernen (1.11.0):** Die Suche erkennt Korrekturen selbst – ohne dass jede falsche Schreibweise von Hand hinterlegt werden muss. Als Korrektur gilt nur eine kleine Abweichung in der Schreibweise mit denselben Zahlen („spiegel t2“ → „spiegel t3“ ist ein anderes Teil, „bremse“ → „auspuff“ ein neuer Gedanke). Seit 1.12.0 zählt eine Korrektur je Besucher und Tag nur einmal und wird erst übernommen, wenn sie oft genug **an mindestens zwei verschiedenen Tagen** beobachtet wurde **und** die stündliche Prüfung zeigt, dass der Begriff selbst keine Treffer hat, die Korrektur aber schon. Gelernte Korrekturen stehen unter *Auswertung → Ohne Treffer → Gespeicherte Umschreibungen*, lassen sich dort deaktivieren und werden wie Claude-Umschreibungen alle 30 Tage nachgeprüft (entfernt, sobald der Begriff selbst etwas findet oder die Korrektur nichts mehr). Einstellungen: *Tippfehler aus dem Verhalten lernen*, *Tippfehler übernehmen nach so vielen Beobachtungen*. Ergänzt Claude, das nur Begriffe **ohne** Treffer auswertet – ein Tippfehler wie „vergser“ findet oft irgendetwas Unpassendes.

**Beliebte Suchanfragen:** die häufigsten Begriffe mit Treffern aus den letzten 90 Tagen, ab einer Mindestanzahl Suchen (Standard 5) und an mindestens drei verschiedenen Tagen gesucht. Die Trefferzahl prüft der Shop selbst (stündlich im Hintergrund), nicht der Browser – neue Begriffe erscheinen daher mit bis zu einer Stunde Verzögerung. Eine **Sperrliste** verhindert, dass unerwünschte Begriffe öffentlich angezeigt werden. Von Hand gepflegte Begriffe stehen immer vorne.

## Claude: Suchbegriffe ohne Treffer dauerhaft verbessern

1. Ein Begriff liefert keine Treffer → er wird vorgemerkt.
2. Stündlich (Scheduled Task) bzw. sofort per `bin/console nova-search:rewrite` gehen die offenen Begriffe **gebündelt** (bis 30 je Anfrage) an Claude (Modell `claude-haiku-5-5`). Claude kennt dabei Shopname, Sprache sowie Kategorie- und Markennamen des Verkaufskanals und schlägt je Begriff bis zu drei Alternativen vor (Tippfehler, Spitznamen, Füllwörter).
3. **Jede Alternative wird gegen die echte Produktsuche geprüft** – gespeichert wird nur, was tatsächlich Treffer liefert.
4. Ab dann sucht der Shop bei diesem Begriff automatisch mit der Umschreibung – im Suchfenster, auf der Suchergebnisseite und in den Vorschlägen – und zeigt den Hinweis „Ergebnisse für „bremsbelag 2cv““.
5. Nach 30 Tagen wird nachgeprüft: Findet der ursprüngliche Begriff inzwischen selbst etwas (neues Sortiment), wird die Umschreibung entfernt. Begriffe, für die es damals keine Lösung gab, bekommen eine neue Chance.

**Nachhaltig:** Jeder Begriff wird nur **einmal** an Claude geschickt – nicht bei jeder Suche. Die Verbesserung bleibt dauerhaft in der Datenbank und wirkt ohne weitere Anfragen.

**Optional sofort:** Mit „Neue Begriffe auf der Suchergebnisseite sofort auswerten“ bekommt der erste Kunde mit einem neuen Begriff direkt passende Treffer (Wartezeit beim ersten Mal bis zu 4 Sekunden). Zum Schutz vor Missbrauch gilt das nicht für Bots und höchstens dreimal je IP und Stunde (30 je Verkaufskanal); sonst wird der Begriff wie üblich im Hintergrund ausgewertet. Neue Begriffe ohne Treffer kommen je IP höchstens 30-mal pro Stunde in die Warteschlange, häufig gesuchte Begriffe werden zuerst ausgewertet.

**Kosten (Schätzung):** Claude Haiku 5.5 kostet 0,10 $ je 1 Mio. Eingabe- und 0,50 $ je 1 Mio. Ausgabe-Tokens. Eine gebündelte Anfrage mit 30 Begriffen braucht grob 1.500 Eingabe- und 1.000 Ausgabe-Tokens – also deutlich unter 0,1 Cent. **1.000 neue Begriffe ohne Treffer kosten damit rund 2–3 Cent.**

### Kostenlimit

Damit die Claude-Stufe nie teurer wird als geplant, gibt es ein **monatliches Kostenlimit** (Standard **5 USD**):

- Das Limit gilt **für alle Verkaufskanäle zusammen** – in der Regel nutzen alle denselben API-Schlüssel. Bitte unter *Erweiterungen → Nova Suche → Konfigurieren* mit der Auswahl **„Alle Verkaufskanäle“** einstellen. `0` schaltet Claude komplett ab.
- Nach jeder Antwort werden die von der API gemeldeten Tokens (Eingabe, Ausgabe, ggf. Cache) mit den Modellpreisen umgerechnet und je **Kalendermonat (UTC)** gespeichert.
- **Vor jedem Aufruf** wird geprüft, ob die geschätzten **Höchstkosten** des Aufrufs (Prompt + volle Antwortlänge) noch ins Restbudget passen. Ein einzelner Aufruf kann das Limit deshalb nicht nennenswert überschreiten. Bei Haiku 5.5 sind das höchstens ca. 0,2 Cent je Aufruf.
- Ist das Limit erreicht, werden **keine Anfragen mehr gestellt**. Offene Begriffe bleiben vorgemerkt und werden im nächsten Monat (oder nach Erhöhung des Limits) ausgewertet – auch die Sofort-Auswertung auf der Suchergebnisseite hält sich daran. Die Suche selbst läuft normal weiter.
- Beim Erreichen der **Warnschwelle** (Standard 80 %) und des Limits steht **einmal pro Monat** ein Hinweis im Shopware-Log.
- Verbrauch ansehen: `bin/console nova-search:claude-usage` (aktueller Monat mit Anfragen, Tokens, Kosten, Limit, Rest sowie die letzten 12 Monate) oder Export mit `bin/console nova-search:export --type=claude-usage`.

Preise in der Berechnung (USD je 1 Mio. Tokens, Eingabe/Ausgabe): `claude-haiku-5-5` 0,10/0,50 (bei Prompts über 100.000 Tokens 0,50/2,50), `claude-sonnet-5-5` 2/10, `claude-opus-5-5` 4/20, `claude-fable-5-1` 10/50. Unbekannte Modelle werden mit den teuersten bekannten Preisen gerechnet. Prompt-Caching nutzt das Plugin nicht; meldet die API trotzdem Cache-Tokens, zählen Cache-Lesezugriffe wie Eingabe und Cache-Schreibzugriffe doppelt (bewusst eher zu hoch).

> **Empfehlung – zweite Sicherung in der Anthropic Console:** Lege in der [Anthropic Console](https://console.anthropic.com) einen **eigenen Workspace** (z. B. „Shop-Suche“) mit einem **Ausgabenlimit** an und erstelle den API-Schlüssel in diesem Workspace. Ausgabenlimits gelten pro Workspace, nicht pro einzelnem Schlüssel. Der Zähler im Plugin ist eine Schätzung auf Basis der gemeldeten Tokens – **maßgeblich ist immer die Abrechnung in der Console**.

**Fehlerfest:** Fehlt der API-Schlüssel, ist Claude nicht erreichbar oder antwortet nicht verwertbar, läuft die Suche ganz normal weiter. Fehlversuche werden gezählt; nach drei Versuchen wird ein Begriff nicht mehr angefragt.

## Store-API für Apps

Die Shop-App nutzt die Nova Suche über die Store-API – mit demselben gelernten Ranking und denselben Claude-Umschreibungen wie das Suchfenster. Authentifizierung wie bei jeder Store-API-Anfrage über die Header `sw-access-key` und `sw-context-token`; alle Routen erwarten `POST` mit JSON-Body.

### Suche

```http
POST /store-api/nova-search/search
{ "search": "bremsbelag ente", "p": 1, "limit": 24, "order": "score",
  "properties": "<Options-ID>|<Options-ID>", "manufacturer": "<Hersteller-ID>" }
```

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `search` | ja | Suchbegriff, mind. 2 Zeichen – sonst `400` (Shopware-Fehlerstruktur) |
| `p` | nein | Seite (Standard 1) |
| `limit` | nein | Treffer pro Seite (Standard 24, max. 48) |
| `order` | nein | Sortierschlüssel aus `availableSortings`; unbekannte Werte → Standard-Sortierung |
| `properties` | nein | Eigenschafts-Optionen mit `|` getrennt, z. B. das gewählte Fahrzeug |
| `manufacturer` | nein | Hersteller-IDs mit `|` getrennt |
| `exactNumber` | nein | `true` = „Nur exakte Teilenummer“: ausschließlich Produkte mit genau dieser Nummer, sonst leer |

Antwort:

```json
{
  "ids": ["0190…", "0188…"],
  "total": 30, "page": 1, "limit": 24, "sorting": "score",
  "availableSortings": [{ "key": "score", "label": "Relevanz" }, { "key": "price-asc", "label": "Preis aufsteigend" }],
  "rewrite": { "original": "bremsbelag ente", "term": "bremsbelag 2cv" },
  "redirect": null
}
```

`ids` sind die Produkt-IDs in Ranking-Reihenfolge – Preise, Bilder und Bestand lädt die App wie gewohnt über `/store-api/product`. `rewrite` ist `null`, wenn ohne Umschreibung gesucht wurde; sonst kann die App „Ergebnisse für …“ anzeigen. `redirect` enthält das Ziel einer Weiterleitung für genau diesen Begriff (Pfad im Shop wie `/account` oder volle Adresse), sonst `null`.

**Wichtig:** Diese Route zählt die Suche **nicht** in der Statistik, weil die App sie beim Blättern und Nachladen mehrfach aufruft. Die App meldet jede fertige Suche genau einmal über `track` (siehe unten). Begriffe ohne Treffer werden hier nur für die Claude-Umschreibung vorgemerkt.

### Beliebte Suchanfragen

```http
POST /store-api/nova-search/popular
{ "limit": 10 }
```

Antwort: `{ "terms": ["chrom", "scheinwerfer", "rolldach"] }` – gleiche Liste wie im Suchfenster (manuelle zuerst, dann automatische, Sperrliste beachtet), max. 20.

### Nutzungssignale (Lernen)

```http
POST /store-api/nova-search/track
{ "events": [
  { "type": "search",   "term": "bremse", "total": 24 },
  { "type": "click",    "term": "bremse", "productId": "0190…" },
  { "type": "cart",     "term": "bremse", "productId": "0190…", "quantity": 2 },
  { "type": "purchase", "term": "bremse", "productId": "0190…" }
] }
```

Antwort immer `204`. Es gelten dieselben Regeln wie im Suchfenster: max. 20 Ereignisse pro Aufruf, **300 pro Kontext-Token und Stunde**, dazu 600 je Client-IP und Stunde und eine Obergrenze je Verkaufskanal, Klicks und Warenkorb je IP, Begriff und Produkt einmal am Tag, nur existierende Produkte, Begriffe werden normalisiert, E-Mail-Adressen und Telefonnummern verworfen. Gespeichert werden nur Begriff, Produkt-ID und Zähler – kein Token, keine Kunden- oder Bestelldaten.

`purchase` zählt nur, wenn der angemeldete Kunde (auch Gast) dieses Produkt in den **letzten 48 Stunden wirklich bestellt** hat, und je Kontext-Token und Produkt nur einmal. So lassen sich keine Käufe erfinden.

### Shopwares eigene Suche

Auch Shopwares Standard-Route `/store-api/search` nutzt das gelernte Ranking und gespeicherte Umschreibungen (die Plugin-Logik sitzt im Such-Builder). Dort wird nichts gezählt – die App kann bei Fehlern also gefahrlos darauf zurückfallen.

## Auswertung in der Administration

**Erweiterungen → Nova Suche** (eigener Menüpunkt, Reiter *Auswertung · Synonyme · Weiterleitungen*) zeigt für einen Verkaufskanal oder alle Shops und einen Zeitraum (7, 30, 90 oder 365 Tage):

- Gezählt werden echte Suchen: Bots, Vorschau-/Prefetch-Abrufe und automatische Aufrufe werden ignoriert, und derselbe Begriff zählt pro Besucher-Sitzung höchstens einmal in 30 Minuten (Neuladen, Zurück-Taste, Suchfenster und danach Enter)
- **Kennzahlen** mit Veränderung zum Vorzeitraum: Suchen, Anteil ohne Treffer, Klickrate, in den Warenkorb, Käufe nach einer Suche, verschiedene Begriffe
- **Suchen pro Tag** als Balken (orange: ohne Treffer)
- **Suchbegriffe** mit Suchen, Klicks, Klickrate, Warenkorb, Käufen – ⚠ markiert Begriffe, die oft gesucht, aber nie angeklickt wurden (Treffer passen nicht)
- **Ohne Treffer** mit Stand der Claude-Umschreibung und Knöpfen „Synonym“/„Weiterleitung“ zum direkten Beheben; darunter alle **gespeicherten Umschreibungen** (von Claude oder gelernt) mit Knopf „Deaktivieren“
- **Geklickte Produkte** (Link zum Produkt in der Administration)
- **Claude-Kosten** des laufenden Monats im Verhältnis zum Limit
- **CSV-Export** je Tabelle (Excel-tauglich, gleiche Daten wie `nova-search:export`)
- Knopf **„Einstellungen“** führt direkt zur Plugin-Konfiguration

Tageswerte werden ab Version 1.6.0 gezählt; frühere Zeiträume sind leer (Gesamtzahlen gibt es weiter im CSV-Export). Gespeichert werden nur Begriffe und Zähler (zum Personenbezug von Suchbegriffen siehe *Datenschutz*); Tageswerte werden nach 2 Jahren gelöscht.

**Berechtigungen (1.12.0):** Auswertung, Export sowie Synonyme/Weiterleitungen/Hinweise ansehen braucht die Berechtigung *Einstellungen lesen* (`system_config:read`), Anlegen, Ändern, Importieren und Deaktivieren *Einstellungen bearbeiten* (`system_config:update`) – dieselben Rechte wie für die Plugin-Konfiguration. Administratoren haben sie immer.

## Suchdaten aus einem bisherigen Suchdienst übernehmen

Damit „Beliebte Suchanfragen“, das gelernte Ranking und „Beliebt in der Suche“ nicht bei null starten, lassen sich die CSV-Exporte eines bisherigen Suchdienstes einlesen: *Erweiterungen → Nova Suche → Auswertung → **Daten importieren***, Verkaufskanal wählen, Dateien auswählen. Alternativ per Konsole:

```
bin/console nova-search:import-stats --sales-channel=<ID> suchbegriffe.csv produkte.csv
```

- **Suchbegriffe** (Spalten `search_term`, `total_searches`, `total_clicks`) → Suchen und Klicks je Begriff
- **Produkte** (Spalten `clicked_product_link`, `search_query`, `total_searches_clicks`) → welches Produkt bei welchem Begriff angeklickt wurde. Das Produkt wird über die **Produktnummer am Ende des Links** gefunden (Shopwares Standard-URL `…/Produktname/Produktnummer`); nicht gefundene Produkte werden übersprungen.
- Die Werte werden zu vorhandenen Zählern addiert. **Dieselbe Datei wird nur einmal übernommen** (je Verkaufskanal) – ein versehentlicher zweiter Import zählt nichts doppelt.
- Die Exporte enthalten nur Summen, keine Tageswerte: Kennzahlen, Grafik und Tabellen der Auswertung bleiben für die Zeit davor leer; im CSV-Export sind die Daten enthalten.
- Eigene Testsuchen (z. B. „test“) danach über die Sperrliste bei „Beliebte Suchanfragen“ ausblenden.

## Nummernsuche (Artikel-, Hersteller-, OE-Nummern)

Sieht der Suchbegriff wie eine Nummer aus (mindestens 3 Ziffern, keine weiteren Wörter), sucht die Nova Suche zuerst im **Nummern-Index** – und zeigt genau die Produkte mit dieser Nummer, inklusive Varianten. Gibt es keine passende Nummer, wird ganz normal gesucht.

- Schreibweise egal: `25043 25031`, `25043-25031`, `25.043.250/31` und `2504325031` finden dasselbe Produkt; vorangestellte Bezeichnungen wie `OE 5461234` oder `Nr. 5461234` werden erkannt.
- Durchsucht werden standardmäßig **Artikelnummer, Herstellernummer und EAN**. Zusätzlich wählbar: **Zusatzfelder** (technischer Name, z. B. `oe_nummer`) und **Eigenschaften** (z. B. „OE-Nummer“, „Vergleichsnummer“). Mehrere Nummern in einem Feld dürfen durch Komma, Semikolon, Zeilenumbruch oder Leerzeichen getrennt sein.
- **Checkbox „Nur exakte Teilenummer“** neben dem Suchfeld im Suchfenster: Ist sie angehakt, erscheinen **ausschließlich** Produkte mit genau dieser Nummer – keine unscharfen Treffer, kein Nummernanfang, keine Umschreibung. Gibt es die Nummer nicht, steht dort „Keine Teilenummer … gefunden“. So funktionieren auch Nummern mit wenigen Ziffern (z. B. `ab12cd`), die automatisch nicht als Nummer erkannt würden. Das Häkchen bleibt für die Sitzung des Besuchers erhalten und gilt auch für Enter (Suchergebnisseite). Abschaltbar unter *Nummernsuche*.
- **Ohne Häkchen** gilt als Nummer, was mindestens 3 Ziffern hat und aus einem Wort besteht: `1a32zq`, `25043 25031` und `5461234` ja – `2cv`, `2cv6` oder `ami8` nein, die werden ganz normal gesucht.
- **Nummern mit Gruppen-Vorsatz**: Steht vor der eigentlichen Nummer ein kurzer Vorsatz mit Bindestrich oder Schrägstrich (z. B. `281-281569`, `191A-194003`), findet auch der hintere Teil allein das Produkt: `281569`, `28 15 69`. Kunden tippen oft nur diesen Teil.
- **Anfang genügt** (abschaltbar): Gibt es keine exakte Nummer, werden Nummern gefunden, die so beginnen (ab 6 Zeichen).
- Der Index wird beim Speichern von Produkten automatisch aktualisiert. Komplett aufgebaut wird er beim ersten stündlichen Lauf nach der Installation und nach geänderten Einstellungen – sofort mit `bin/console nova-search:numbers`.
- Einstellungen unter *Konfigurieren → Nummernsuche*, am besten unter „Alle Verkaufskanäle“ (der Index gilt für alle Shops; was ein Shop zeigen darf, prüft weiterhin Shopware).

## Suchregeln: Synonyme und Weiterleitungen

*Erweiterungen → Nova Suche → Reiter **Synonyme** bzw. **Weiterleitungen**.* Jede Regel ist ein eigener Eintrag in einer Liste – mit Suchfeld, Schalter „Aktiv“, Bearbeiten und Löschen. Ohne Verkaufskanal gilt eine Regel für alle Shops; mit Verkaufskanal nur dort. Gilt für Suchfenster, Suchergebnisseite und App, sofort nach dem Speichern.

**Synonyme** – Gruppen gleichwertiger Begriffe, z. B. `bremsklötze, bremsbeläge`:

- Jeder Begriff einer Gruppe findet auch die Produkte der anderen: „bremsklötze“ zeigt Bremsbeläge.
- Mehrwortsuchen bleiben genau: „bremsklötze 2cv“ zeigt Bremsbeläge **für den 2CV**, nicht alle Bremsbeläge (Shopwares UND-Suche bleibt erhalten).
- Begriffe aus mehreren Wörtern sind möglich („zweiter schalldämpfer“), Groß-/Kleinschreibung ist egal.

**Weiterleitungen** – Suchbegriff → Ziel, z. B. `versand` → `/versand-und-zahlungsbedingungen/`:

- Gilt bei genau diesem Suchbegriff: Enter bzw. die Suchergebnisseite führt direkt zum Ziel; im Suchfenster erscheint oben ein Link „Direkt zu …“.
- Ziel ist ein Pfad im Shop (wird um die Shop-Adresse inkl. Sprachpfad ergänzt) oder eine vollständige `https://`-Adresse. Andere Angaben werden beim Speichern abgelehnt.
- Gibt es denselben Begriff allgemein und für einen Verkaufskanal, gewinnt der Verkaufskanal.
- Die App bekommt das Ziel im Feld `redirect` der Such-API.

**Aus der Auswertung beheben:** Im Reiter *Auswertung → Ohne Treffer* steht bei jedem Begriff „Synonym“ bzw. „Weiterleitung“ – das öffnet das Anlegen-Fenster mit dem Begriff schon eingetragen.

**CSV-Import und -Export** (Knöpfe über der Liste):

- Synonyme: eine Gruppe pro Zeile, Begriffe durch Komma getrennt. Exporte anderer Suchdienste mit mehreren Spalten funktionieren auch – die Spalte mit der Begriffsliste wird erkannt, Kopfzeilen und bereits vorhandene Gruppen werden übersprungen.
- Weiterleitungen: `begriff;ziel` (auch `begriff = ziel` oder mit Komma). Bereits vorhandene Begriffe werden übersprungen.
- Der Export ist im selben Format und lässt sich wieder importieren (z. B. in einen anderen Shop).

**Update von 1.6.0 oder älter:** Die bisherigen Textfelder unter *Konfigurieren → Suchregeln* werden beim Update automatisch in die Listen übernommen und danach aus der Konfiguration entfernt.

## Wenn dieser Shop nichts hat: Schwestershops, Hinweise, „Teil anfragen“

Betreiben Sie mehrere Shops in derselben Shopware-Installation, kann das Suchfenster Kunden zum passenden Shop führen.

**Schwestershops** (*Konfigurieren → Suchfenster: Treffer → Schwestershops*, pro Verkaufskanal): Findet dieser Shop nichts, sucht die Nova Suche in den gewählten Shops (max. 3) und zeigt deren Treffer als Karten – „In unserem Shop … gefunden“, mit Link „Alle … Treffer ansehen“. Preise, Sichtbarkeit und Verfügbarkeit kommen aus dem anderen Shop, Links führen auf dessen Domain und öffnen sich in einem neuen Tab; dort gibt es keinen „In den Warenkorb“-Knopf, und Klicks zählen nicht fürs Lernen dieses Shops. Gesucht wird, wenn dieser Shop zum eingegebenen Begriff nichts hat – bei 0 Treffern **und** wenn die Treffer nur über eine Claude-Umschreibung zustande kamen (z. B. „lichtmaschine cx“ → „lichtmaschine“); dann stehen die Treffer des Schwestershops über den umgeschriebenen. Nicht bei „Nur exakte Teilenummer“ oder wenn nur Filter alles ausschließen.

**Hinweise** (*Erweiterungen → Nova Suche → Hinweise*): Kommt eines der Wörter im Suchbegriff vor, erscheint oben im Suchfenster ein Hinweis – auch wenn der andere Shop das Teil gerade nicht hat. Beispiel:

- Wörter: `t2, t3, vw, golf, käfer`
- Hinweis: „Teile für VW finden Sie in unserem Shop …“
- Link: `https://www.anderer-shop.de/search?search={term}` – `{term}` wird durch den Suchbegriff ersetzt

Die Wörter müssen als ganzes Wort vorkommen („t2“ passt bei „spiegel t2“, nicht bei „t25“). Ohne Verkaufskanal gilt ein Hinweis für alle Shops.

**„Teil anfragen“** (*Konfigurieren → Suchfenster: Treffer*): Bei 0 Treffern klappt ein Knopf „Teil anfragen“ ein kleines Formular **direkt im Suchfenster** auf:

- „Ich suche: …“ ist schon eingetragen; **E-Mail-Adresse oder Telefonnummer genügt**, Name ist optional – absenden, fertig. Der Kunde bleibt in der Suche.
- Die Anfrage geht als Mail an den Shop (*Empfänger für Anfragen*, leer = Shop-E-Mail aus den Stammdaten); bei angegebener E-Mail-Adresse geht „Antworten“ direkt an den Kunden. Gespeichert wird nichts.
- Datenschutzhinweis bzw. -Häkchen wie im Shopware-Kontaktformular (*Einstellungen → Login & Registrierung*).
- Schutz vor Spam: unsichtbares Feld für Bots und höchstens 5 Anfragen pro IP-Adresse und Stunde.
- **Link zum Kontaktformular (optional)**, z. B. `/kontakt`: erscheint darunter als „Lieber zum Kontaktformular“; dort sind Betreff („Teileanfrage“) und „Ich suche: …“ vorausgefüllt. Ist das Formular im Suchfenster ausgeschaltet, erscheint nur ein Knopf mit diesem Link.
- Auch bei „Nur exakte Teilenummer“ ohne Treffer verfügbar.

## Ausgeschlossene Produkte

*Konfigurieren → Ausgeschlossene Produkte.* Ausgeschlossene Produkte erscheinen nicht in Suchfenster, Suchergebnisseite, Suchvorschlägen und App – über Kategorien und Direktlinks bleiben sie erreichbar.

- **Abverkaufsartikel ohne Bestand ausblenden** – „Abverkauf“ aktiv und Lagerbestand ≤ 0
- **Produkte** (bei Hauptprodukten auch alle Varianten), **Kategorien**, **Tags** (z. B. Tag „nicht in Suche“)
- **Zusatzfeld**: technischer Name eines Ja/Nein-Zusatzfelds am Produkt; ist es eingeschaltet, wird das Produkt ausgeblendet. So lassen sich bestehende Ausschluss-Markierungen eines bisherigen Suchdienstes weiterverwenden – das alte Plugin bzw. dessen geplante Aufgabe wird dann nicht mehr gebraucht.

## Umstieg von einem externen Suchdienst

1. Nova Suche installieren (ist zunächst in keinem Shop aktiv).
2. Synonyme und Weiterleitungen als CSV importieren, Ausschlüsse einstellen (siehe oben).
3. In einem kleinen Shop **„Sofortsuche verwenden“** einschalten, Cache löschen, testen.
4. Dort das Plugin bzw. Skript des bisherigen Dienstes deaktivieren – sonst öffnen sich zwei Suchfenster.
5. Nach ein paar Tagen die übrigen Shops umstellen, altes Plugin deinstallieren, Abo kündigen.

## Suchdaten exportieren

Alle gesammelten Daten lassen sich als CSV exportieren – zur Auswertung in Excel oder zur Analyse durch Claude:

```bash
bin/console nova-search:export                       # alles, alle Verkaufskanäle
bin/console nova-search:export --days=90             # nur die letzten 90 Tage
bin/console nova-search:export --type=terms --min-searches=3
bin/console nova-search:export --sales-channel=<ID> --output=/tmp/export
```

| Datei | Inhalt |
|---|---|
| `suchbegriffe.csv` | Begriff, Suchen, Treffer (zuletzt), Klicks, Warenkorb, Käufe, Klickrate, erstmals/zuletzt gesucht |
| `suchbegriffe-produkte.csv` | Begriff × Produkt (Nummer, Name) mit Klicks, Warenkorb, Käufen |
| `claude-umschreibungen.csv` | Begriffe ohne Treffer, Umschreibung durch Claude, Status, Treffer |
| `claude-kosten.csv` | Claude-Verbrauch je Monat und Modell: Anfragen, Tokens, Kosten in USD (für alle Verkaufskanäle zusammen) |

| Option | Bedeutung |
|---|---|
| `--type` | `terms`, `products`, `rewrites`, `claude-usage` (mehrfach möglich, Standard: alle) |
| `--sales-channel` | nur diese Verkaufskanal-ID(s) |
| `--days` | nur Einträge der letzten N Tage |
| `--min-searches` | Suchbegriffe erst ab N Suchen |
| `--output` | Zielordner (Standard `nova-search-export`) |
| `--delimiter` | Trennzeichen (Standard `;` für deutsches Excel) |

Die Dateien sind UTF-8 mit BOM (Umlaute in Excel korrekt). Suchbegriffe, die Excel als Formel ausführen würde (z. B. `=…`), werden mit einem Apostroph entschärft. Sie enthalten **keine Kundendaten** – nur Suchbegriffe, Produkte und Zähler.

## Datenschutz

- **Datenbank:** gespeichert werden Suchbegriffe, Produkt-IDs und Zähler – keine IP-Adressen, Kunden-, Bestell- oder Sitzungs-IDs. Begriffe, die wie E-Mail-Adressen, Telefonnummern oder Links aussehen, werden nie gespeichert. Suchbegriffe sind aber freie Eingaben: Tippt jemand einen Namen, eine Anschrift oder eine Kundennummer ins Suchfeld, wird dieser Text wie jeder andere Begriff gespeichert (bis 1 Jahr, Tageswerte 2 Jahre) und kann – wenn er keine Treffer hat und Claude aktiv ist – an Anthropic gehen. Das gehört in die Datenschutzerklärung (und bei aktivem Claude in den Auftragsverarbeitungsvertrag mit Anthropic).
- **IP-Adressen nur flüchtig:** Zur Missbrauchsabwehr werden Ereignisse, Klicks und Korrekturen je Client-IP begrenzt bzw. entdoppelt. Dafür liegt höchstens 24 Stunden ein Hash der IP im Shopware-Objekt-Cache, nie in der Datenbank (berechtigtes Interesse). Dasselbe gilt für die Teileanfrage (1 Stunde).
- **An Anthropic (Claude) gehen nur** Suchbegriffe ohne Treffer sowie Shop-, Kategorie- und Markennamen – keine Kunden- oder Bestelldaten (zu frei eingetippten Texten siehe oben). Claude ist standardmäßig ausgeschaltet.
- **Cookies/Speicher im Browser nur mit Zustimmung** (Shopware-Cookie-Banner):
  - *Statistik* → „Suche: Käufe Suchbegriffen zuordnen“: Cookie `nova-search-attr` merkt sich bis zu 2 Tage, welches Produkt über welchen Begriff gefunden wurde; wird nach der Bestellung gelöscht. Ohne Zustimmung werden nur anonyme Suchen und Klicks gezählt.
  - *Komfortfunktionen* → „Zuletzt angesehene Produkte merken“: Liste im localStorage (`nova-recently-viewed`), verlässt nie den Browser. Die Zustimmungspflicht ist abschaltbar.
- **Missbrauchsschutz:** Signale aus dem Suchfenster nur mit bestehender Shop-Sitzung von derselben Website und ohne Bots; max. 20 Ereignisse pro Aufruf, 300 pro Sitzung und Stunde, 600 je IP und Stunde und eine Obergrenze je Verkaufskanal; Klicks, Warenkorb und Korrekturen je IP und Tag einmal; nur existierende Produkte; öffentlich wirksam (beliebte Begriffe, Ranking, Highlights, gelernte Korrekturen) wird nur, was die stündliche Prüfung gegen die echte Suche bestätigt. Neue Begriffe für Claude und Schwestershop-Suchen sind je IP begrenzt.
- Einträge, die ein Jahr lang nicht vorkamen, werden automatisch gelöscht; beobachtete Korrekturen und nie gelöste Begriffe nach 180 Tagen. Beim Deinstallieren ohne „Daten behalten“ werden alle Tabellen entfernt.

## Installation

```bash
cd <shopware-root>/custom/plugins
git clone https://github.com/DrdotHouse2106/SW6-Nova-Suche.git NovaSearch

cd <shopware-root>
bin/console plugin:refresh
bin/console plugin:install --activate NovaSearch
bin/console theme:compile
bin/console cache:clear
```

- `plugin:install` installiert automatisch das offizielle **Anthropic-PHP-SDK** (`anthropic-ai/sdk`) per Composer und legt die Tabellen `nova_search_term`, `nova_search_term_product` und `nova_search_rewrite` an.
- `theme:compile` ist nötig, damit die Styles des Suchfensters ins Theme übernommen werden.
- **Danach ist das Suchfenster noch aus.** Unter *Erweiterungen → Meine Erweiterungen → Nova Suche → Konfigurieren* oben den Verkaufskanal wählen und „Sofortsuche verwenden“ einschalten – so lässt sich die Suche zuerst in einem Shop testen.
- Für die stündliche Auswertung müssen Shopwares **Scheduled Tasks und die Message Queue** laufen (Standard bei Shopware: `bin/console scheduled-task:run` und `bin/console messenger:consume` als Dienst/Cronjob oder der Admin-Worker). Alternativ `bin/console nova-search:rewrite` per Cronjob.

## Einstellungen

**Erweiterungen → Meine Erweiterungen → Nova Suche → Konfigurieren** (pro Verkaufskanal):

| Einstellung | Standard | Bedeutung |
|---|---|---|
| Sofortsuche verwenden | **aus** | Nach der Installation pro Verkaufskanal gezielt einschalten (oben den Verkaufskanal wählen). Aus = Shopwares Standard-Suchvorschläge |
| Produkte pro Ladeschritt | 24 | Beim Scrollen wird automatisch nachgeladen |
| Passende Kategorien anzeigen | an | |
| Filter „Marken“ anzeigen | an | |
| Wartezeit nach Tastendruck | 180 ms | Kleiner = schnellere Reaktion, größer = weniger Serverlast |
| Beliebte Suchanfragen | – | Eine pro Zeile, z. B. `chrom`, `scheinwerfer`, `rolldach` – stehen immer vorne |
| Beliebte Suchanfragen automatisch ermitteln | an | Häufigste Begriffe mit Treffern der letzten 90 Tage |
| Mindestanzahl Suchen für „beliebt“ | 5 | |
| Sperrliste | – | Begriffe mit diesen Wörtern werden nie als „beliebt“ angezeigt |
| Highlight-Produkte | – | Produkte für „Aktuelle Highlights“ |
| Nur Produkte mit Bild | an | Highlights und Empfehlungen ohne Treffer nur mit Produktbild |
| „Zuletzt angesehen“ anzeigen | an | |
| Nur mit Zustimmung (Komfortfunktionen) | an | |
| Aus Suchen, Klicks und Käufen lernen | an | |
| Mindest-Lernwert für bessere Platzierung | 3 | Klick = 1, Warenkorb = 3, Kauf = 5 Punkte |
| Suchbegriffe ohne Treffer mit Claude verbessern | aus | |
| Anthropic-API-Schlüssel | – | Aus der Anthropic Console |
| Modell | `claude-haiku-5-5` | |
| Monatliches Kostenlimit (USD) | 5 | Für alle Verkaufskanäle zusammen – unter „Alle Verkaufskanäle“ einstellen. 0 = Claude aus |
| Warnschwelle (% des Limits) | 80 | Einmaliger Hinweis im Log pro Monat |
| Neue Begriffe auf der Suchergebnisseite sofort auswerten | aus | |

Die Mindestlänge des Suchbegriffs kommt aus Shopwares Sucheinstellungen (*Einstellungen → Suche*).

## Tipps für bessere Treffer (ohne Plugin-Änderung)

- **Suchbegriffe pflegen:** Im Produkt unter *Suchbegriffe* Spitznamen und Varianten eintragen (z. B. „Ente“ beim 2CV)
- **Abverkaufsartikel ohne Bestand ausblenden:** *Einstellungen → Produkte → „Abverkaufsprodukte ohne Bestand ausblenden“* – gilt auch für die Suche
- **Suchgewichtung anpassen:** *Einstellungen → Suche* (z. B. Produktnummer und Name höher gewichten)

## Entwicklung

Innerhalb von Shopware: `bin/build-storefront.sh`. Ohne Shopware (nur das Storefront-JavaScript):

```bash
cd src/Resources/app/storefront
npm install
npm run build
```

Templates erweitern (`views/storefront/nova-search/`):

| Template | Inhalt | Blöcke |
|---|---|---|
| `start.html.twig` | Startansicht | `nova_search_start`, `nova_search_popular`, `nova_search_recent`, `nova_search_highlights` |
| `results.html.twig` | Trefferansicht | `nova_search_results`, `nova_search_empty`, `nova_search_filters`, `nova_search_main`, `nova_search_rewrite_hint` |
| `card.html.twig` | Produktkarte | `nova_search_card` |
| `items.html.twig` | Nachgeladene Karten | – |
| `layout/header/search.html.twig` | Rahmen des Fensters | `nova_search_layer_template` |
| `page/search/index.html.twig` | Hinweis auf der Suchergebnisseite | `page_search_headline_text` |

### Schnittstelle für andere Plugins (z. B. Tag Manager)

Events auf `document`:

| Event | `detail` | Wann |
|---|---|---|
| `nova:search-open` | – | Suchfenster geöffnet |
| `nova:search-close` | – | Suchfenster geschlossen |
| `nova:search` | `{ term, total }` | Ergebnis angezeigt – nach einer Tipp-Pause, je Begriff einmal pro geöffnetem Fenster (Filter/Sortierung lösen es nicht erneut aus) |
| `nova:products-rendered` | `{ container }` | Produktkarten eingefügt (Start-/Trefferansicht oder Nachladen) |

Markup:

- Jede Produktkarte trägt `data-product-information` (JSON `{ id, name, brand, price, sku }` – wie Shopwares Produktbox).
- Listen tragen `data-nova-list-id` / `data-nova-list-name`: `search_window`/„Suchfenster“, `search_highlights`/„Suche: Highlights“, `recently_viewed`/„Zuletzt angesehen“.
- `localStorage['nova-recently-viewed']`: JSON-Liste von Produkt-IDs, neueste zuerst, max. 20 (gleiches Format wie im Nova Theme).

Zusätzlich feuert das Storefront-Plugin-Event `novaSearch/render`.

Serverseitig: Eine gespeicherte Umschreibung hängt an der Criteria als Extension `novaSearchRewrite` (`original`, `rewrite`) – z. B. `page.listing.criteria.extension('novaSearchRewrite')`.

## Kompatibilität

Shopware 6.7.x (geprüft gegen 6.7.15).

## Roadmap

Geplante Funktionen (Synonyme, Weiterleitungen, Auswertungen in der Administration, Empfehlungen, Produktberater, Assistent …) stehen mit Stand in der [ROADMAP.md](ROADMAP.md).

## Häufige Probleme und Lösungen

### Suchfenster zeigt Fehler 500 bei bestimmten Begriffen (bis 1.10.7)

**Ursache:** 0 Treffer, aber passende Kategorien (z. B. bei gewähltem Fahrzeug) – der Text „… Ergebnisse gefunden“ hatte keine Pluralform für 0. Im Log: „Unable to choose a translation … for value "0"“

**Lösung:** Ab 1.10.8 behoben. Eigene Übersetzungen dieses Textes brauchen eine Form `{0} …`


Hier sammeln wir Hürden, die bei Installation und Betrieb tatsächlich aufgetreten sind. **Neue Probleme bitte ergänzen** (oder als Issue melden), damit andere nicht lange suchen müssen.

### Nummer wird nicht gefunden

**Ursache:** Der Nummern-Index ist noch nicht aufgebaut (direkt nach Installation/Update oder geänderten Einstellungen), oder die Nummer steht in einem Feld, das nicht ausgewählt ist (z. B. Beschreibung statt Zusatzfeld)

**Lösung:** Einmal `bin/console nova-search:numbers` ausführen (oder bis zum nächsten stündlichen Lauf warten). Steht die Nummer in einem Zusatzfeld oder einer Eigenschaft, diese unter *Nummernsuche* eintragen. Geplante Aufgaben müssen laufen (Cron)

### Nummernsuche zeigt zu viele Produkte

**Ursache:** „Nummern auch über den Anfang finden“ – kurze Nummernanfänge passen zu mehreren Produkten

**Lösung:** Einstellung abschalten; dann zählt nur die exakte Nummer

### „In den Warenkorb“ im Suchfenster fehlt bei manchen Produkten

**Ursache:** Gewollt bei Produkten mit Variantenauswahl, Staffelpreisen oder Abverkauf ohne Bestand – dort muss auf der Produktseite gewählt werden

**Lösung:** Keine; die Karte führt zur Produktseite. Ganz abschalten: *Suchfenster: Treffer → Button „In den Warenkorb“*

### Nach „In den Warenkorb“ wechselt die Seite statt den Warenkorb seitlich zu öffnen

**Ursache:** Shopwares Warenkorb-JavaScript wurde für die nachgeladenen Karten nicht gestartet (z. B. durch ein anderes Plugin oder Theme)

**Lösung:** Funktioniert trotzdem (Weiterleitung in den Warenkorb). Bitte als Issue melden, mit Theme-Name

### Eigenschaften-Filter erscheint nicht

**Ursache:** Die Eigenschaft ist in Shopware nicht als Filter freigegeben, oder keiner der Treffer hat sie

**Lösung:** *Kataloge → Eigenschaften → (Eigenschaft) → „In Produktfiltern anzeigen“* aktivieren und unter *Suchfenster: Treffer → Eigenschaften als Filter* auswählen

### „Zuletzt gesucht“ bleibt leer

**Ursache:** Es werden nur Suchen mit Treffern gemerkt, und nur mit Zustimmung im Cookie-Banner (Komfortfunktionen)

**Lösung:** Im Cookie-Banner zustimmen, etwas suchen und kurz warten (die Suche wird nach einer Tipp-Pause gemerkt)

### Menüpunkt „Nova Suche“ unter Erweiterungen fehlt

**Ursache:** Die Administration wurde nach dem Update noch nicht neu geladen, oder der Browser hält alte Dateien vor

**Lösung:** Cache löschen und die Administration mit Strg+F5 neu laden

### Auswertung zeigt nur Nullen

**Ursache:** Tageswerte werden erst seit Version 1.6.0 gezählt, und „Aus Suchen, Klicks und Käufen lernen“ muss aktiv sein

**Lösung:** Ein paar Tage abwarten; für ältere Gesamtzahlen den CSV-Export nutzen

### Synonym wirkt nicht

**Ursache:** Keiner der anderen Begriffe der Gruppe kommt in Produktnamen bzw. Suchwörtern vor – oder das Suchfenster zeigt noch ein zwischengespeichertes Ergebnis

**Lösung:** Mindestens ein Begriff der Gruppe muss so geschrieben sein wie im Shop (z. B. „bremsbeläge“, wenn die Produkte so heißen). Prüfen, ob die Gruppe auf „Aktiv“ steht und für den richtigen Verkaufskanal gilt. Bereits zwischengespeicherte Seiten: Knopf „Cache leeren“ über der Liste

### Weiterleitung greift nicht

**Ursache:** Der Begriff muss genau passen (nur Groß-/Kleinschreibung und Leerzeichen am Rand sind egal), oder das Ziel ist ungültig (z. B. ohne `/` am Anfang bzw. ohne `https://`)

**Lösung:** Eintrag unter *Nova Suche → Weiterleitungen* prüfen (Aktiv, Verkaufskanal, Ziel z. B. `/account`). Beim Tippen im Suchfenster erscheint der Link erst bei genau diesem Begriff

### Teileanfrage kommt nicht an

**Ursache:** Mailversand des Shops nicht eingerichtet, Empfänger fehlt oder die Mail landet im Spam

**Lösung:** *Einstellungen → Mailer* prüfen (eine Testbestellung o. Ä. muss ankommen), *Empfänger für Anfragen* oder die Shop-E-Mail unter *Stammdaten* setzen. Fehler stehen im Shopware-Log („Nova Suche: Teileanfrage …“)

### Schwestershop zeigt keine Treffer

**Ursache:** Der andere Verkaufskanal hat keine Domain mit http(s)-Adresse, ist inaktiv, oder das Produkt ist dort nicht sichtbar – oder dieser Shop hat selbst Treffer (dann wird nicht weitergesucht)

**Lösung:** Unter *Verkaufskanäle* Domain und Status des anderen Shops prüfen; die Einstellung „Schwestershops“ im richtigen Verkaufskanal setzen (nicht nur unter „Alle Verkaufskanäle“, wenn Shops gegenseitig verweisen sollen)

### Kunden finden Produkte nicht über den hinteren Teil der Artikelnummer

**Ursache:** Artikelnummern mit Gruppen-Vorsatz (`281-281569`) waren bis 1.10.0 nur über die ganze Nummer oder ihren Anfang auffindbar; Kunden tippen aber oft nur `281569` oder `28 15 69`

**Lösung:** Ab 1.10.1 wird der Teil nach dem Vorsatz mit in den Nummern-Index aufgenommen. Der Index baut sich nach dem Update beim nächsten stündlichen Lauf neu auf – sofort mit `bin/console nova-search:numbers`

### Ausgeschlossenes Produkt erscheint trotzdem

**Ursache:** Zwischengespeicherte Ergebnisse oder Ausschluss nur in einem anderen Verkaufskanal eingestellt

**Lösung:** Oben in der Konfiguration den richtigen Verkaufskanal (oder „Alle Verkaufskanäle“) wählen, danach Cache löschen. Varianten werden ausgeschlossen, wenn das Hauptprodukt eingetragen ist

### Nach der Installation ändert sich nichts

**Ursache:** Das Suchfenster ist **standardmäßig aus**  
**Lösung:** *Erweiterungen → Nova Suche → Konfigurieren* → oben den Verkaufskanal wählen → „Sofortsuche verwenden“ einschalten

### Suchfenster ohne Gestaltung

**Ursache:** Styles noch nicht im Theme  
**Lösung:** `bin/console theme:compile` und `bin/console cache:clear`

### Zwei Suchfenster gehen auf

**Ursache:** Eine andere Such-Erweiterung mit eigenem Overlay ist im selben Verkaufskanal aktiv  
**Lösung:** Die andere Such-Erweiterung in diesem Verkaufskanal deaktivieren

### Installation bricht bei Composer ab / „Class Anthropic\Client not found“ im Log

**Ursache:** Shopware lädt das Anthropic-SDK bei der Installation per Composer nach; auf manchen Hostern fehlt Composer oder Schreibrecht  
**Lösung:** Im Shop-Hauptverzeichnis `composer require anthropic-ai/sdk` ausführen. Ohne SDK funktioniert alles außer der Claude-Stufe (im Log steht dann ein klarer Hinweis)

### Claude verbessert nichts

**Ursache:** Claude ist aus, kein API-Schlüssel, oder **Scheduled Tasks / Message Queue laufen nicht**  
**Lösung:** Schlüssel aus der Anthropic Console eintragen, Claude einschalten; prüfen, dass `bin/console scheduled-task:run` und `bin/console messenger:consume` laufen (Cron/Supervisor). Sofort testen: `bin/console nova-search:rewrite`

### „Ergebnisse für …“ erscheint erst später

**Ursache:** Begriffe ohne Treffer werden gesammelt und **stündlich** gebündelt ausgewertet  
**Lösung:** Gewollt (spart Kosten). Sofort: `bin/console nova-search:rewrite`. Optional „Neue Begriffe auf der Suchergebnisseite sofort auswerten“ einschalten

### Claude stoppt mitten im Monat

**Ursache:** **Monatliches Kostenlimit erreicht** (Hinweis im Shopware-Log „Monatliches Claude-Kostenlimit erreicht“)  
**Lösung:** Verbrauch prüfen mit `bin/console nova-search:claude-usage`; ggf. Limit unter „Alle Verkaufskanäle“ erhöhen. Offene Begriffe werden danach bzw. im nächsten Monat automatisch ausgewertet

### Limit pro Shop eingestellt, wirkt aber nicht wie erwartet

**Ursache:** Das Limit gilt für alle Verkaufskanäle zusammen und wird global gelesen  
**Lösung:** Wert unter „Alle Verkaufskanäle“ eintragen

### Kosten im Plugin weichen von der Anthropic-Rechnung ab

**Ursache:** Der Plugin-Zähler ist eine Schätzung anhand der gemeldeten Tokens und der hinterlegten Preise  
**Lösung:** Maßgeblich ist die Console; zusätzlich dort ein Workspace-Ausgabenlimit setzen

### Tippfehler werden nicht gefunden

**Ursache:** Shopwares MySQL-Suche kennt keine Tippfehler-Toleranz  
**Lösung:** Mit OpenSearch/Elasticsearch deutlich besser; sonst fängt die Claude-Stufe viele Fälle ab

### Beliebte Suchanfragen sind leer

**Ursache:** Automatische Liste braucht erst echte Suchen (Mindestanzahl, an drei verschiedenen Tagen, letzte 90 Tage) und die stündliche Trefferprüfung  
**Lösung:** Anfangs Begriffe in der Konfiguration von Hand eintragen

### Ranking ändert sich nicht

**Ursache:** Boost greift erst ab einem Schwellenwert (Klicks/Käufe), erst nach der stündlichen Prüfung und nur bei Sortierung „Relevanz“  
**Lösung:** Gewollt, damit Einzelklicks nichts verzerren

### „Zuletzt angesehen“ fehlt im Suchfenster

**Ursache:** Nur mit Zustimmung „Komfortfunktionen“ im Cookie-Dialog  
**Lösung:** Cookie-Einstellungen prüfen

### Export enthält keine Daten

**Ursache:** Daten werden erst gesammelt, seit das Suchfenster aktiv ist  
**Lösung:** Einige Tage nach dem Einschalten erneut exportieren

### Die App sucht nicht mit der Nova Suche

**Ursache:** Store-API-Routen erst ab dieser Version; die App fällt bei 404 auf die Shopware-Suche zurück  
**Lösung:** Plugin aktualisieren, App neu starten

## Lizenz

MIT
