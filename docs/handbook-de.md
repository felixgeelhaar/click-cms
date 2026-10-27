# Click CMS Handbuch — Deutsch

[English](handbook-en.md) · [Praktische Demos](demos-de.md)

**Sie möchten Ihre Website bearbeiten?** Beginnen Sie mit der [einfachen Redaktionsanleitung](editor-de.md). Sie erklärt jede Aufgabe Schritt für Schritt. Die technischen Kapitel unten richten sich an die Personen, die Ihre Website betreiben oder entwickeln.

Für Redakteure, Website-Verantwortliche und Entwickler. Dieses Handbuch beschreibt Click 1.12. Verfügbare Funktionen hängen von Rolle, Plugins und den Abschnitts- und Sammlungsdefinitionen der Website ab. Die Oberfläche ist auf Deutsch und Englisch verfügbar. Die Sprache der Oberfläche ist unabhängig von der Sprache der Seiteninhalte.

## Den passenden Einstieg wählen

| Aufgabe | Einstieg |
|---|---|
| Texte oder Bilder ändern | Inhalte bearbeiten und Demo 1 |
| Beiträge freigeben | Speichern und veröffentlichen und Demo 2 |
| Eine Website betreiben | Administration und Betrieb |
| Eine Website oder SPA entwickeln | Entwickler und Demo 4 |
| Click ohne Auswirkungen auf Besucher ausprobieren | [Lokale Demo](demos-de.md) |

## Anmelden und Berechtigungen verstehen

Öffnen Sie die vom Administrator genannte Adresse, normalerweise mit `/admin/` hinter der CMS-Adresse. Bei einer Installation im Unterverzeichnis kann sie beispielsweise `/2026/cms2/admin/` lauten. Verwenden Sie Ihren Benutzernamen, der nicht unbedingt der E-Mail-Adresse entspricht. Führen Sie den erforderlichen Passwortwechsel und gegebenenfalls den zweiten Anmeldeschritt durch. Nutzen Sie einen Passwortmanager. Die dokumentierten Erstzugangsdaten gelten nicht für eine bestehende Website.

| Rolle | Typische Aufgaben |
|---|---|
| Administrator | Inhalte, Konten, Einstellungen, Erweiterungen und Updates |
| Editor | Inhalte bearbeiten und veröffentlichen; Löschen hängt vom Eigentümer ab |
| Author | Eigene Entwürfe erstellen und pflegen; Veröffentlichung beim Editor anfragen |
| Viewer | Inhalte ansehen, ohne sie zu verändern |

Fehlende Schaltflächen können an der Rolle liegen. Fragen Sie den Administrator. [Berechtigungsreferenz auf Englisch](roles.md) · [Zwei-Faktor-Authentifizierung auf Englisch](two-factor.md).

## Inhalte bearbeiten

Öffnen Sie **Pages**, suchen Sie Titel oder Adresse und wählen Sie **Edit**. Prüfen Sie zuerst den Veröffentlichungsstatus. Bearbeiten Sie die Inhaltsabschnitte und öffnen Sie den benötigten Abschnitt. Seltener benötigte Metadaten finden Sie in den Seiteneinstellungen. Welche Felder und Abschnittsdesigns vorhanden sind, hängt von Ihrer Website ab.

Eine Seite besteht aus geordneten Abschnitten. Füllen Sie deren Felder aus und verwenden Sie die Abschnittssteuerung zum Verschieben, Duplizieren oder Entfernen. Speichern Sie vor dem Verlassen. Das Entfernen eines Abschnitts ändert den Entwurf; das Löschen einer Seite entfernt das Dokument. Zum vorübergehenden Ausblenden verwenden Sie die Funktion zum Zurückziehen der Veröffentlichung.

Laden Sie Bilder über **Media** oder die Bildauswahl hoch. Beschreiben Sie aussagekräftige Bilder sinnvoll. Prüfen Sie den Ausschnitt auch auf kleinen Bildschirmen. Bewahren Sie Originaldateien separat auf. Das Löschen einer Mediendatei kann Seiten beschädigen, die sie verwenden. [Bildreferenz auf Englisch](pictures.md).

