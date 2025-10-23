# printful-virtuemart

schreibe mithilfe der virtuemart und printful Dokumentation einen Promt für Codex zur Erstellung eines Joomla 5/PhP 8.3.26 VirtueMart 4.2.18 11050 Plugins zum Synchronisieren von Printful Produkten und Virtuemart. Verbindung per API token und Store ID. Bestellungen über Virtuemart an Printful. Folgende Custom Field Funktion muss integriert werden.:

Codex Prompt
Du bist Senior-PHP-Entwickler für Joomla 5 + VirtueMart 4.
Du erhältst Zugriff auf ein bestehendes Printful-Sync-Plugin (Komponente/Plugin), das Produkte von Printful in VirtueMart anlegt/aktualisiert.
Ziel: Beim Sync sollen alle Printful-Varianten automatisch so in VirtueMart abgebildet werden, dass im Elternprodukt zwei Dropdowns (Farbe, Größe) erscheinen und bei Auswahl die passende Kindprodukt-Kombination geladen wird (VirtueMart Generic Child Variant Workflow).
Anforderungen (funktional)
Custom-Field-Infrastruktur sicherstellen (idempotent):
Erzeuge/verwende eine Benutzerdefinierte Feld-Gruppe namens Generic Child Variant (configurierbarer Name).
Erzeuge/aktualisiere zwei Custom Fields vom Typ „Zeichenfolge“:
Farbe (configurierbarer Label-Text)
Größe (configurierbarer Label-Text)
Einstellungen für beide Felder:
Ist eine Liste = Ja; befülle die Zulässigen Werte aus den Printful-Attributen (color, size) → eine Option pro Wert, exakt geschriebene Bezeichnung.
Warenkorb Attribut = Nein, Warenkorb Eingabe = Nein, durchsuchbar = Nein.
Benutzerdefinierte Gruppe = Generic Child Variant.
Layout Position leer (nicht „addtocart“).
Werte-Listen werden vereinigt (neue Werte hinzu, veraltete optional deaktivieren – per Plugin-Option).
Elternprodukt konfigurieren:
Lege genau ein Custom Field vom Typ Plugin → vmcustom – Generic Child Variant am Elternprodukt an (falls nicht vorhanden).
Einstellungen:
Layout Position = addtocart.
Stammprodukt anzeigen = Nein, Stammprodukt bestellbar = Nein, Preis anzeigen = Ja.
Ajax auf Übersichtsseiten benutzen = optional (per Plugin-Option).
Keine Farbe/Größe-Felder direkt am Elternprodukt anlegen.
Kindprodukte erzeugen/aktualisieren:
Für jede Printful-Variant (color × size) ein Kindprodukt unter dem Elternprodukt:
SKU, Name (Konvention: <ParentName> – <Farbe> / <Größe>), Preis, Lagerbestand, Bilder gemäß Printful.
Benutzerdefinierte Felder am Kind:
Farbe = passender Listenwert
Größe = passender Listenwert
Veröffentlichungsstatus & Sichtbarkeit gemäß Printful-Availability.
Idempotent: existierende Kinder per SKU matchen und aktualisieren; nicht mehr vorhandene Varianten de-publizieren oder löschen (per Option).
Mapping & Lokalisierung:
Printful → VirtueMart Mapping:
variant.color → Farbe
variant.size → Größe
Optionale Label-Mapping-Tabelle (z. B. Dark Heather → Dark Heather / Heather Grey → Heather Grey), damit Schreibweisen konsistent bleiben.
Erlaube Mehrsprachigkeit: Felder/Gruppen werden in „Alle“ angelegt; Labels können via Sprach-Konstante gesetzt werden.
Wiederholbarer Sync:
Ein Lauf darf keine Duplikate erzeugen.
Änderungen an Feld-Optionen, Preisen, Lagerbeständen, Bildern werden übernommen.
Schreibe aussagekräftige Logs.
Keine Template-Abhängigkeiten: Es wird nur VM-Datenstruktur benutzt; vorhandene Template-Overrides dürfen unverändert bleiben.
Technische Leitplanken
Nutze VirtueMart-Modelle statt Roh-SQL, wo möglich:
VmModel::getModel('custom'), VmModel::getModel('product'), VmModel::getModel('customfields').
Für Custom-Feld-Werte am Produkt: speichere über $productModel->store($data) mit customfields-Array (je Eintrag: virtuemart_custom_id, customfield_value, custom_param leer).
Das Generic-Child-Variant-Plugin-Feld identifizierst du über field_type = 'E' (plugin) und custom_element = 'genericchild' (oder via Titelvergleich „Generic Child Variant“); wähle die existierende virtuemart_custom_id. Falls nicht vorhanden, anlegen.
Achte auf Reihenfolge der Dropdowns: Im Backend (Benutzerdefinierte Felder) steht Farbe vor Größe. Stelle das programmatisch sicher.
Transaktionen/Locking, wenn Massensync.
Konfiguration im Printful-Plugin (INI/params):
group_title (Default: Generic Child Variant)
color_field_title (Default: Farbe)
size_field_title (Default: Größe)
delete_missing_variants (bool)
category_ajax_on_list (bool)
optionale value_map_color, value_map_size (JSON)
Algorithmus (Pseudocode)
function syncPrintfulProductToVM(PrintfulProduct $pf) {
  $cfg = $this->params();

  // 1) Infra sicherstellen
  $groupId = ensureCustomFieldGroup($cfg->group_title);
  $colorId = ensureListCustomField($cfg->color_field_title, $groupId);
  $sizeId  = ensureListCustomField($cfg->size_field_title,  $groupId);

  $colors = collectDistinct($pf->variants, 'color', $cfg->value_map_color);
  $sizes  = collectDistinct($pf->variants, 'size',  $cfg->value_map_size);

  updateListOptions($colorId, $colors);
  updateListOptions($sizeId,  $sizes);

  // 2) Parent
  $parentId = ensureParentProduct($pf);
  ensureGenericChildVariantPluginOnParent($parentId, [
     'layout_pos' => 'addtocart',
     'show_parent' => 0,
     'parent_orderable' => 0,
     'show_price' => 1,
     'ajax_in_category' => (int)$cfg->category_ajax_on_list,
  ]);

  // 3) Children
  $seenChildSkus = [];
  foreach ($pf->variants as $v) {
    $sku = makeChildSku($pf, $v);
    $childId = ensureChildProduct($parentId, $sku, $v);
    setChildCustomFieldValue($childId, $colorId, mapValue($v->color));
    setChildCustomFieldValue($childId, $sizeId,  mapValue($v->size));
    $seenChildSkus[] = $sku;
  }

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
