# Printful Sync für VirtueMart

Zur korrekten Funktionsweise Stockable Custom Fields installieren. Befindet sich im gezippten file. -> Paket öffnen Stockable Custom Fields installieren, den Rest wieder zippen und installieren

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

  unpublishOrDeleteMissingChildren($parentId, $seenChildSkus);
}
Hilfsfunktionen (zu implementieren)
ensureCustomFieldGroup($title): int
ensureListCustomField($title, $groupId): int
setzt: list=true, cart_attribute=false, cart_input=false, searchable=false, layout_position=''
updateListOptions($customId, array $values): void
ensureParentProduct(PrintfulProduct $pf): int
ensureGenericChildVariantPluginOnParent($parentId, array $params): void
sucht/legt virtuemart_product_customfields-Eintrag an, verknüpft mit custom_element='genericchild'
ensureChildProduct($parentId, $sku, $v): int
Produktdaten (Preis, Lager, Bilder) setzen; parent relation; published
setChildCustomFieldValue($productId, $customId, $value): void
unpublishOrDeleteMissingChildren($parentId, array $keepSkus): void
Akzeptanzkriterien (Tests)
Nach einem Sync zeigt das Elternprodukt im Frontend zwei Dropdowns („Farbe“, „Größe“); Auswahl lädt jeweils ein Kindprodukt (Preis/Lager ändern sich entsprechend).
Erneuter Sync erzeugt keine Duplikate; neue Printful-Varianten erscheinen automatisch; entfernte werden (gemäß Option) deaktiviert.
In Benutzerdefinierte Felder existieren:
Gruppe Generic Child Variant
Felder Farbe und Größe (Liste=Ja, Gruppe zugeordnet, kein addtocart)
Beim Elternprodukt: ein Pluginfeld Generic Child Variant mit Layout Position = addtocart.
Schreibweisen der Werte sind identisch und deckungsgleich mit Printful.
Logs zeigen die getroffenen Maßnahmen (created/updated/skipped).
Code-Stil
PHP 8.3.26, Namespaces, strikte Typen, Exceptions + try/catch mit VM-Logs.
Keine BC-Breaks im öffentlichen API deines Plugins.
Ausführliche in-code DocBlocks, kurze Commit-Einheiten.
