# 1.12.0
- Sicherheit: **Tippfehler lernen abgesichert** – eine Korrektur zählt je Besucher (Sitzung und IP) und Tag nur einmal und wird erst übernommen, wenn sie an mindestens zwei verschiedenen Tagen beobachtet wurde und die stündliche Prüfung zeigt, dass der Begriff selbst nichts findet, die Korrektur aber Treffer hat. Bisher reichte ein einziger manipulierter Aufruf
- Bereits gelernte Korrekturen werden nach dem Update einmal neu geprüft (bis dahin inaktiv) und bei Nichtbestehen deaktiviert; gelernte Korrekturen laufen jetzt auch durch die 30-Tage-Prüfung
- Neu in der Auswertung („Ohne Treffer“): Liste aller gespeicherten Umschreibungen (Claude und gelernt) mit Knopf „Deaktivieren“
- Sicherheit: Lernsignale aus dem Suchfenster nur noch mit bestehender Shop-Sitzung, von derselben Website und ohne Bots; zusätzlich Grenzen je Client-IP und je Verkaufskanal (Symfony RateLimiter), auch für die Store-API
- Sicherheit: „Beliebte Suchanfragen“ nutzen eine serverseitig geprüfte Trefferzahl statt der Angabe aus dem Browser und verlangen Suchen an mindestens drei verschiedenen Tagen
- Sicherheit: Gelerntes Ranking und „Beliebt in der Suche“ berücksichtigen nur Produkte, die die Suche bei dem Begriff auch ohne Lernen findet; Klicks und Warenkorb zählen je IP, Begriff und Produkt einmal am Tag
- Claude-Warteschlange: neue Begriffe je IP begrenzt, Bots ausgeschlossen, häufig gesuchte Begriffe zuerst; Sofort-Auswertung auf der Suchergebnisseite höchstens dreimal je IP und Stunde
- Schwestershop-Suche: „nichts gefunden“ wird 10 Minuten gemerkt, Aufrufe je IP begrenzt
- Administration: API-Routen verlangen die Berechtigung „Einstellungen lesen“ bzw. „Einstellungen bearbeiten“
- Aufräumen: beobachtete Korrekturen und nie gelöste Begriffe nach 180 Tagen löschen; Deinstallation entfernt auch die Korrektur-Tabelle
- README: Datenschutz-Hinweise präzisiert (Suchbegriffe sind freie Eingaben, IP nur flüchtig im Cache)

# 1.11.0
- Neu: **Tippfehler lernen** – sucht ein Besucher „vergser“, klickt nichts und findet gleich danach mit „vergaser“ ein Produkt, merkt sich die Suche die Korrektur. Ab 2 Beobachtungen (einstellbar) wird „vergser“ automatisch als „vergaser“ gesucht. Nur bei kleinen Abweichungen in der Schreibweise mit denselben Zahlen
- Auswertung: Zwischenstände beim Tippen („ra“, „rah“, „rahmen“) zählen nicht mehr als eigene Suchen – nur der fertige Begriff

# 1.10.8
- Fehler behoben: Das Suchfenster brach mit Fehler 500 ab, wenn es 0 Treffer gab, aber passende Kategorien (z. B. „bremse“ bei einem Fahrzeug ohne Bremsenteile) – der Text „… Ergebnisse gefunden“ hatte keine Form für 0

# 1.10.7
- Neues Plugin-Icon im Nova-Stil (Erweiterungen → Meine Erweiterungen)
- Repository umbenannt in SW6-Nova-Suche – alte Adresse leitet weiter

# 1.10.6
- Gibt es für den Begriff eine Weiterleitung und keine Treffer, zeigt das Suchfenster nur den Link – ohne „Keine Treffer“, „Teil anfragen“, Empfehlungen und Schwestershop; der Begriff wird nicht für Claude vorgemerkt

# 1.10.5
- Suchen werden ehrlicher gezählt: Bots, Prefetch und automatische Abrufe zählen nicht mehr, derselbe Begriff zählt pro Sitzung höchstens einmal in 30 Minuten (auch Suchfenster + Enter nur einmal)
- Links in Schwestershops (Produktkarten, „Alle Treffer ansehen“, Hinweise auf andere Shops) öffnen sich in einem neuen Tab

# 1.10.4
- Hinweis zur Umschreibung steht direkt über den eigenen Treffern (unter den Schwestershop-Treffern), mit passendem Text: „Hier im Shop gibt es nichts zu … – ähnliche Treffer für …“

# 1.10.3
- Schwestershops werden auch gefragt, wenn die Treffer nur über eine Umschreibung kamen (z. B. „lichtmaschine cx“ → „lichtmaschine“); ihre Treffer stehen dann über den umgeschriebenen

# 1.10.2
- Startansicht ohne Highlights: beliebte Suchanfragen in bis zu 4 Spalten nebeneinander statt einer langen Liste – das Fenster wird deutlich niedriger

# 1.10.1
- Nummernsuche: Nummern mit Gruppen-Vorsatz (z. B. „281-281569“) sind auch über den hinteren Teil auffindbar („281569“, „28 15 69“); der Index baut sich nach dem Update automatisch neu auf

# 1.10.0
- „Teil anfragen“ direkt im Suchfenster: E-Mail oder Telefon genügt, Anfrage per Mail an den Shop, der Kunde bleibt in der Suche; Kontaktformular weiter als Link
- Neue Einstellungen: „Teil anfragen“ im Suchfenster, Empfänger für Anfragen
- Shopware-Modals (z. B. Datenschutzhinweis) erscheinen über dem Suchfenster

