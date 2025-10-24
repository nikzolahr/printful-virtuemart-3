<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Vmextended.Printful
 *
 * VirtueMart ↔ Printful Integration Plugin
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

use Joomla\CMS\Http\HttpFactory;

require_once __DIR__ . '/classes/SyncService.php';

/**
 * VirtueMart ↔ Printful Integration Plugin.
 */
class plgVmExtendedPrintful extends CMSPlugin
{
    private const LOG_CHANNEL = 'plgVmExtendedPrintful';
    private const PRINTFUL_COMMENT_PREFIX = 'Printful order ID: ';

    /**
     * @var    PlgVmExtendedPrintfulSyncService|null
     */
    private $syncService;

    /**
     * @var    \Joomla\CMS\Application\CMSApplicationInterface
     */
    protected $app;

    /**
     * @var    boolean  Automatically load the language file.
     */
    protected $autoloadLanguage = true;

    /**
     * @var    array<int, object>  Cached VirtueMart products to avoid repeated lookups.
     */
    private $productCache = [];

    /**
     * Constructor.
     *
     * @param   object  $subject  The object to observe
     * @param   array   $config   An optional associative array of configuration settings
     */
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        // Ensure translations are available both from the plugin directory and the administrator client.
        $this->loadLanguage();
        $this->loadLanguage('', JPATH_ADMINISTRATOR);

        // Ensure logging is configured once per request.
        static $loggerRegistered = false;