Wählen Sie die richtige Sprache aus. Prüfen Sie alle übersetzten Felder und die öffentliche Adresse dieser Sprache. Eine Änderung ersetzt nicht die Prüfung anderer Übersetzungen. Fehlende Übersetzungen können durch eine andere Sprache ersetzt werden.

## Speichern, Vorschau, Veröffentlichung und Wiederherstellung

**Save** speichert Ihre Arbeit. **Publish** stellt die gespeicherte Fassung öffentlich bereit. Bei einer bereits veröffentlichten Seite bleibt die bisherige öffentliche Fassung sichtbar, bis Sie erneut veröffentlichen. Lesen Sie den Status nach beiden Aktionen; eine erfolgreiche Speicherung bedeutet nicht, dass die öffentliche Website aktualisiert wurde.

Prüfen Sie den Entwurf in der Vorschau: Überschriften, Links, Bilder, Sprache und schmale Bildschirme. Behandeln Sie geteilte Vorschaulinks vertraulich, da sie Zugang zu unveröffentlichten Inhalten geben. Autoren reichen ihre Vorschau zur Freigabe weiter.

Über den Verlauf können Sie ältere Fassungen prüfen und wiederherstellen. Prüfen Sie den wiederhergestellten Entwurf und veröffentlichen Sie ihn bei Bedarf ausdrücklich. Meldet der Editor Änderungen einer anderen Person, sichern Sie Ihren Text separat, laden Sie die aktuelle Fassung und führen Sie die Änderungen zusammen. Überschreiben Sie einen Konflikt nicht wiederholt.

Ziehen Sie die Veröffentlichung zurück, um eine Seite öffentlich auszublenden und dennoch weiter bearbeiten zu können. Kontrollieren Sie anschließend die tatsächliche Website. Eine separate SPA oder statische Website muss Inhalte gegebenenfalls neu abrufen oder neu gebaut werden. [Veröffentlichungsreferenz auf Englisch](publishing.md) · [Bearbeitungsreferenz auf Englisch](editing.md).

## Sammlungen, Navigation und weitere Bereiche

**Collections** enthalten strukturierte Einträge wie Nachrichten oder Teamprofile. Wählen Sie die Sammlung, erstellen oder bearbeiten Sie einen Eintrag, füllen Sie Pflichtfelder aus, speichern und veröffentlichen Sie. Felder und Darstellung legt die Website-Entwicklung fest. [Sammlungsreferenz auf Englisch](collections.md).

Administratoren pflegen über **Menus** die Navigation und ihre Reihenfolge. Eine neue Seite erscheint nicht automatisch im Menü. Nutzen Sie **Redirects**, wenn eine alte Adresse ersetzt wird, und testen Sie die alte URL. Vermeiden Sie Weiterleitungsschleifen.

**Submissions** enthält Formularantworten, sofern entsprechende Formulare eingerichtet sind. Begrenzen Sie den Zugriff, speichern Sie nur notwendige personenbezogene Daten und legen Sie Löschfristen fest. **Builder**, **Themes**, **Plugins** und **Marketplace** verändern Gestaltung oder Funktionen. Testen Sie Änderungen zuerst auf einer Kopie. Der [Rundgang auf Englisch](admin-tour.md) erläutert die Bereiche.

Formularantworten bleiben unter **Submissions** gespeichert. Optionale E-Mail-Hinweise enthalten nur eine Referenz. Im Posteingang sehen Sie, ob der Mailserver den Hinweis angenommen hat oder der Versand fehlgeschlagen ist. Administratoren können Hinweise unter **Einstellungen → E-Mail-Benachrichtigungen für Formulare** einrichten und eine Test-E-Mail senden. Dateien müssen dafür nicht bearbeitet werden. [Einrichtung und Grenzen auf Englisch](forms.md).

## Plugins hinzufügen oder verwalten

Diese Aufgabe ist für Administratoren. Für normale Inhaltsänderungen müssen Sie keine Plugins ändern.