# 1.9.0
- Neu: Schwestershops – bei 0 Treffern Treffer aus anderen Verkaufskanälen derselben Installation, mit Link in den anderen Shop
- Neu: Hinweise – Wort im Suchbegriff (z. B. „t2“) → Hinweis mit Link, z. B. auf die Suche im anderen Shop; neuer Reiter unter Erweiterungen → Nova Suche
- Neu: Knopf „Teil anfragen“ bei 0 Treffern – Kontaktformular mit Betreff und Suchbegriff vorausgefüllt

# 1.8.0
- Neu: Suchbegriffe und Klicks aus CSV-Exporten eines bisherigen Suchdienstes übernehmen – Knopf „Daten importieren“ in der Auswertung bzw. bin/console nova-search:import-stats; Produkte über die Produktnummer im Link, jede Datei nur einmal

# 1.7.2
- Bestseller-Highlights zählen Bestellungen statt Stückzahl – Kleinteile in großer Menge (z. B. 100 Schrauben) drängen sich nicht mehr vor

# 1.7.1
- Neu: Schalter „Nur Produkte mit Bild“ für Aktuelle Highlights und Empfehlungen ohne Treffer (Standard: an)

# 1.7.0
- Neu: Synonyme und Weiterleitungen als eigene Listen unter „Erweiterungen → Nova Suche“ (Reiter neben der Auswertung) – Suche, Aktiv-Schalter, Bearbeiten, Löschen, Verkaufskanal je Eintrag
- Neu: CSV-Import (auch Exporte anderer Suchdienste) und CSV-Export
- Neu: „Ohne Treffer“ in der Auswertung mit Knöpfen „Synonym“ und „Weiterleitung“ zum direkten Beheben
- Bisherige Einträge aus den Textfeldern der Konfiguration werden beim Update übernommen; die Textfelder entfallen

# 1.6.0
- Neu: Menüpunkt „Erweiterungen → Nova Suche“ mit Auswertung – Kennzahlen mit Vergleich zum Vorzeitraum, Suchen pro Tag, Suchbegriffe, ohne Treffer (inkl. Claude-Umschreibung), geklickte Produkte, Claude-Kosten, CSV-Export und Knopf zu den Einstellungen
- Tageswerte je Suchbegriff und Produkt (nach 2 Jahren gelöscht)

# 1.5.0
- Neu: „Zuletzt gesucht“ als Chips in der Startansicht (einzeln entfernbar, „Alles löschen“; nur im Browser, mit Zustimmung)
- Neu: Aktuelle Highlights automatisch – Bestseller, beliebt in der Suche oder neu
- Neu: Eigenschaften als Filter im Suchfenster (z. B. Fahrzeugmodell)
- Neu: Produktkarten mit Bewertungssternen, „Derzeit nicht lieferbar“ und Button „In den Warenkorb“
- Neu: Bei 0 Treffern eigener Text je Shop und Produktempfehlungen
- Neu: Mindestanzahl Zeichen einstellbar
- „In den Warenkorb“ aus dem Suchfenster zählt fürs Lernen wie ein Klick

# 1.4.0
- Neu: Checkbox „Nur exakte Teilenummer“ im Suchfenster – zeigt ausschließlich Produkte mit genau dieser Nummer, sonst „Keine Teilenummer … gefunden“; bleibt für die Sitzung erhalten, gilt auch für Enter; Store-API-Parameter exactNumber
- Begriffe aus der Nummernsuche werden nicht mehr an Claude zur Umschreibung gegeben

# 1.3.0
- Neu: Nummernsuche – Artikel-, Hersteller-, EAN- und OE-Nummern (Zusatzfelder, Eigenschaften) unabhängig von Leerzeichen, Punkten, Bindestrichen und Schrägstrichen; auch über den Nummernanfang
- Nummern-Index wird beim Speichern von Produkten aktualisiert, komplett im stündlichen Lauf bzw. mit bin/console nova-search:numbers

# 1.2.0
- Neu: Synonyme (eine Gruppe pro Zeile) – gelten in Suchfenster, Suchergebnisseite und App; Mehrwortsuchen bleiben genau
- Neu: Weiterleitungen für Suchbegriffe (z. B. „kundenkonto“ → Konto) – Enter führt direkt zum Ziel, im Suchfenster erscheint ein Link, die Store-API liefert das Feld `redirect`
- Neu: Ausgeschlossene Produkte – Abverkauf ohne Bestand, Produkte, Kategorien, Tags oder Ja/Nein-Zusatzfeld
- README: Umstieg von einem externen Suchdienst

# 1.1.0
- Monatliches Kostenlimit für die Claude-Stufe (Standard 5 USD, 0 = aus) mit Warnschwelle (Standard 80 %)
- Verbrauch wird je Monat und Modell aus den gemeldeten Tokens berechnet und gespeichert
- Vor jedem Aufruf wird geprüft, ob die Höchstkosten noch ins Restbudget passen; bei erreichtem Limit bleiben Begriffe offen
- Neuer Befehl bin/console nova-search:claude-usage, Export-Typ claude-usage

# 1.0.0
- Erste Version: Suchfenster mit Bildern, Filtern (Marken, Preis), Sortierung und automatischem Nachladen
- Lernt aus Suchen, Klicks, Warenkorb und Käufen (ohne personenbezogene Daten)
- Beliebte Suchanfragen automatisch, „Zuletzt angesehen“ in der Startansicht
- Claude (Haiku 5.5) verbessert Suchbegriffe ohne Treffer dauerhaft – jede Anfrage nur einmal
- Store-API für Apps (Suche, beliebte Suchanfragen, Tracking)
- Export der Suchdaten als CSV: bin/console nova-search:export
- Das Suchfenster ist nach der Installation aus und wird pro Verkaufskanal eingeschaltet