        if (!$loggerRegistered) {
            Log::addLogger(
                [
                    'text_file' => 'plg_vmextended_printful.log.php',
                    'text_entry_format' => '{DATE}\t{TIME}\t{PRIORITY}\t{MESSAGE}',
                ],
                Log::ALL,
                [self::LOG_CHANNEL]
            );

            $loggerRegistered = true;
        }
    }

    /**
     * Handle confirmed VirtueMart orders and create corresponding Printful orders.
     *
     * @param   object  $cart   The VirtueMart cart (unused).
     * @param   array   $order  The confirmed order data.
     *
     * @return  void
     */
    public function plgVmConfirmedOrder($cart, $order): void
    {
        $this->handleOrderEvent(__FUNCTION__, $cart, $order);
    }

    /**
     * Alternative hook for confirmed orders.
     *
     * @param   object  $cart   The VirtueMart cart (unused).
     * @param   array   $order  The confirmed order data.
     *
     * @return  void
     */
    public function plgVmAfterOrderConfirmed($cart, $order): void
    {
        $this->handleOrderEvent(__FUNCTION__, $cart, $order);
    }

    /**
     * AJAX entrypoint for Printful webhooks (package_shipped/shipment_sent).
     *
     * @return  void
     */
    public function onAjaxPrintful(): void
    {
        try {
            $task = $this->app->input->getCmd('task');

            if ($task === 'syncProducts') {
                if (!$this->ensureAjaxPostRequest()) {
                    return;
                }

                if (!$this->ensureAjaxToken()) {
                    return;
                }

                if (!$this->ensureSyncPermission()) {
                    return;
                }

                $this->handleAjaxSyncProducts();

                return;
            }

            if ($task === 'pingPrintful') {
                if (!$this->ensureAjaxPostRequest()) {
                    return;
                }

                if (!$this->ensureAjaxToken()) {
                    return;
                }

                if (!$this->ensureSyncPermission()) {
                    return;
                }

                $this->handleAjaxPingPrintful();

                return;
            }

            if ($task === 'registerWebhook') {
                if (!$this->ensureAjaxPostRequest()) {
                    return;
                }

                if (!$this->ensureAjaxToken()) {
                    return;
                }

                if (!$this->ensureSyncPermission()) {
                    return;
                }

                $this->handleAjaxRegisterWebhook();

                return;
            }

            if ($task === 'webhookStatus') {
                if (!$this->ensureSyncPermission()) {
                    return;
                }

                $this->handleAjaxWebhookStatus();

                return;
            }

            if ($task === 'disableWebhook') {
                if (!$this->ensureAjaxPostRequest()) {
                    return;
                }

                if (!$this->ensureAjaxToken()) {
                    return;
                }

                if (!$this->ensureSyncPermission()) {
                    return;
                }

                $this->handleAjaxDisableWebhook();

                return;
            }

            if ($task !== '') {
                $this->respondJson(['status' => 'error', 'message' => 'Unknown task'], 400);

                return;
            }

            $this->handleWebhookRequest();
        } catch (\Throwable $e) {
            Log::add('AJAX error: ' . $e->getMessage(), Log::ERROR, self::LOG_CHANNEL);
            $this->respondJson(['status' => 'error', 'message' => 'Sync failed: runtime error'], 500);
        }
    }

    /**
     * Execute the product synchronisation task via AJAX.
     *
     * @return  void
     */
    private function handleAjaxSyncProducts(): void
    {
        if ($this->getPrintfulToken() === '') {
            Log::add('Product synchronisation aborted: missing Printful API token.', Log::WARNING, self::LOG_CHANNEL);

            $this->respondJson(
                [
                    'status' => 'error',
                    'message' => Text::_('PLG_VMEXTENDED_PRINTFUL_ERROR_TOKEN_MISSING'),
                ],
                400
            );

            return;
        }

        try {
            $service = $this->getSyncService();
            $result = $service->sync();
        } catch (PlgVmExtendedPrintfulSyncException $exception) {
            Log::add('Sync failed: ' . $exception->getMessage(), Log::WARNING, self::LOG_CHANNEL);

            $statusCode = (int) $exception->getCode();

            if ($statusCode < 400 || $statusCode > 599) {
                $statusCode = 400;
            }

            $this->respondJson([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], $statusCode);

            return;
        } catch (\Throwable $throwable) {
            Log::add('Sync failed: ' . $throwable->getMessage(), Log::ERROR, self::LOG_CHANNEL);
            $this->respondJson([
                'status' => 'error',
                'message' => 'Sync failed: runtime error',
            ], 500);

            return;
        }

        $payload = [
            'status' => 'ok',
            'created' => (int) ($result['created'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'errors' => (int) ($result['errors'] ?? 0),
            'fetched' => (int) ($result['fetched'] ?? 0),
            'processed' => (int) ($result['processed'] ?? 0),
            'dryRun' => (bool) ($result['dry_run'] ?? false),
            'skipSamples' => array_values(array_slice((array) ($result['skipSamples'] ?? []), 0, 10)),
            'errorSamples' => array_values(array_slice((array) ($result['errorSamples'] ?? []), 0, 5)),
            'tokenType' => (string) ($result['tokenType'] ?? ''),
            'endpoint' => (string) ($result['endpoint'] ?? ''),
            'apiBase' => (string) ($result['apiBase'] ?? ''),
            'httpStatus' => (int) ($result['httpStatus'] ?? 0),
            'requestHeaders' => array_values(array_slice((array) ($result['requestHeaders'] ?? []), 0, 10)),
            'pfSample' => array_values(array_slice((array) ($result['pfSample'] ?? []), 0, 3)),
        ];

        $statusCode = (int) ($result['httpStatus'] ?? 200);

        if ($statusCode < 100 || $statusCode > 599) {
            $statusCode = 200;
        }

        $this->respondJson($payload, $statusCode);
    }

    /**
     * Perform a lightweight connectivity check against Printful.
     *
     * @return  void
     */
    private function handleAjaxPingPrintful(): void
    {
        if ($this->getPrintfulToken() === '') {
            $this->respondJson([
                'status' => 'error',
                'message' => Text::_('PLG_VMEXTENDED_PRINTFUL_ERROR_TOKEN_MISSING'),
            ], 400);

            return;
        }

        try {
            $service = $this->getSyncService();
            $result = $service->ping();
        } catch (PlgVmExtendedPrintfulSyncException $exception) {
            $statusCode = (int) $exception->getCode();

            if ($statusCode < 400 || $statusCode > 599) {
                $statusCode = 400;
            }

            $this->respondJson([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], $statusCode);

            return;
        } catch (\Throwable $throwable) {
            Log::add('Ping failed: ' . $throwable->getMessage(), Log::ERROR, self::LOG_CHANNEL);
            $this->respondJson([
                'status' => 'error',
                'message' => 'Ping failed: runtime error',
            ], 500);

            return;
        }

        $payload = [
            'status' => 'ok',
            'tokenType' => (string) ($result['tokenType'] ?? ''),
            'endpoint' => (string) ($result['endpoint'] ?? '/v2/store/products'),
            'httpStatus' => (int) ($result['httpStatus'] ?? 0),
            'requestHeaders' => array_values(array_slice((array) ($result['requestHeaders'] ?? []), 0, 10)),
            'pfSample' => array_values(array_slice((array) ($result['pfSample'] ?? []), 0, 3)),
            'fetched' => (int) ($result['fetched'] ?? 0),
        ];

        $this->respondJson($payload);
    }

    /**
     * Register a webhook with the Printful API.
     *
     * @return  void
     */
    private function handleAjaxRegisterWebhook(): void
    {
        $eventType = $this->app->input->getCmd('event');
        $targetUrl = $this->app->input->getString('target');

        if ($eventType === '' || $targetUrl === '') {
            $this->respondJson(['status' => 'error', 'message' => 'Webhook event or target missing'], 400);

            return;
        }

        try {
            $response = $this->dispatchPrintfulRequest('POST', 'webhooks/' . $eventType, ['url' => $targetUrl]);
        } catch (\Throwable $throwable) {
            Log::add('Webhook registration failed: ' . $throwable->getMessage(), Log::ERROR, self::LOG_CHANNEL);
            $this->respondJson(['status' => 'error', 'message' => 'Webhook registration failed'], 502);

            return;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']);
            $this->respondJson([
                'status' => 'error',
                'message' => $message !== '' ? $message : 'Webhook registration rejected',
            ], $response['status']);

            return;
        }

        $this->respondJson([
            'status' => 'ok',
            'data' => $response['body']['result'] ?? $response['body'],
        ]);
    }

    /**
     * Retrieve webhook registration status for a given event type.
     *
     * @return  void
     */
    private function handleAjaxWebhookStatus(): void
    {
        $eventType = $this->app->input->getCmd('event');

        $path = $eventType === '' ? 'webhooks' : 'webhooks/' . $eventType;

        try {
            $response = $this->dispatchPrintfulRequest('GET', $path);
        } catch (\Throwable $throwable) {
            Log::add('Webhook status request failed: ' . $throwable->getMessage(), Log::ERROR, self::LOG_CHANNEL);
            $this->respondJson(['status' => 'error', 'message' => 'Webhook status unavailable'], 502);

            return;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']);
            $this->respondJson([
                'status' => 'error',
                'message' => $message !== '' ? $message : 'Webhook status request rejected',
            ], $response['status']);

            return;
        }

        $this->respondJson([
            'status' => 'ok',
            'data' => $response['body']['result'] ?? $response['body'],
        ]);
    }

    /**
     * Disable webhook registrations.
     *
     * @return  void
     */
    private function handleAjaxDisableWebhook(): void
    {
        $eventType = $this->app->input->getCmd('event');
        $path = $eventType === '' ? 'webhooks' : 'webhooks/' . $eventType;

        try {
            $response = $this->dispatchPrintfulRequest('DELETE', $path);
        } catch (\Throwable $throwable) {
            Log::add('Webhook disable failed: ' . $throwable->getMessage(), Log::ERROR, self::LOG_CHANNEL);
            $this->respondJson(['status' => 'error', 'message' => 'Webhook disable failed'], 502);

            return;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']);
            $this->respondJson([
                'status' => 'error',
                'message' => $message !== '' ? $message : 'Webhook disable rejected',
            ], $response['status']);

            return;
        }

        $this->respondJson([
            'status' => 'ok',
        ]);
    }

    /**
     * Handle incoming webhook callbacks.
     *
     * @return  void
     */
    private function handleWebhookRequest(): void
    {
        $rawBody = file_get_contents('php://input');

        try {
            $this->verifySignatureIfEnabled($rawBody);
        } catch (\RuntimeException $exception) {
            $this->respondJson(['status' => 'error', 'message' => $exception->getMessage()], 403);

            return;
        }

        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            Log::add('Received malformed webhook payload (JSON decode failed).', Log::ERROR, self::LOG_CHANNEL);
            $this->respondJson(['status' => 'error', 'message' => 'Invalid payload'], 400);

            return;
        }

        $eventType = $data['type'] ?? '';

        Log::add('Webhook event received: ' . $eventType, Log::INFO, self::LOG_CHANNEL);

        if (!in_array($eventType, ['package_shipped', 'shipment_sent'], true)) {
            Log::add('Ignoring unsupported webhook event: ' . $eventType, Log::INFO, self::LOG_CHANNEL);
            $this->respondJson(['status' => 'ignored']);

            return;
        }

        $orderNumber = (string) ($data['data']['order']['external_id'] ?? '');

        if ($orderNumber === '') {
            Log::add('Webhook payload missing external order number.', Log::ERROR, self::LOG_CHANNEL);
            $this->respondJson(['status' => 'error', 'message' => 'Missing order reference'], 422);

            return;
        }

        $trackingNumbers = $this->extractTrackingNumbers($data['data']['shipments'] ?? []);

        try {
            $this->setOrderAsShipped($orderNumber, $trackingNumbers);
        } catch (\RuntimeException $exception) {
            Log::add('Failed to update VM order for webhook: ' . $exception->getMessage(), Log::ERROR, self::LOG_CHANNEL);
            $this->respondJson(['status' => 'error', 'message' => 'Order update failed'], 500);

            return;
        }

        $this->respondJson(['status' => 'ok']);
    }

    /**
     * Inject administrator helper assets for manual synchronisation.
     *
     * @param   Form   $form  The form being prepared.
     * @param   mixed  $data  Form data.
     *
     * @return  boolean
     */
    public function onContentPrepareForm(Form $form, $data): bool
    {
        if (!$this->app->isClient('administrator')) {
            return true;
        }

        if ($form->getName() !== 'com_plugins.plugin') {
            return true;
        }

        Form::addFieldPath(__DIR__ . '/fields');

        $element = '';
        $folder = '';

        if (is_array($data)) {
            $element = (string) ($data['element'] ?? '');
            $folder = (string) ($data['folder'] ?? $data['type'] ?? '');
        } elseif (is_object($data)) {
            $element = (string) ($data->element ?? '');
            $folder = (string) ($data->folder ?? $data->type ?? '');
        } else {
            $element = (string) $form->getValue('element');
            $folder = (string) $form->getValue('folder');
        }

        if ($element !== 'printful' || ($folder !== '' && $folder !== 'vmextended')) {
            return true;
        }

        $this->registerAdminAssets();

        return true;
    }

    /**
     * Register administrator assets for manual synchronisation helper.
     *
     * @return  void
     */
    private function registerAdminAssets(): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        $document = Factory::getDocument();
        $webAssetManager = $document->getWebAssetManager();

        if (!$webAssetManager->assetExists('script', 'plg.vmextended.printful.admin-sync')) {
            $webAssetManager->registerScript(
                'plg.vmextended.printful.admin-sync',
                'plg_vmextended_printful/js/admin-sync.js',
                [],
                ['version' => 'auto'],
                ['relative' => true]
            );
        }

        $webAssetManager->useScript('plg.vmextended.printful.admin-sync');

        Text::script('PLG_VMEXTENDED_PRINTFUL_SYNC_INTRO');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SYNC_BUTTON_LABEL');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SYNC_RUNNING');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SYNC_DONE');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SYNC_ERROR');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SYNC_FETCHED_PROCESSED');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SYNC_SKIP_SAMPLES');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SYNC_SKIP_ENTRY');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SKIP_REASON_FILTERED_BY_STATUS');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SKIP_REASON_FILTERED_BY_WAREHOUSE');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SKIP_REASON_MISSING_VARIANT_ID');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SKIP_REASON_MISSING_NAME');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SKIP_REASON_MISSING_PRICE');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SKIP_REASON_AMBIGUOUS_MATCH');
        Text::script('PLG_VMEXTENDED_PRINTFUL_SKIP_REASON_MATCH_FOUND_BUT_NO_CHANGES');
        Text::script('JERROR_AN_ERROR_HAS_OCCURRED');

        $registered = true;
    }

    /**
     * Unified handler for VirtueMart order confirmation events.
     *
     * @param   string  $triggerName  Name of the trigger that fired.
     * @param   object  $cart         VirtueMart cart (unused).
     * @param   array   $order        Order payload.
     *
     * @return  void
     */
    private function handleOrderEvent(string $triggerName, $cart, $order): void
    {
        $token = trim((string) $this->params->get('printful_token'));

        if ($token === '') {
            Log::add('Printful integration skipped – API token missing.', Log::INFO, self::LOG_CHANNEL);

            return;
        }

        if (!is_array($order) || empty($order['details']['BT']) || !is_object($order['details']['BT'])) {
            Log::add('Order payload missing billing details for trigger ' . $triggerName . '.', Log::ERROR, self::LOG_CHANNEL);

            return;
        }

        $billing = $order['details']['BT'];
        $shipping = $order['details']['ST'] ?? $billing;
        $orderNumber = (string) ($billing->order_number ?? '');
        $orderId = (int) ($billing->virtuemart_order_id ?? 0);
        $items = $order['items'] ?? [];

        if (!is_array($items)) {
            $items = (array) $items;
        }

        $productNames = [];

        foreach ($items as $item) {
            $name = is_object($item) ? ($item->order_item_name ?? '') : ($item['order_item_name'] ?? '');

            if ($name !== '') {
                $productNames[] = $name;
            }
        }

        Log::add(
            sprintf(
                '[%s] Order event received for %s (ID %s) with %d item(s)%s.',
                $triggerName,
                $orderNumber !== '' ? $orderNumber : 'n/a',
                $orderId ?: 'n/a',
                count($items),
                $productNames ? ' – ' . implode(', ', $productNames) : ''
            ),
            Log::INFO,
            self::LOG_CHANNEL
        );

        if ($orderNumber === '' || $orderId === 0) {
            Log::add('Order payload missing order number or ID, aborting Printful sync.', Log::ERROR, self::LOG_CHANNEL);

            return;
        }

        try {
            if ($this->hasOrderBeenSentToPrintful($orderId)) {
                Log::add('Order ' . $orderNumber . ' already synced to Printful, skipping.', Log::INFO, self::LOG_CHANNEL);

                return;
            }
        } catch (\RuntimeException $exception) {
            Log::add('Failed to inspect VirtueMart order history: ' . $exception->getMessage(), Log::ERROR, self::LOG_CHANNEL);

            return;
        }

        try {
            $recipient = $this->buildRecipientPayload($shipping);
        } catch (\RuntimeException $exception) {
            Log::add('Unable to build Printful recipient: ' . $exception->getMessage(), Log::ERROR, self::LOG_CHANNEL);

            return;
        }

        $customFieldName = trim((string) $this->params->get('variant_customfield', 'printful_variant_id'));
        $itemsPayload = $this->buildItemsPayload($items, $customFieldName);

        if (empty($itemsPayload)) {
            Log::add('Order ' . $orderNumber . ' has no Printful-ready line items – skipping.', Log::WARNING, self::LOG_CHANNEL);

            return;
        }

        $payload = [
            'external_id' => $orderNumber,
            'recipient' => $recipient,
            'items' => $itemsPayload,
        ];

        $storeId = trim((string) $this->params->get('printful_store_id'));

        if ($storeId !== '') {
            $payload['store'] = ['id' => $storeId];
        }

        if ((int) $this->params->get('auto_confirm', 0) === 1) {
            $payload['confirm'] = true;
        }

        try {
            $response = $this->printfulRequest('POST', 'orders', $payload, $token);
        } catch (\RuntimeException $exception) {
            Log::add('Failed to communicate with Printful: ' . $exception->getMessage(), Log::ERROR, self::LOG_CHANNEL);

            return;
        }

        $status = $response['status'];
        $body = $response['body'];
        $printfulOrderId = (int) ($body['result']['id'] ?? 0);

        if ($status === 409) {
            Log::add('Printful reported existing order for ' . $orderNumber . ', attempting lookup.', Log::WARNING, self::LOG_CHANNEL);

            try {
                $lookup = $this->printfulRequest('GET', 'orders/@' . rawurlencode($orderNumber), null, $token);
            } catch (\RuntimeException $exception) {
                Log::add('Unable to retrieve existing Printful order for ' . $orderNumber . ': ' . $exception->getMessage(), Log::ERROR, self::LOG_CHANNEL);

                return;
            }

            if ($lookup['status'] >= 200 && $lookup['status'] < 300) {
                $printfulOrderId = (int) ($lookup['body']['result']['id'] ?? 0);
                $status = $lookup['status'];
                $body = $lookup['body'];
            }
        }

        if ($status < 200 || $status >= 300) {
            $snippet = '';

            if (is_array($body)) {
                $snippet = substr(json_encode($body), 0, 300);
            } elseif (is_string($response['raw'])) {
                $snippet = substr($response['raw'], 0, 300);
            }

            Log::add(
                'Printful API returned HTTP ' . $status . ' for order ' . $orderNumber . ' – ' . $snippet,
                Log::ERROR,
                self::LOG_CHANNEL
            );

            return;
        }

        Log::add('Printful order successfully submitted for VM order ' . $orderNumber . '.', Log::INFO, self::LOG_CHANNEL);

        if ($printfulOrderId > 0) {
            try {
                $this->storePrintfulOrderReference($orderId, $orderNumber, (string) $printfulOrderId);
            } catch (\RuntimeException $exception) {
                Log::add('Unable to persist Printful order reference: ' . $exception->getMessage(), Log::ERROR, self::LOG_CHANNEL);
            }
        } else {
            Log::add('Printful response for order ' . $orderNumber . ' missing order ID.', Log::WARNING, self::LOG_CHANNEL);
        }
    }

    /**
     * Build the Printful recipient payload from VirtueMart address data.
     *
     * @param   object  $address  VirtueMart address object.
     *
     * @return  array
     */
    private function buildRecipientPayload($address): array
    {
        if (!is_object($address)) {
            throw new \RuntimeException('Invalid address payload');
        }

        $stateCode = strtoupper((string) ($address->state_code ?? $address->state ?? $address->state_name ?? ''));
        $countryCode = strtoupper((string) ($address->country_2_code ?? $address->country ?? ''));

        $recipient = [
            'name' => trim(((string) ($address->first_name ?? '')) . ' ' . ((string) ($address->last_name ?? ''))),
            'company' => (string) ($address->company ?? ''),
            'address1' => (string) ($address->address_1 ?? ''),
            'address2' => (string) ($address->address_2 ?? ''),
            'city' => (string) ($address->city ?? ''),
            'state_code' => $stateCode,
            'country_code' => $countryCode,
            'zip' => (string) ($address->zip ?? ''),
            'phone' => (string) ($address->phone_1 ?? $address->phone_2 ?? ''),
            'email' => (string) ($address->email ?? ''),
        ];

        return array_filter(
            $recipient,
            static function ($value) {
                return $value !== null && $value !== '';
            }
        );
    }

    /**
     * Build the Printful items payload.
     *
     * @param   array   $orderItems        VirtueMart order items.
     * @param   string  $customFieldName   Custom field name used for variant IDs.
     *
     * @return  array
     */
    private function buildItemsPayload(array $orderItems, string $customFieldName): array
    {
        $items = [];

        foreach ($orderItems as $item) {
            $productId = 0;

            if (is_object($item) && isset($item->virtuemart_product_id)) {
                $productId = (int) $item->virtuemart_product_id;
            } elseif (is_array($item) && isset($item['virtuemart_product_id'])) {
                $productId = (int) $item['virtuemart_product_id'];
            }

            try {
                $variantId = $this->getPrintfulVariantId($productId, $customFieldName, $item);
            } catch (\RuntimeException $exception) {
                Log::add('Error resolving variant ID for product ' . $productId . ': ' . $exception->getMessage(), Log::ERROR, self::LOG_CHANNEL);

                continue;
            }

            if ($variantId === null) {
                $productName = is_object($item) ? ($item->order_item_name ?? 'Unknown') : ($item['order_item_name'] ?? 'Unknown');
                Log::add('Skipping line item without Printful variant ID: ' . $productName, Log::WARNING, self::LOG_CHANNEL);

                continue;
            }

            $quantity = 0;

            if (is_object($item) && isset($item->product_quantity)) {
                $quantity = (int) $item->product_quantity;
            } elseif (is_array($item) && isset($item['product_quantity'])) {
                $quantity = (int) $item['product_quantity'];
            }

            if ($quantity < 1) {
                Log::add('Skipping line item with invalid quantity for variant ' . $variantId, Log::WARNING, self::LOG_CHANNEL);

                continue;
            }

            $line = [
                'variant_id' => $variantId,
                'quantity' => $quantity,
            ];

            $price = null;

            if (is_object($item) && isset($item->product_final_price)) {
                $price = $item->product_final_price;
            } elseif (is_array($item) && isset($item['product_final_price'])) {
                $price = $item['product_final_price'];
            }

            if ($price !== null && $price !== '') {
                $line['retail_price'] = (string) $price;
            }

            $items[] = $line;
        }

        return $items;
    }

    /**
     * Resolve the Printful variant ID for an order item.
     *
     * @param   int          $virtuemartProductId  Product ID.
     * @param   string       $customFieldName      Custom field name.
     * @param   object|array $orderItem            Order item data.
     *
     * @return  int|null
     */
    private function getPrintfulVariantId(int $virtuemartProductId, string $customFieldName, $orderItem): ?int
    {
        if ($virtuemartProductId > 0) {
            try {
                $product = $this->getVirtueMartProduct($virtuemartProductId);
            } catch (\RuntimeException $exception) {
                Log::add('Unable to load product ' . $virtuemartProductId . ': ' . $exception->getMessage(), Log::ERROR, self::LOG_CHANNEL);
                $product = null;
            }

            if ($product && !empty($product->customfields)) {
                foreach ((array) $product->customfields as $field) {
                    $fieldName = '';
                    $fieldValue = null;

                    if (is_object($field)) {
                        $fieldName = (string) ($field->custom_title ?? $field->customfield_name ?? $field->title ?? '');
                        $fieldValue = $field->customfield_value ?? $field->custom_value ?? $field->value ?? null;
                    } elseif (is_array($field)) {
                        $fieldName = (string) ($field['custom_title'] ?? $field['customfield_name'] ?? $field['title'] ?? '');
                        $fieldValue = $field['customfield_value'] ?? $field['custom_value'] ?? $field['value'] ?? null;
                    }

                    if ($fieldName === '') {
                        continue;
                    }

                    if (strcasecmp($fieldName, $customFieldName) !== 0) {
                        continue;
                    }

                    if ($fieldValue === null || $fieldValue === '') {
                        continue;
                    }

                    $numeric = preg_replace('/[^0-9]/', '', (string) $fieldValue);

                    if ($numeric === '') {
                        continue;
                    }

                    return (int) $numeric;
                }
            }
        }

        $attribute = null;

        if (is_object($orderItem) && isset($orderItem->product_attribute)) {
            $attribute = $orderItem->product_attribute;
        } elseif (is_array($orderItem) && isset($orderItem['product_attribute'])) {
            $attribute = $orderItem['product_attribute'];
        }

        if (is_string($attribute) && $attribute !== '') {
            $numeric = preg_replace('/[^0-9]/', '', $attribute);

            if ($numeric !== '') {
                return (int) $numeric;
            }
        }

        return null;
    }

    /**
     * Retrieve a VirtueMart product by ID.
     *
     * @param   int  $productId  The product ID.
     *
     * @return  object
     */
    private function getVirtueMartProduct(int $productId)
    {
        if (isset($this->productCache[$productId])) {
            return $this->productCache[$productId];
        }

        $this->bootstrapVirtueMart();

        if (!class_exists('VmModel')) {
            throw new \RuntimeException('VirtueMart VmModel class not available.');
        }

        // Ensure customfields model is initialised so product customfields are populated.
        \VmModel::getModel('customfields');

        $productModel = \VmModel::getModel('product');

        if (!method_exists($productModel, 'getProduct')) {
            throw new \RuntimeException('VirtueMart product model is missing getProduct method.');
        }

        $product = $productModel->getProduct($productId, true, true, true, 1, true);

        if (!$product) {
            throw new \RuntimeException('Product not found.');
        }

        $this->productCache[$productId] = $product;

        return $product;
    }

    /**
     * Dispatch a Printful API request.
     *
     * @param   string      $method   HTTP method.
     * @param   string      $path     API path.
     * @param   array|null  $payload  Request payload.
     * @param   string      $token    Bearer token.
     * @param   array       $query    Optional query parameters.
     *
     * @return  array{status:int,body:mixed,raw:string,headers:mixed}
     */
    private function printfulRequest(string $method, string $path, ?array $payload, string $token, array $query = []): array
    {
        $jsonPayload = null;

        if ($payload !== null) {
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($jsonPayload === false) {
                throw new \RuntimeException('Failed to encode Printful payload.');
            }
        }

        $httpOptions = new Registry([
            'timeout' => 20,
        ]);

        $http = HttpFactory::getHttp($httpOptions);
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];

        $storeId = trim((string) $this->params->get('printful_store_id'));
        $useAccountToken = (bool) $this->params->get('use_account_token', 0);

        if ($useAccountToken) {
            if ($storeId !== '') {
                $headers['X-PF-Store-Id'] = $storeId;
            } else {
                static $accountStoreWarningIssued = false;

                if (!$accountStoreWarningIssued) {
                    Log::add('Account token mode is enabled but no Printful Store ID is configured – requests may return empty results.', Log::WARNING, self::LOG_CHANNEL);
                    $accountStoreWarningIssued = true;
                }
            }
        } elseif ($storeId !== '') {
            static $storeTokenStoreWarningIssued = false;

            if (!$storeTokenStoreWarningIssued) {
                Log::add('Ignoring configured Printful Store ID because a store-bound API token is in use.', Log::DEBUG, self::LOG_CHANNEL);
                $storeTokenStoreWarningIssued = true;
            }
        }

        $language = trim((string) $this->params->get('printful_language'));

        if ($language !== '') {
            $headers['X-PF-Language'] = $language;
        }

        if ($jsonPayload !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $isStoreEndpoint = strpos(ltrim($path, '/'), 'store/') === 0;
        $baseUrls = ['https://api.printful.com/v2/'];

        if ($isStoreEndpoint) {
            $baseUrls[] = 'https://api.printful.com/';
        }

        $maxAttempts = max(1, (int) $this->params->get('api_retry_attempts', 3));
        $response = null;
        $statusCode = 0;
        $lastException = null;

        foreach ($baseUrls as $index => $baseUrl) {
            $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

            if (!empty($query)) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
            }

            $attempt = 0;
            $delay = 1;
            $response = null;

            do {
                $attempt++;

                try {
                    $response = $http->request($method, $url, $jsonPayload, $headers);
                } catch (\Throwable $throwable) {
                    Log::add('HTTP request to Printful failed on attempt ' . $attempt . ': ' . $throwable->getMessage(), Log::WARNING, self::LOG_CHANNEL);

                    if ($attempt >= $maxAttempts) {
                        $lastException = $throwable;
                        break;
                    }

                    sleep($delay);
                    $delay = min($delay * 2, 30);

                    continue;
                }

                $statusCode = (int) ($response->code ?? 0);

                if ($statusCode === 429 && $attempt < $maxAttempts) {
                    $retryAfter = $this->resolveRetryAfter($response->headers ?? []);
                    $sleepFor = $retryAfter ?? $delay;
                    $sleepFor = $sleepFor > 0 ? $sleepFor : $delay;
                    Log::add('Printful API rate limited request (HTTP 429). Retrying after ' . $sleepFor . ' second(s).', Log::WARNING, self::LOG_CHANNEL);
                    sleep($sleepFor);
                    $delay = min($delay * 2, 60);

                    continue;
                }

                if ($statusCode >= 500 && $statusCode < 600 && $attempt < $maxAttempts) {
                    Log::add('Printful API temporary error (HTTP ' . $statusCode . '), retrying.', Log::WARNING, self::LOG_CHANNEL);
                    sleep($delay);
                    $delay = min($delay * 2, 60);

                    continue;
                }

                break;
            } while ($attempt < $maxAttempts);

            if ($response === null) {
                if ($lastException !== null) {
                    throw new \RuntimeException('HTTP request to Printful failed: ' . $lastException->getMessage(), 0, $lastException);
                }

                continue;
            }

            if ($statusCode === 404 && $isStoreEndpoint && $index === 0) {
                Log::add('v2 store endpoints not available → using v1', Log::INFO, self::LOG_CHANNEL);
                $response = null;

                continue;
            }

            break;
        }

        if ($response === null) {
            throw new \RuntimeException('No response from Printful API.');
        }

        $statusCode = (int) ($response->code ?? 0);
        $rawBody = is_string($response->body ?? null) ? $response->body : json_encode($response->body);
        $decodedBody = null;

        if (is_string($rawBody) && $rawBody !== '') {
            $decodedBody = json_decode($rawBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::add('Failed to decode Printful response JSON: ' . json_last_error_msg(), Log::WARNING, self::LOG_CHANNEL);
                $decodedBody = null;
            }
        }

        if ($statusCode >= 400) {
            $message = $this->extractErrorMessage($decodedBody);
            Log::add('Printful API ' . $method . ' ' . $path . ' responded with HTTP ' . $statusCode . ($message !== '' ? ' – ' . $message : ''), Log::WARNING, self::LOG_CHANNEL);
        } else {
            Log::add('Printful API ' . $method . ' ' . $path . ' responded with HTTP ' . $statusCode . '.', Log::INFO, self::LOG_CHANNEL);
        }

        return [
            'status' => $statusCode,
            'body' => $decodedBody,
            'raw' => (string) $rawBody,
            'headers' => $response->headers ?? [],
        ];
    }

    /**
     * Wrapper for Printful API requests that exposes the transport to helper classes.
     *
     * @param   string      $method   HTTP verb.
     * @param   string      $path     API path.
     * @param   array|null  $payload  Optional payload.
     * @param   array       $query    Optional query parameters.
     *
     * @return  array{status:int,body:mixed,raw:string,headers:mixed}
     */
    public function dispatchPrintfulRequest(string $method, string $path, ?array $payload = null, array $query = []): array
    {
        $token = $this->getPrintfulToken();

        if ($token === '') {
            throw new \RuntimeException('Printful API token missing.');
        }

        return $this->printfulRequest($method, $path, $payload, $token, $query);
    }

    /**
     * Ensure AJAX call is performed via POST.
     *
     * @return  void
     */
    private function ensureAjaxPostRequest(): bool
    {
        if (strtoupper($this->app->input->getMethod()) !== 'POST') {
            $this->respondJson(['status' => 'error', 'message' => Text::_('JLIB_APPLICATION_ERROR_METHOD_NOT_ALLOWED')], 405);

            return false;
        }

        return true;
    }

    /**
     * Validate request token for AJAX operations.
     *
     * @return  bool
     */
    private function ensureAjaxToken(): bool
    {
        if (!Session::checkToken('request')) {
            $this->respondJson(['status' => 'error', 'message' => Text::_('JINVALID_TOKEN')], 403);

            return false;
        }

        return true;
    }

    /**
     * Ensure the current user has permission to trigger synchronisation/webhook actions.
     *
     * @return  bool
     */
    private function ensureSyncPermission(): bool
    {
        $identity = $this->app->getIdentity();

        $hasPermission = $identity instanceof User
            && (
                $identity->authorise('core.manage', 'com_virtuemart')
                || $identity->authorise('plg.vmextended.printful.sync', 'com_virtuemart')
                || $identity->authorise('core.admin')
            );

        if (!$hasPermission) {
            $this->respondJson(['status' => 'error', 'message' => Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN')], 403);

            return false;
        }

        return true;
    }

    /**
     * Extract an error message from a Printful API response body.
     *
     * @param   mixed  $body  Response body.
     *
     * @return  string
     */
    private function extractErrorMessage($body): string
    {
        if (is_array($body)) {
            if (isset($body['error']) && is_array($body['error']) && isset($body['error']['message'])) {
                return (string) $body['error']['message'];
            }

            if (isset($body['error']) && is_string($body['error'])) {
                return $body['error'];
            }

            if (isset($body['message']) && is_string($body['message'])) {
                return $body['message'];
            }
        }

        return '';
    }

    /**
     * Parse Retry-After header value.
     *
     * @param   array|object  $headers  Response headers.
     *
     * @return  int|null
     */
    private function resolveRetryAfter($headers): ?int
    {
        $headerValue = '';

        if (is_object($headers) && method_exists($headers, 'get')) {
            $value = $headers->get('Retry-After');

            if ($value !== null) {
                $headerValue = is_array($value) ? (string) reset($value) : (string) $value;
            }
        } elseif (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strcasecmp((string) $key, 'Retry-After') === 0) {
                    $headerValue = is_array($value) ? (string) reset($value) : (string) $value;
                    break;
                }
            }
        }

        if ($headerValue === '') {
            return null;
        }

        if (is_numeric($headerValue)) {
            return (int) $headerValue;
        }

        $timestamp = strtotime($headerValue);

        if ($timestamp === false) {
            return null;
        }

        $seconds = $timestamp - time();

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * Check whether a VirtueMart order already contains a Printful reference.
     *
     * @param   int  $orderId  VirtueMart order ID.
     *
     * @return  bool
     */
    private function hasOrderBeenSentToPrintful(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        $ordersModel = $this->getOrdersModel();
        $order = $ordersModel->getOrder($orderId);

        if (!isset($order['history']) || !is_array($order['history'])) {
            return false;
        }

        foreach ($order['history'] as $historyEntry) {
            $comment = is_object($historyEntry) ? ($historyEntry->comments ?? '') : ($historyEntry['comments'] ?? '');

            if (is_string($comment) && stripos($comment, self::PRINTFUL_COMMENT_PREFIX) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Persist the Printful order reference inside VirtueMart.
     *
     * @param   int     $orderId          VirtueMart order ID.
     * @param   string  $orderNumber      VirtueMart order number.
     * @param   string  $printfulOrderId  Printful order identifier.
     *
     * @return  void
     */
    private function storePrintfulOrderReference(int $orderId, string $orderNumber, string $printfulOrderId): void
    {
        if ($orderId <= 0 || $printfulOrderId === '') {
            return;
        }

        $ordersModel = $this->getOrdersModel();
        $order = $ordersModel->getOrder($orderId);
        $currentStatus = '';

        if (isset($order['details']['BT']->order_status)) {
            $currentStatus = (string) $order['details']['BT']->order_status;
        }

        $comment = self::PRINTFUL_COMMENT_PREFIX . $printfulOrderId;

        $updateData = [
            'customer_notified' => 0,
            'comments' => $comment,
        ];

        if ($currentStatus !== '') {
            $updateData['order_status'] = $currentStatus;
        }

        $ordersModel->updateStatusForOneOrder(
            $orderId,
            $updateData,
            false
        );

        Log::add('Stored Printful order reference for VM order ' . $orderNumber . ': ' . $printfulOrderId, Log::INFO, self::LOG_CHANNEL);
    }

    /**
     * Retrieve the VirtueMart orders model.
     *
     * @return  object
     */
    private function getOrdersModel()
    {
        $this->bootstrapVirtueMart();

        if (!class_exists('VmModel')) {
            throw new \RuntimeException('VirtueMart VmModel class not available.');
        }

        $ordersModel = \VmModel::getModel('orders');

        if (!is_object($ordersModel)) {
            throw new \RuntimeException('Unable to initialise VirtueMart orders model.');
        }

        return $ordersModel;
    }

    /**
     * Optionally verify webhook signature.
     *
     * @param   string  $rawBody  Raw request body.
     *
     * @return  void
     */
    private function verifySignatureIfEnabled(string $rawBody): void
    {
        $verify = (int) $this->params->get('verify_signature', 0) === 1;

        if (!$verify) {
            return;
        }

        $secretHex = trim((string) $this->params->get('webhook_secret_hex'));

        if ($secretHex === '') {
            throw new \RuntimeException('Signature verification is enabled but no secret is configured.');
        }

        $secret = @hex2bin($secretHex);

        if ($secret === false) {
            throw new \RuntimeException('Webhook secret must be valid hexadecimal.');
        }

        $headerName = trim((string) $this->params->get('signature_header', 'X-Printful-Signature'));
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        $headerValue = $_SERVER[$headerKey] ?? '';

        if ($headerValue === '') {
            throw new \RuntimeException('Missing webhook signature header.');
        }

        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);

        if (!hash_equals($expectedSignature, $headerValue)) {
            throw new \RuntimeException('Invalid webhook signature.');
        }
    }

    /**
     * Extract tracking numbers from webhook payload.
     *
     * @param   array  $shipments  Shipments array from webhook payload.
     *
     * @return  array
     */
    private function extractTrackingNumbers($shipments): array
    {
        if (!is_array($shipments)) {
            return [];
        }

        $numbers = [];

        foreach ($shipments as $shipment) {
            if (is_object($shipment)) {
                $shipment = (array) $shipment;
            }

            if (!is_array($shipment)) {
                continue;
            }

            if (!empty($shipment['tracking_number'])) {
                $numbers[] = (string) $shipment['tracking_number'];
            }

            if (!empty($shipment['tracking_numbers']) && is_array($shipment['tracking_numbers'])) {
                foreach ($shipment['tracking_numbers'] as $number) {
                    if ((string) $number !== '') {
                        $numbers[] = (string) $number;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($numbers)));
    }

    /**
     * Update VirtueMart order status to shipped and append tracking information.
     *
     * @param   string  $orderNumber      VirtueMart order number.
     * @param   array   $trackingNumbers  Tracking numbers list.
     *
     * @return  void
     */
    private function setOrderAsShipped(string $orderNumber, array $trackingNumbers): void
    {
        $ordersModel = $this->getOrdersModel();

        if (!method_exists($ordersModel, 'getOrderIdByOrderNumber')) {
            throw new \RuntimeException('Orders model missing getOrderIdByOrderNumber method.');
        }

        $orderId = $ordersModel->getOrderIdByOrderNumber($orderNumber);

        if (!$orderId) {
            throw new \RuntimeException('Order ' . $orderNumber . ' not found.');
        }

        $comment = 'Printful shipment received.';

        if (!empty($trackingNumbers)) {
            $comment .= ' Tracking: ' . implode(', ', $trackingNumbers);
        }

        if (!method_exists($ordersModel, 'updateStatusForOneOrder')) {
            throw new \RuntimeException('Orders model missing updateStatusForOneOrder method.');
        }

        $ordersModel->updateStatusForOneOrder(
            $orderId,
            [
                'order_status' => 'S',
                'customer_notified' => 0,
                'comments' => $comment,
            ],
            true
        );

        Log::add('Order ' . $orderNumber . ' marked as shipped via Printful webhook.', Log::INFO, self::LOG_CHANNEL);
    }

    /**
     * Bootstrap VirtueMart helper classes.
     *
     * @return  void
     */
    public function bootstrapVirtueMart(): void
    {
        $vmAdminPath = JPATH_ADMINISTRATOR . '/components/com_virtuemart';

        if (!class_exists('VmConfig')) {
            $configFile = $vmAdminPath . '/helpers/config.php';

            if (file_exists($configFile)) {
                require_once $configFile;
                \VmConfig::loadConfig();
            } else {
                Log::add('VirtueMart config helper not found at ' . $configFile, Log::WARNING, self::LOG_CHANNEL);
            }
        }

        if (!class_exists('VmModel')) {
            $modelFile = $vmAdminPath . '/helpers/vmmodel.php';

            if (file_exists($modelFile)) {
                require_once $modelFile;
            } else {
                Log::add('VirtueMart model helper not found at ' . $modelFile, Log::WARNING, self::LOG_CHANNEL);
            }
        }
    }

    /**
     * Retrieve the configured Printful API token.
     *
     * @return  string
     */
    public function getPrintfulToken(): string
    {
        return trim((string) $this->params->get('printful_token'));
    }

    /**
     * Get a configured synchronisation service instance.
     *
     * @return  PlgVmExtendedPrintfulSyncService
     */
    private function getSyncService(): PlgVmExtendedPrintfulSyncService
    {
        if ($this->syncService instanceof PlgVmExtendedPrintfulSyncService) {
            return $this->syncService;
        }

        $this->syncService = new PlgVmExtendedPrintfulSyncService($this, $this->params);

        return $this->syncService;
    }

    /**
     * Respond with JSON and proper HTTP status.
     *
     * @param   array  $payload  Response payload.
     * @param   int    $status   HTTP status code.
     *
     * @return  void
     */
    private function respondJson(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            Factory::getApplication()->close();
        } catch (\Throwable $throwable) {
            exit;
        }
    }
}