1. Sichern Sie die Website und probieren Sie Änderungen zuerst auf einer Testkopie aus.
2. Unter **Marketplace** können Sie Plugins aus dem eingerichteten Verzeichnis installieren oder eine Plugin-ZIP-Datei aus vertrauenswürdiger Quelle hochladen. Ohne eingerichtetes Verzeichnis gibt es keinen Katalog; bereits installierte Plugins sind deshalb nicht defekt.
3. Öffnen Sie **Plugins**. Mit **Aktivieren** schalten Sie ein installiertes Plugin ein, mit **Deaktivieren** aus. Lesen Sie angezeigte Fehler: Abhängigkeiten oder eine Bereitstellungsrichtlinie können die Änderung verhindern. Bei einem übersprungenen Plugin-Ordner muss der Betreiber dessen Dateien prüfen.
4. Laden Sie die Administration neu und testen Sie die Funktion auf der tatsächlichen Website oder über die API. Die Installation allein legt keine Inhalte an und richtet eine getrennte SPA nicht automatisch für die Darstellung ein.

Externe Plugins werden auf denselben Wegen installiert. Bei Downloads aus dem Verzeichnis werden signierte Angaben und Paket-Prüfsummen kontrolliert; ZIP-Uploads sind nicht verifiziert. Eine Plugin-ZIP muss `plugin.json` und `bootstrap.php` enthalten. Die Prüfung bestätigt den Herausgeber, aber nicht die Kompatibilität. Prüfen Sie, ob das Plugin Ihre Click-Version unterstützt. Bei durch die Bereitstellung verwalteten Installationen ist die Installation im Marketplace gesperrt; bitten Sie den Betreiber, das Plugin in die Bereitstellung aufzunehmen. REST und Speicherfunktionen sind bereits in Click enthalten und benötigen keine alten Plugins.

Installieren Sie nur vertrauenswürdige Plugins: Ihr PHP-Code läuft mit den Zugriffsrechten des CMS. Deaktivieren entfernt keine Dateien. Sichern Sie Plugin-Daten und prüfen Sie abhängige Funktionen vor Änderungen an der öffentlichen Website.

## Administration und Sicherheit

Legen Sie unter **Users** persönliche Konten mit der jeweils erforderlichen Rolle an. Entfernen Sie Zugänge ausgeschiedener Personen. **Profile** enthält persönliche Kontoeinstellungen. Aktivieren Sie verfügbare Zwei-Faktor-Authentifizierung und bewahren Sie Wiederherstellungsinformationen vertraulich auf. Passwörter werden als Hash gespeichert und können nicht ausgelesen werden. Bei Verlust ist ein kontrolliertes Zurücksetzen durch den Betreiber erforderlich.

Installieren Sie nur vertrauenswürdige Erweiterungen. Halten Sie PHP und Click aktuell. Verwenden Sie HTTPS. Nur `public/` darf öffentlich erreichbar sein; Konfiguration, Inhalte, Kontodateien, Sitzungen und Sicherungen gehören außerhalb des öffentlichen Zugriffs. Administratorpasswörter, Verwaltungszugänge und Vorschaugeheimnisse gehören weder in Browsercode noch in öffentliche Repositories.

## Installation, Sicherung und automatische Updates

Für eine Neuinstallation verwenden Sie das Archiv mit `-install.zip`; das andere Archiv dient Aktualisierungen. Diese Version benötigt mindestens PHP 8.1. Veröffentlichen Sie das öffentliche Verzeichnis und geben Sie PHP die notwendigen Schreibrechte für Inhalte und Daten. Die [Installationsreferenz auf Englisch](install.md) beschreibt Host-Prüfung, Speicherwahl und getrennte Verzeichnisse.

Sichern Sie `content/`, `data/`, `config/` und eigene Themes/Plugins. Datenbankspeicher benötigt zusätzlich eine Datenbanksicherung. Bewahren Sie Kopien außerhalb des öffentlichen Webverzeichnisses auf und testen Sie die Wiederherstellung isoliert. Eine Update-Sicherung ersetzt keine unabhängige Notfallvorsorge. [Sicherungsreferenz auf Englisch](backup.md).

Prüfen Sie unter **Updates** Version, angebotene Aktualisierung, Richtlinie und Verlauf. Verfügbar sind `manual`, `notify`, `security`, `minor` und `all`. `minor` erlaubt unbeaufsichtigte stabile Patch- und Minor-Updates; Hauptversionen benötigen bei dieser Richtlinie eine Freigabe. Vorabversionen werden nie automatisch installiert. Die Voreinstellung `security` installiert als Sicherheitsupdates gekennzeichnete Releases automatisch.

