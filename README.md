# Printful Sync für VirtueMart

Dieses Repository enthält ein Joomla-5-Systemplugin, das Produkte eines Printful-Shops nach VirtueMart 4 synchronisiert. Der Sync wird über das Event `onPrintfulSyncProduct` ausgelöst und kann z. B. von einem Cronjob oder einer externen Integration aufgerufen werden.【F:plugins/system/printfulsync/printfulsync.php†L30-L59】

## Funktionsumfang

* Das Plugin stellt sicher, dass die erforderliche Custom-Field-Infrastruktur (Gruppe sowie Listenfelder für Farbe und Größe) idempotent angelegt und mit den Printful-Werten befüllt wird.【F:plugins/system/printfulsync/src/Service/PrintfulSyncService.php†L51-L72】【F:plugins/system/printfulsync/src/Service/CustomFieldManager.php†L39-L130】
* Für das Elternprodukt wird das Generic-Child-Variant-Plugin aktiviert, damit im Frontend kombinierbare Dropdowns erscheinen.【F:plugins/system/printfulsync/src/Service/PrintfulSyncService.php†L74-L89】【F:plugins/system/printfulsync/src/Service/ProductManager.php†L63-L121】
* Jede Printful-Variante erzeugt oder aktualisiert ein Kindprodukt mit konsistenter SKU, Lagerbestand, Veröffentlichungsstatus sowie den zugehörigen Custom-Field-Werten für Farbe und Größe.【F:plugins/system/printfulsync/src/Service/PrintfulSyncService.php†L91-L109】【F:plugins/system/printfulsync/src/Service/ProductManager.php†L123-L212】
* Nicht mehr verfügbare Varianten werden wahlweise depubliziert oder gelöscht; sämtliche Maßnahmen werden über das Joomla-Log sowie Backend-Nachrichten protokolliert.【F:plugins/system/printfulsync/src/Service/PrintfulSyncService.php†L111-L114】【F:plugins/system/printfulsync/src/Service/ProductManager.php†L214-L275】【F:plugins/system/printfulsync/src/Service/CustomFieldManager.php†L132-L207】

## Installation

1. Archivieren Sie das Verzeichnis `plugins/system/printfulsync` zu einer installierbaren ZIP-Datei oder kopieren Sie den Ordner direkt in Ihre Joomla-Instanz.
2. Installieren bzw. entdecken Sie das Plugin über den Joomla-Erweiterungsmanager und aktivieren Sie es anschließend im Plugin-Manager.
3. Stellen Sie sicher, dass VirtueMart 4 installiert und konfiguriert ist, bevor Sie den ersten Sync ausführen.

## Konfiguration

Im Plugin-Backend stehen folgende Einstellungen zur Verfügung：【F:plugins/system/printfulsync/printfulsync.xml†L17-L86】

| Option | Beschreibung |
| --- | --- |
| API Token & Store ID | Zugangsdaten für die Authentifizierung gegenüber der Printful-API. |
| Titel der Custom-Field-Gruppe sowie der Felder Farbe/Größe | Überschriften, die beim Anlegen oder Aktualisieren der VirtueMart-Custom-Fields verwendet werden. |
| Variantenbereinigung | Schalter, um fehlende Varianten automatisch zu löschen oder nur zu depublizieren. |
| AJAX auf Kategorieseiten | Aktiviert den AJAX-Ladevorgang des Generic-Child-Variant-Plugins in Listenansichten. |
| Deaktivieren veralteter Listenwerte | Legt fest, ob nicht mehr verwendete Feldoptionen automatisch unpubliziert werden. |
| Wert-Mappings (JSON) | Optionales Mapping für Farb- und Größenbezeichnungen, um Printful-Werte auf gewünschte Labels abzubilden. |

## Synchronisationsablauf

1. **Payload-Prüfung:** Das Plugin validiert, dass die empfangene Printful-Nachricht eine Produkt-ID, einen Namen sowie mindestens eine Variante enthält.【F:plugins/system/printfulsync/src/Service/PrintfulSyncService.php†L116-L132】
2. **Custom-Fields anlegen/aktualisieren:** Die Listenwerte werden aus den Varianten aggregiert, fehlende Optionen ergänzt und auf Wunsch veraltete Werte deaktiviert.【F:plugins/system/printfulsync/src/Service/PrintfulSyncService.php†L51-L72】【F:plugins/system/printfulsync/src/Service/CustomFieldManager.php†L39-L130】
3. **Elternprodukt synchronisieren:** Über die SKU (external_id oder Printful-ID) wird das Hauptprodukt erstellt oder aktualisiert und mit dem Generic-Child-Variant-Plugin verknüpft.【F:plugins/system/printfulsync/src/Service/ProductManager.php†L39-L162】
4. **Kindprodukte pflegen:** Für jede Farb-Größen-Kombination wird ein Kindprodukt gespeichert und mit den passenden Custom-Field-Werten versehen.【F:plugins/system/printfulsync/src/Service/PrintfulSyncService.php†L91-L109】【F:plugins/system/printfulsync/src/Service/ProductManager.php†L123-L212】
5. **Aufräumen:** Varianten, die nicht mehr im Printful-Payload enthalten sind, werden je nach Einstellung depubliziert oder gelöscht.【F:plugins/system/printfulsync/src/Service/PrintfulSyncService.php†L111-L114】【F:plugins/system/printfulsync/src/Service/ProductManager.php†L214-L258】

## Integration in eigene Abläufe

* Rufen Sie das Event `onPrintfulSyncProduct` mit dem Printful-Produktpayload (Produkt inklusive Varianten) auf, um eine Synchronisation zu starten.【F:plugins/system/printfulsync/printfulsync.php†L30-L59】
* Laden Sie vor dem Aufruf die VirtueMart-Konfiguration (`VmConfig::loadConfig()`), wenn Sie den Sync außerhalb von Joomla- oder VirtueMart-Kontexten ausführen.【F:plugins/system/printfulsync/printfulsync.php†L46-L58】
* Prüfen Sie nach dem Lauf das Joomla-Log (`plg_system_printfulsync`) oder die Backend-Nachrichten für Details zu erstellten, aktualisierten oder entfernten Datensätzen.【F:plugins/system/printfulsync/src/Service/ProductManager.php†L260-L275】【F:plugins/system/printfulsync/src/Service/CustomFieldManager.php†L188-L207】

## Support & Erweiterung

Die Klassen `CustomFieldManager`, `ProductManager` und `PrintfulSyncService` sind voneinander getrennt testbar und können bei Bedarf um zusätzliche Anforderungen (z. B. Kategoriezuweisung, Medienimport oder Preislogik) erweitert werden.【F:plugins/system/printfulsync/src/Service/PrintfulSyncService.php†L29-L104】【F:plugins/system/printfulsync/src/Service/CustomFieldManager.php†L24-L207】【F:plugins/system/printfulsync/src/Service/ProductManager.php†L25-L275】 Eigene Anpassungen sollten weiterhin über die VirtueMart-Modelle erfolgen, um Kompatibilität und Updatesicherheit zu gewährleisten.