Fällige GET-/HEAD-Anfragen an PHP lösen normalerweise einmal täglich eine Prüfung aus, auch über die öffentliche API. Ohne solche Anfragen kann Click nicht prüfen; vollständig zwischengespeicherter Verkehr reicht nicht. Verwaltete Deployments deaktivieren diesen Mechanismus. Erforderlich sind verifizierte signierte Feeds, Paketprüfung, ausgehender Netzwerkzugriff sowie beschreibbare Installations- und Sicherungsverzeichnisse. Bei getrennten öffentlichen und internen Verzeichnissen muss `publicRoot` den tatsächlichen absoluten PHP-Dateisystempfad enthalten. Der SFTP-Pfad kann davon abweichen. [Update-Konfiguration und Wiederherstellung auf Englisch](updates.md).

## Entwickler: klassische Website und SPA

Click kann ein Theme rendern oder veröffentlichte Inhalte über die Delivery-API liefern. Definieren Sie Abschnitte in `config/sections/` und Sammlungen in `config/collections/`. Trennen Sie Inhaltsstruktur und Darstellung und testen Sie Schemaänderungen mit vorhandenen Dokumenten. [Architektur auf Englisch](core.md) · [Visual Builder auf Englisch](visual-builder.md).

Die CMS-Basisadresse einer SPA muss gegebenenfalls das Unterverzeichnis enthalten. Lesen Sie `/api/pages` oder `/api/pages/{slug}` aus. Behandeln Sie fehlende Inhalte, Sprachersatz, Netzwerkfehler und Wartungsantworten. Fehlende oder unveröffentlichte Seiten benötigen eine eigene Nicht-gefunden-Darstellung. Implementieren Sie jeden Abschnittstyp bewusst und fügen Sie kein unvertrauenswürdiges HTML direkt ein. Erlauben Sie über Delivery-CORS nur tatsächlich benötigte Ursprünge.

Der [TypeScript-Client auf Englisch](../sdk/typescript/README.md) dokumentiert Antwortstruktur und generierte Abschnittstypen. Generieren Sie Typen nach Schemaänderungen erneut und kompilieren Sie das Frontend dagegen. Halten Sie authentifizierte Bearbeitung und öffentliche Auslieferung getrennt. Besucher benötigen keine Administratorsitzung. [Demo 4](demos-de.md#demo-4-veröffentlichte-inhalte-für-eine-spa-lesen) zeigt eine minimale Leseanfrage.

Eine Veröffentlichung baut statisches HTML nicht automatisch neu. Konfigurieren Sie einen Empfänger für signierte [Webhooks auf Englisch](webhooks.md) bei Veröffentlichung und Zurückziehen. Prüfen Sie Signaturen und schützen Sie Deployment-Zugangsdaten. Testen Sie den gesamten Weg vom Editor bis zur öffentlichen Website.

## Hilfe und Abnahme

| Problem | Prüfen |
|---|---|
| Anmeldung scheitert | Admin-Adresse, Benutzername, Passwort, zweiter Faktor; Betreiber kontaktieren |
| Veröffentlichung fehlt | Rolle und Berechtigungen |
| Änderung nicht sichtbar | Veröffentlichungsstatus, Sprache, Frontend-Cache oder Neubau |
| Upload scheitert | Dateibeschränkungen, PHP-Limits und Schreibrechte |
| Admin bleibt leer | Gebaute Release-Dateien und öffentliche/interne Pfade |
| Update scheitert | Update-Verlauf, PHP-Protokolle, Feed-Zugriff, Speicherplatz und Rechte |
| Kurzzeitiger 503 beim Update | Nach dem Wartungsintervall erneut versuchen; dauerhafte Fehler untersuchen |

Führen Sie vor der Übergabe alle vier Demos auf einer isolierten Kopie durch. Testen Sie eine Wiederherstellung und den Schutz privater Dateien. Dokumentieren Sie Betreiber, Sicherungsort und Update-Richtlinie. Verwenden Sie in öffentlichen Demos keine Produktionszugänge oder echten Formulardaten.
