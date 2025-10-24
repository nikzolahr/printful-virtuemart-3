<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Vmextended.Printful
 */

defined('_JEXEC') or die;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;
use Joomla\Registry\Registry;

class PlgVmExtendedPrintfulSyncException extends \RuntimeException
{
}

/**
 * Handles Printful → VirtueMart product synchronisation.
 */
class PlgVmExtendedPrintfulSyncService
{
    private const LOG_CHANNEL = 'plgVmExtendedPrintful';
    private const IMAGE_DIRECTORY = 'images/printful';

    /**
     * @var    plgVmExtendedPrintful
     */
    private $plugin;

    /**
     * @var    Registry
     */
    private $params;

    /**
     * @var    array|null  Cached request context for store API calls.
     */
    private $storeRequestContext;

    /**
     * @param   plgVmExtendedPrintful  $plugin  Parent plugin instance.
     * @param   Registry               $params  Plugin parameters.
     */
    public function __construct(plgVmExtendedPrintful $plugin, Registry $params)
    {
        $this->plugin = $plugin;
        $this->params = $params;
    }

    /**
     * Run the synchronisation and return aggregated statistics.
     *
     * @return  array{created:int,updated:int,skipped:int,errors:int,fetched:int,processed:int,skipSamples:array,errorSamples:array,dry_run:bool,tokenType:?string,endpoint:?string,httpStatus:?int,pfSample:array}
     */
    public function sync(): array
    {
        $this->plugin->bootstrapVirtueMart();

        $dryRun = $this->isDryRun();
        $this->assertAccountTokenConfiguration();
        $customFieldName = (string) $this->params->get('variant_customfield', 'printful_variant_id');
        $colorFieldName = (string) $this->params->get('color_customfield', 'printful_color');
        $sizeFieldName = (string) $this->params->get('size_customfield', 'printful_size');

        Log::add('Starting Printful product synchronisation (dry-run=' . ($dryRun ? 'yes' : 'no') . ').', Log::INFO, self::LOG_CHANNEL);

        $customFieldId = $this->ensureCustomField($customFieldName, $dryRun);
        $colorCustomFieldId = $this->ensureCustomField(
            $colorFieldName,
            $dryRun,
            'Printful colour attribute',
            false
        );
        $sizeCustomFieldId = $this->ensureCustomField(
            $sizeFieldName,
            $dryRun,
            'Printful size attribute',
            false
        );

        $diagnostics = [
            'fetched' => 0,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'dry_run' => $dryRun,
            'skipSamples' => [],
            'errorSamples' => [],
            'tokenType' => null,
            'endpoint' => null,
            'httpStatus' => null,
            'requestHeaders' => [],
            'pfSample' => [],
            'apiBase' => null,
        ];

        $filters = [
            'onlyActive' => (bool) $this->params->get('sync_only_active', 1),
            'onlyWarehouse' => (bool) $this->params->get('sync_only_warehouse', 0),
        ];

        foreach ($this->fetchPrintfulProducts($diagnostics) as $payload) {
            $product = $payload['product'];
            $variants = $payload['variants'];

            $parentMapping = $this->mapProduct($product);

            if ($parentMapping === null) {
                continue;
            }

            $parentProductId = $this->ensureParentProduct($parentMapping, $dryRun);

            foreach ($variants as $variant) {
                $variantId = (string) ($variant['id'] ?? $variant['variant_id'] ?? $variant['sync_variant_id'] ?? $variant['external_id'] ?? '');
                $diagnostics['processed']++;

                try {
                    $filterResult = $this->passesFilters($product, $variant, $filters);

                    if ($filterResult !== true) {
                        $this->skip($diagnostics, $variantId !== '' ? $variantId : 'unknown', $filterResult);

                        continue;
                    }

                    $mapping = $this->mapFields($product, $variant, $diagnostics);

                    if ($mapping === null) {
                        continue;
                    }

                    if (!isset($mapping['mpn']) || trim((string) $mapping['mpn']) === '') {
                        $mapping['mpn'] = $mapping['sku'];
                    }
                    $mapping['parentId'] = (int) ($parentProductId ?? 0);

                    if (!$dryRun && $mapping['parentId'] <= 0) {
                        $this->recordError($diagnostics, $mapping['variantId'], 'parent_product_missing');

                        continue;
                    }

                    $match = $this->matchExistingProduct($mapping, $variant, $customFieldId);

                    if ($match['ambiguous']) {
                        $this->skip($diagnostics, $mapping['variantId'], 'vm_match_ambiguous');

                        continue;
                    }

                    $existingProductId = $match['productId'];

                    if ($existingProductId !== null) {
                        $changeSet = $this->detectProductChanges(
                            $existingProductId,
                            $mapping,
                            $customFieldId,
                            $colorCustomFieldId,
                            $sizeCustomFieldId
                        );

                        if ($changeSet['hasChanges'] === false) {
                            $this->skip($diagnostics, $mapping['variantId'], 'vm_match_found_but_no_changes');

                            continue;
                        }
                    }

                    if ($dryRun) {
                        $diagnostics[$existingProductId === null ? 'created' : 'updated']++;

                        continue;
                    }

                    $action = $this->upsertVmProduct(
                        $mapping,
                        $product,
                        $variant,
                        $customFieldId,
                        $dryRun,
                        $existingProductId,
                        $colorCustomFieldId,
                        $sizeCustomFieldId
                    );

                    if (!isset($diagnostics[$action])) {
                        $diagnostics[$action] = 0;
                    }

                    $diagnostics[$action]++;

                    if ($action === 'errors') {
                        $this->recordError($diagnostics, $mapping['variantId'], 'persist_failed');
                    }
                } catch (\Throwable $throwable) {
                    $diagnostics['errors']++;
                    $this->recordError($diagnostics, $variantId !== '' ? $variantId : ($mapping['variantId'] ?? 'unknown'), $throwable->getMessage());
                    Log::add('Variant synchronisation failed: ' . $throwable->getMessage(), Log::ERROR, self::LOG_CHANNEL);
                }
            }
        }

        Log::add(
            sprintf(
                'Synchronisation finished. Fetched: %d, Processed: %d, Created: %d, Updated: %d, Skipped: %d, Errors: %d',
                $diagnostics['fetched'],
                $diagnostics['processed'],
                $diagnostics['created'],
                $diagnostics['updated'],
                $diagnostics['skipped'],
                $diagnostics['errors']
            ),
            Log::INFO,
            self::LOG_CHANNEL
        );

        if (!is_string($diagnostics['tokenType']) || $diagnostics['tokenType'] === '') {
            $diagnostics['tokenType'] = (bool) $this->params->get('use_account_token', 0) ? 'account' : 'store';
        }

        if (!is_string($diagnostics['endpoint']) || $diagnostics['endpoint'] === '') {
            $diagnostics['endpoint'] = '/store/products';
        }

        if (!is_string($diagnostics['apiBase']) || $diagnostics['apiBase'] === '') {
            $diagnostics['apiBase'] = 'v1';
        }

        if (!is_int($diagnostics['httpStatus'])) {
            $diagnostics['httpStatus'] = (int) ($diagnostics['httpStatus'] ?? 0);
        }

        if (!is_array($diagnostics['pfSample'])) {
            $diagnostics['pfSample'] = [];
        }

        if (!is_array($diagnostics['requestHeaders'])) {
            $diagnostics['requestHeaders'] = [];
        } else {
            $diagnostics['requestHeaders'] = array_values(array_map(
                static function ($value): string {
                    return (string) $value;
                },
                $diagnostics['requestHeaders']
            ));
        }

        return $diagnostics;
    }

    /**
     * Fetch all Printful products including variants.
     *
     * @return  iterable<int, array{product:array,variants:array<int,array>}>  Generator like list of product payloads.
     */
    private function fetchPrintfulProducts(array &$diagnostics): iterable
    {
        $limit = $this->getPageLimit();
        $offset = 0;
        $hasMore = true;
        $page = 0;
        $maxPages = $this->getMaxPages();

        while ($hasMore) {
            $page++;

            if ($page > $maxPages) {
                Log::add('Aborting product sync after reaching configured maximum pages (' . $maxPages . ').', Log::WARNING, self::LOG_CHANNEL);

                return;
            }

            try {
                $context = $this->getStoreRequestContext();
                $pageInfo = $this->fetchProductsFromPrintful($limit, $offset, $context, $page === 1);
            } catch (PlgVmExtendedPrintfulSyncException $exception) {
                throw $exception;
            } catch (\Throwable $throwable) {
                Log::add('Failed to fetch Printful store product list: ' . $throwable->getMessage(), Log::ERROR, self::LOG_CHANNEL);

                return;
            }

            $body = $pageInfo['body'] ?? [];
            $result = is_array($pageInfo['result'] ?? null) ? $pageInfo['result'] : $this->extractResultList($body);
            $paging = is_array($pageInfo['paging'] ?? null) ? $pageInfo['paging'] : ($body['paging'] ?? null);
            $count = is_array($result) ? count($result) : 0;
            $total = (int) ($paging['total'] ?? $count);

            if (isset($pageInfo['status'])) {
                $diagnostics['httpStatus'] = (int) $pageInfo['status'];
            }

            if ($diagnostics['tokenType'] === null && isset($pageInfo['tokenType'])) {
                $diagnostics['tokenType'] = is_string($pageInfo['tokenType']) ? $pageInfo['tokenType'] : null;
            }

            if ($diagnostics['endpoint'] === null && isset($pageInfo['endpoint'])) {
                $diagnostics['endpoint'] = is_string($pageInfo['endpoint']) ? $pageInfo['endpoint'] : null;
            }

            if ($diagnostics['apiBase'] === null && isset($pageInfo['apiBase'])) {
                $diagnostics['apiBase'] = is_string($pageInfo['apiBase']) ? $pageInfo['apiBase'] : null;
            }

            if (empty($diagnostics['requestHeaders']) && isset($pageInfo['requestHeaders'])) {
                $headers = $pageInfo['requestHeaders'];
                $diagnostics['requestHeaders'] = is_array($headers) ? array_values($headers) : [];
            }

            if ($page === 1) {
                $diagnostics['pfSample'] = $this->buildPrintfulSample(is_array($result) ? $result : []);

                if (!empty($diagnostics['pfSample'])) {
                    $firstItem = $diagnostics['pfSample'][0];
                    Log::add('[printful] firstItem=' . json_encode($firstItem), Log::INFO, self::LOG_CHANNEL);
                } else {
                    $message = 'Printful store products API returned no results';

                    if ($this->params->get('use_account_token')) {
                        $message .= '. Verify that X-PF-Store-Id is configured for the account token.';
                    }

                    Log::add($message . ' – ensure that store endpoints are used and products exist.', Log::WARNING, self::LOG_CHANNEL);
                }
            }

            $diagnostics['fetched'] += (int) ($pageInfo['fetched'] ?? $count);

            Log::add(
                sprintf('Fetched Printful store products page %d (limit=%d, offset=%d, count=%d, total=%d).', $page, $limit, $offset, $count, $total),
                Log::DEBUG,
                self::LOG_CHANNEL
            );

            if (empty($result)) {
                return;
            }

            foreach ($result as $summary) {
                $productId = (int) ($summary['id'] ?? $summary['product_id'] ?? $summary['sync_product_id'] ?? 0);

                if ($productId <= 0) {
                    Log::add('Encountered product entry without identifier in Printful list.', Log::WARNING, self::LOG_CHANNEL);
                    $this->skip($diagnostics, $summary['external_id'] ?? $summary['sync_product_id'] ?? 'unknown', 'api_result_item_invalid');
                    continue;
                }

                $details = $this->fetchPrintfulProductDetails($productId, $context);

                if ($details === null) {
                    continue;
                }

                yield $details;

                usleep(300000); // Respect Printful rate limits.
            }

            [$hasMore, $offset] = $this->resolveNextPage($body, $offset, $limit, $count);
        }
    }

    private function assertAccountTokenConfiguration(): void
    {
        if (!(bool) $this->params->get('use_account_token', 0)) {
            return;
        }

        $storeId = $this->getConfiguredStoreId();

        if ($storeId === '') {
            throw new PlgVmExtendedPrintfulSyncException(
                'Account token requires a Store-ID (X-PF-Store-Id). Please set the Printful Store-ID in plugin settings.',
                400
            );
        }
    }

    private function getConfiguredStoreId(): string
    {
        $storeId = trim((string) $this->params->get('store_id', ''));

        if ($storeId !== '') {
            return $storeId;
        }

        return trim((string) $this->params->get('printful_store_id', ''));
    }

    private function getApiBaseForStoreSync(): string
    {
        return 'https://api.printful.com';
    }

    private function getStoreRequestContext(): array
    {
        if (is_array($this->storeRequestContext)) {
            return $this->storeRequestContext;
        }

        $token = $this->plugin->getPrintfulToken();

        if ($token === '') {
            throw new PlgVmExtendedPrintfulSyncException('Printful API token missing.', 400);
        }

        $tokenType = (bool) $this->params->get('use_account_token', 0) ? 'account' : 'store';
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        $language = trim((string) $this->params->get('printful_language'));

        if ($language !== '') {
            $headers[] = 'X-PF-Language: ' . $language;
        }

        if ($tokenType === 'account') {
            $storeId = $this->getConfiguredStoreId();

            if ($storeId === '') {
                throw new PlgVmExtendedPrintfulSyncException(
                    'Account token requires a Store-ID (X-PF-Store-Id). Please set the Printful Store-ID in plugin settings.',
                    400
                );
            }

            $headers[] = 'X-PF-Store-Id: ' . $storeId;
        }

        $this->storeRequestContext = [
            'tokenType' => $tokenType,
            'headers' => $headers,
            'httpHeaders' => $this->buildHeaderMap($headers),
            'sanitisedHeaders' => $this->sanitizeHeadersForLog($headers),
        ];

        return $this->storeRequestContext;
    }

    private function buildHeaderMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $header) {
            if (!is_string($header) || $header === '') {
                continue;
            }

            $parts = explode(':', $header, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);

            if ($name === '') {
                continue;
            }

            $map[$name] = ltrim($parts[1]);
        }

        return $map;
    }

    private function performStoreRequest(string $endpoint, array $query, array $context, bool $logRequest = false): array
    {
        $base = rtrim($this->getApiBaseForStoreSync(), '/');
        $url = $base . $endpoint;

        if (!empty($query)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        if ($logRequest) {
            $limit = $query['limit'] ?? null;
            $offset = $query['offset'] ?? null;
            Log::add(
                '[printful] tokenType=' . $context['tokenType'] . ' endpoint=' . $endpoint . ' apiBase=v1'
                . ($limit !== null ? ' limit=' . $limit : '')
                . ($offset !== null ? ' offset=' . $offset : ''),
                Log::INFO,
                self::LOG_CHANNEL
            );
            Log::add(
                'Calling ' . $url . ' with headers=' . json_encode($context['sanitisedHeaders'] ?? []),
                Log::DEBUG,
                self::LOG_CHANNEL
            );
        }

        $maxAttempts = max(1, (int) $this->params->get('api_retry_attempts', 3));
        $attempt = 0;
        $delay = 1;
        $response = null;
        $headers = (array) ($context['httpHeaders'] ?? []);

        do {
            $attempt++;

            try {
                $response = $this->httpGet($url, $headers, 20);
            } catch (\Throwable $throwable) {
                Log::add('HTTP request to Printful failed on attempt ' . $attempt . ': ' . $throwable->getMessage(), Log::WARNING, self::LOG_CHANNEL);

                if ($attempt >= $maxAttempts) {
                    throw new PlgVmExtendedPrintfulSyncException('HTTP request to Printful failed: ' . $throwable->getMessage(), 502, $throwable);
                }

                sleep($delay);
                $delay = min($delay * 2, 30);

                continue;
            }

            $status = (int) ($response['code'] ?? 0);

            if ($status === 429 && $attempt < $maxAttempts) {
                $retryAfter = $this->resolveRetryAfterFromHeaders($response['headers'] ?? []);
                $sleepFor = $retryAfter ?? $delay;
                $sleepFor = $sleepFor > 0 ? $sleepFor : $delay;
                Log::add('Printful API rate limited request (HTTP 429). Retrying after ' . $sleepFor . ' second(s).', Log::WARNING, self::LOG_CHANNEL);
                sleep($sleepFor);
                $delay = min($delay * 2, 60);

                continue;
            }

            if ($status >= 500 && $status < 600 && $attempt < $maxAttempts) {
                Log::add('Printful API temporary error (HTTP ' . $status . '), retrying.', Log::WARNING, self::LOG_CHANNEL);
                sleep($delay);
                $delay = min($delay * 2, 60);

                continue;
            }

            break;
        } while ($attempt < $maxAttempts);

        if ($response === null) {
            throw new PlgVmExtendedPrintfulSyncException('No response from Printful API.', 502);
        }

        $status = (int) ($response['code'] ?? 0);
        $rawBody = is_string($response['body'] ?? null) ? $response['body'] : json_encode($response['body']);
        $body = [];

        if (is_string($rawBody) && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $body = $decoded;
            } else {
                Log::add('Failed to decode Printful store response JSON: ' . json_last_error_msg(), Log::WARNING, self::LOG_CHANNEL);
            }
        }

        if ($status >= 400) {
            $snippet = is_string($rawBody) ? substr($rawBody, 0, 300) : json_encode($body);
            $level = $status >= 500 ? Log::ERROR : Log::WARNING;
            Log::add('[printful] http_error=' . $status . ' body=' . $snippet, $level, self::LOG_CHANNEL);

            throw new PlgVmExtendedPrintfulSyncException('Printful store request failed with HTTP ' . $status . '.', $status);
        }

        return [
            'status' => $status,
            'body' => $body,
            'headers' => $response['headers'] ?? [],
        ];
    }

    private function httpGet(string $url, array $headers, int $timeout): array
    {
        $options = new Registry([
            'timeout' => $timeout,
        ]);

        try {
            $client = HttpFactory::getHttp($options);
            $response = $client->get($url, $headers, $timeout);

            return [
                'code' => (int) ($response->code ?? 0),
                'body' => is_string($response->body ?? null) ? $response->body : json_encode($response->body),
                'headers' => $this->normaliseResponseHeaders($response->headers ?? []),
            ];
        } catch (\Throwable $throwable) {
            Log::add('Joomla HTTP client GET failed: ' . $throwable->getMessage(), Log::WARNING, self::LOG_CHANNEL);

            $fallback = $this->httpGetWithFallback($url, $headers, $timeout, $throwable);

            if ($fallback === null) {
                throw $throwable;
            }

            return $fallback;
        }
    }

    private function httpGetWithFallback(string $url, array $headers, int $timeout, ?\Throwable $previous = null): ?array
    {
        $headerLines = $this->convertHeaderMapToLines($headers);

        if (function_exists('curl_init')) {
            $curl = curl_init($url);

            if ($curl !== false) {
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_HTTPHEADER => $headerLines,
                    CURLOPT_HEADER => true,
                ]);

                $result = curl_exec($curl);

                if ($result !== false) {
                    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
                    $rawHeaders = substr($result, 0, $headerSize);
                    $body = substr($result, $headerSize);
                    curl_close($curl);

                    return [
                        'code' => $status,
                        'body' => (string) $body,
                        'headers' => $this->parseRawHeaders($rawHeaders),
                    ];
                }

                $error = curl_error($curl);
                curl_close($curl);
                Log::add('cURL fallback GET failed: ' . $error, Log::WARNING, self::LOG_CHANNEL);
            }
        }

        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headerLines),
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                ],
            ]);

            $body = @file_get_contents($url, false, $context);

            if ($body !== false) {
                $status = 0;
                $responseHeaders = [];

                if (isset($http_response_header) && is_array($http_response_header)) {
                    $responseHeaders = $this->parseHeaderLines($http_response_header);
                    foreach ($http_response_header as $line) {
                        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                            $status = (int) $matches[1];
                        }
                    }
                }

                return [
                    'code' => $status,
                    'body' => (string) $body,
                    'headers' => $responseHeaders,
                ];
            }

            Log::add('stream fallback GET failed for ' . $url, Log::WARNING, self::LOG_CHANNEL);
        }

        if ($previous !== null) {
            throw new PlgVmExtendedPrintfulSyncException('HTTP GET fallback failed: ' . $previous->getMessage(), 500, $previous);
        }

        return null;
    }

    private function convertHeaderMapToLines(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $item = trim((string) $item);
                    if ($item !== '') {
                        $lines[] = $name . ': ' . $item;
                    }
                }
            } else {
                $value = trim((string) $value);
                if ($value !== '') {
                    $lines[] = $name . ': ' . $value;
                }
            }
        }

        return $lines;
    }

    private function normaliseResponseHeaders($headers): array
    {
        if (is_object($headers)) {
            if (method_exists($headers, 'toArray')) {
                return (array) $headers->toArray();
            }

            if ($headers instanceof \Traversable) {
                return iterator_to_array($headers);
            }
        }

        if (is_array($headers)) {
            return $headers;
        }

        return [];
    }

    private function parseRawHeaders(string $rawHeaders): array
    {
        $lines = preg_split('/\r?\n/', trim($rawHeaders));

        if ($lines === false) {
            return [];
        }

        return $this->parseHeaderLines($lines);
    }

    private function parseHeaderLines(array $lines): array
    {
        $headers = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            if (stripos($line, 'HTTP/') === 0) {
                $headers = [];
                continue;
            }

            $parts = explode(':', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);

            if ($name === '') {
                continue;
            }

            if (isset($headers[$name])) {
                if (is_array($headers[$name])) {
                    $headers[$name][] = $value;
                } else {
                    $headers[$name] = [$headers[$name], $value];
                }
            } else {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function resolveRetryAfterFromHeaders($headers): ?int
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
     * Load Printful product details.
     *
     * @param   int  $productId  Product identifier.
     *
     * @return  array{product:array,variants:array<int,array>}|null
     */
    private function fetchPrintfulProductDetails(int $productId, array $context): ?array
    {
        try {
            $productResponse = $this->performStoreRequest('/store/products/' . $productId, [], $context);
        } catch (PlgVmExtendedPrintfulSyncException $exception) {
            Log::add('Failed to fetch store product details for ' . $productId . ': ' . $exception->getMessage(), Log::ERROR, self::LOG_CHANNEL);

            return null;
        } catch (\Throwable $throwable) {
            Log::add('Failed to fetch store product details for ' . $productId . ': ' . $throwable->getMessage(), Log::ERROR, self::LOG_CHANNEL);

            return null;
        }

        if ((int) ($productResponse['status'] ?? 0) !== 200) {
            Log::add('Failed to fetch details for Printful store product ' . $productId . '.', Log::ERROR, self::LOG_CHANNEL);

            return null;
        }

        $productBody = $productResponse['body'] ?? [];
        $productResult = $this->extractResultObject($productBody);

        if (!is_array($productResult)) {
            Log::add('Unexpected product detail payload for product ' . $productId . '.', Log::ERROR, self::LOG_CHANNEL);

            return null;
        }

        $product = $productResult['sync_product'] ?? $productResult['product'] ?? $productResult;
        $variants = [];

        if (isset($productResult['sync_variants']) && is_array($productResult['sync_variants'])) {
            $variants = $productResult['sync_variants'];
        } elseif (isset($productResult['variants']) && is_array($productResult['variants'])) {
            $variants = $productResult['variants'];
        }

        $variants = array_values(array_filter(is_array($variants) ? $variants : [], 'is_array'));

        return [
            'product' => is_array($product) ? $product : [],
            'variants' => $variants,
        ];
    }

    /**
     * Request a page of Printful store products.
     *
     * @param   int    $limit       Maximum number of products per page.
     * @param   int    $offset      Offset for pagination.
     * @param   array  $context     Store request context including headers/token info.
     * @param   bool   $logRequest  Whether to log request metadata (first page).
     *
     * @return  array{body:array,tokenType:string,endpoint:string,apiBase:string,fetched:int,status:int,paging:?array,result:array,requestHeaders:array}
     */
    private function fetchProductsFromPrintful(int $limit, int $offset, array $context, bool $logRequest): array
    {
        $query = [
            'limit' => max(1, $limit),
            'offset' => max(0, $offset),
        ];

        $endpoint = '/store/products';
        $response = $this->performStoreRequest($endpoint, $query, $context, $logRequest);
        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $result = $this->extractResultList($body);
        $paging = is_array($body['paging'] ?? null) ? $body['paging'] : null;
        $count = is_array($result) ? count($result) : 0;
        $total = (int) ($paging['total'] ?? $count);
        $status = (int) ($response['status'] ?? 0);

        Log::add('[printful] http=' . $status . ' pageFetched=' . $count . ' totalKnown=' . $total . ' apiBase=v1', Log::INFO, self::LOG_CHANNEL);

        return [
            'body' => $body,
            'tokenType' => $context['tokenType'] ?? 'store',
            'endpoint' => $endpoint,
            'apiBase' => 'v1',
            'fetched' => $count,
            'status' => $status,
            'paging' => $paging,
            'result' => is_array($result) ? $result : [],
            'requestHeaders' => $context['sanitisedHeaders'] ?? [],
        ];
    }

    /**
     * Perform a lightweight connectivity check against the Printful products endpoint.
     *
     * @return  array
     */
    public function ping(): array
    {
        $this->plugin->bootstrapVirtueMart();
        $this->assertAccountTokenConfiguration();

        try {
            $context = $this->getStoreRequestContext();
            $pageInfo = $this->fetchProductsFromPrintful(1, 0, $context, true);
        } catch (PlgVmExtendedPrintfulSyncException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new PlgVmExtendedPrintfulSyncException('Ping failed: ' . $throwable->getMessage(), 500, $throwable);
        }

        $sample = $this->buildPrintfulSample($pageInfo['result'] ?? []);

        return [
            'status' => 'ok',
            'tokenType' => $pageInfo['tokenType'] ?? ((bool) $this->params->get('use_account_token', 0) ? 'account' : 'store'),
            'endpoint' => $pageInfo['endpoint'] ?? '/store/products',
            'apiBase' => $pageInfo['apiBase'] ?? 'v1',
            'httpStatus' => (int) ($pageInfo['status'] ?? 0),
            'requestHeaders' => array_values(is_array($pageInfo['requestHeaders'] ?? null) ? $pageInfo['requestHeaders'] : []),
            'pfSample' => array_slice($sample, 0, 3),
            'fetched' => (int) ($pageInfo['fetched'] ?? 0),
        ];
    }

    /**
     * Hide sensitive headers from debug output.
     *
     * @param   array  $headers  Header definitions (either numeric array of header lines or associative map).
     *
     * @return  array<int,string>  Sanitised header lines ready for logging/diagnostics.
     */
    private function sanitizeHeadersForLog(array $headers): array
    {
        $sanitised = [];

        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $line = trim((string) $value);

                if ($line === '') {
                    continue;
                }

                if (stripos($line, 'Authorization:') === 0) {
                    $sanitised[] = 'Authorization: Bearer ***';
                    continue;
                }

                $sanitised[] = $line;

                continue;
            }

            $headerName = (string) $name;
            $headerValue = is_array($value) ? json_encode($value) : (string) $value;

            if (strcasecmp($headerName, 'Authorization') === 0) {
                $sanitised[] = 'Authorization: Bearer ***';
                continue;
            }

            $sanitised[] = $headerName . ': ' . $headerValue;
        }

        return $sanitised;
    }

    /**
     * Build a compact debug sample of the Printful response payload.
     *
     * @param   array<int,array>  $items  Raw Printful result list.
     *
     * @return  array<int,array<string,mixed>>
     */
    private function buildPrintfulSample(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $items = array_values(array_filter($items, 'is_array'));

        $samples = array_slice($items, 0, 3);

        return array_map(
            static function (array $item): array {
                $variantsList = [];

                if (isset($item['variants']) && is_array($item['variants'])) {
                    $variantsList = $item['variants'];
                } elseif (isset($item['sync_variants']) && is_array($item['sync_variants'])) {
                    $variantsList = $item['sync_variants'];
                }

                $variantCount = null;

                if (is_array($variantsList)) {
                    $variantCount = count($variantsList);
                } elseif (isset($item['variant_count']) && is_numeric($item['variant_count'])) {
                    $variantCount = (int) $item['variant_count'];
                }

                $syncedCount = null;

                if (isset($item['synced']) && $item['synced'] !== null) {
                    $syncedCount = is_numeric($item['synced']) ? (int) $item['synced'] : null;
                } elseif ($variantCount !== null) {
                    $syncedCount = $variantCount;
                }

                return array_filter(
                    [
                        'id' => $item['id'] ?? $item['product_id'] ?? $item['sync_product_id'] ?? null,
                        'external_id' => $item['external_id'] ?? null,
                        'name' => $item['name'] ?? null,
                        'variants' => $variantCount,
                        'synced' => $syncedCount,
                    ],
                    static function ($value) {
                        return $value !== null;
                    }
                );
            },
            $samples
        );
    }

    /**
     * Determine pagination limit for Printful catalog requests.
     *
     * @return  int
     */
    private function getPageLimit(): int
    {
        $limit = (int) $this->params->get('api_page_limit', 100);

        if ($limit < 1) {
            $limit = 1;
        }

        if ($limit > 200) {
            $limit = 200;
        }

        return $limit;
    }

    /**
     * Determine the maximum number of pages to fetch from the Printful API.
     *
     * @return  int
     */
    private function getMaxPages(): int
    {
        $maxPages = (int) $this->params->get('api_max_pages', 50);

        if ($maxPages < 1) {
            $maxPages = 1;
        }

        if ($maxPages > 500) {
            $maxPages = 500;
        }

        return $maxPages;
    }

    /**
     * Extract result list from Printful API payload.
     *
     * @param   mixed  $body  Response body.
     *
     * @return  array
     */
    private function extractResultList($body): array
    {
        if (!is_array($body)) {
            return [];
        }

        if (isset($body['result']) && is_array($body['result'])) {
            return $body['result'];
        }

        if (isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        return [];
    }

    /**
     * Extract result object from Printful API payload.
     *
     * @param   mixed  $body  Response body.
     *
     * @return  array|null
     */
    private function extractResultObject($body): ?array
    {
        if (!is_array($body)) {
            return null;
        }

        if (isset($body['result']) && is_array($body['result'])) {
            return $body['result'];
        }

        if (isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        return $body;
    }

    /**
     * Determine pagination continuation state from Printful payload.
     *
     * @param   array  $body        Response body.
     * @param   int    $current     Current offset.
     * @param   int    $limit       Configured limit.
     * @param   int    $count       Number of records processed.
     *
     * @return  array{0:bool,1:int}
     */
    private function resolveNextPage($body, int $current, int $limit, int $count): array
    {
        if (!is_array($body)) {
            return [false, $current];
        }

        $paging = $body['paging'] ?? [];
        $total = (int) ($paging['total'] ?? 0);
        $offset = (int) ($paging['offset'] ?? $current);
        $limitFromResponse = (int) ($paging['limit'] ?? $limit);

        if ($limitFromResponse > 0) {
            $limit = $limitFromResponse;
        }

        if (isset($body['_links']['next']['href'])) {
            $next = $body['_links']['next']['href'];
            $parsed = parse_url($next);

            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $queryParams);

                if (isset($queryParams['offset'])) {
                    $nextOffset = (int) $queryParams['offset'];

                    return [true, $nextOffset];
                }
            }
        }

        $nextOffset = $offset + $limit;

        if ($total > 0 && $nextOffset >= $total) {
            return [false, $nextOffset];
        }

        if ($count < $limit) {
            return [false, $nextOffset];
        }

        return [true, $nextOffset];
    }

    /**
     * Map Printful product payload to VirtueMart parent structure.
     *
     * @param   array  $pfProduct  Printful product payload.
     *
     * @return  array|null
     */
    private function mapProduct(array $pfProduct): ?array
    {
        $productId = (int) ($pfProduct['id'] ?? $pfProduct['product_id'] ?? 0);
        $sku = trim((string) ($pfProduct['sku'] ?? $pfProduct['external_id'] ?? ''));

        if ($sku === '') {
            $reference = $productId > 0 ? (string) $productId : (string) ($pfProduct['external_id'] ?? 'unknown');
            Log::add('Skipping Printful product ' . $reference . ' without SKU.', Log::WARNING, self::LOG_CHANNEL);

            return null;
        }

        $name = trim((string) ($pfProduct['name'] ?? ''));

        if ($name === '') {
            $name = 'Printful ' . $sku;
        }

        $description = (string) ($pfProduct['description'] ?? '');

        return [
            'productId' => $productId,
            'sku' => $sku,
            'name' => $name,
            'description' => $description,
            'externalId' => trim((string) ($pfProduct['external_id'] ?? '')),
            'slugReference' => $sku,
            'mpn' => $sku,
        ];
    }

    /**
     * Map Printful variant payload to VirtueMart compatible structure.
     *
     * @param   array  $pfProduct     Printful product payload.
     * @param   array  $pfVariant     Printful variant payload.
     * @param   array  $diagnostics   Diagnostics output reference.
     *
     * @return  array|null
     */
    private function mapFields(array $pfProduct, array $pfVariant, array &$diagnostics): ?array
    {
        $variantDetails = is_array($pfVariant['variant'] ?? null) ? $pfVariant['variant'] : [];
        $variantId = (string) ($pfVariant['id'] ?? $pfVariant['variant_id'] ?? $pfVariant['sync_variant_id'] ?? $variantDetails['id'] ?? '');

        if ($variantId === '') {
            $this->skip($diagnostics, 'unknown', 'api_result_item_invalid');
            Log::add('Skipping variant without variant_id.', Log::WARNING, self::LOG_CHANNEL);

            return null;
        }

        $name = trim((string) ($pfVariant['name'] ?? $variantDetails['name'] ?? $pfProduct['name'] ?? ''));

        if ($name === '') {
            $this->skip($diagnostics, $variantId, 'api_result_item_invalid');
            Log::add('Skipping variant ' . $variantId . ' without name.', Log::WARNING, self::LOG_CHANNEL);

            return null;
        }

        $description = (string) ($pfProduct['description'] ?? '');

        if ($description === '') {
            $description = (string) ($pfVariant['description'] ?? $variantDetails['description'] ?? '');
        }

        $externalId = trim((string) ($pfVariant['external_id'] ?? $variantDetails['external_id'] ?? ''));

        if ($externalId === '') {
            $this->skip($diagnostics, $variantId, 'missing_external_id');
            Log::add('Variant ' . $variantId . ' missing external_id, skipping.', Log::WARNING, self::LOG_CHANNEL);

            return null;
        }

        $sku = trim((string) ($pfVariant['sku'] ?? $variantDetails['sku'] ?? ''));

        if ($sku === '') {
            $sku = $externalId;
        }

        if ($sku === '') {
            $this->skip($diagnostics, $variantId, 'missing_sku');
            Log::add('Variant ' . $variantId . ' missing SKU, skipping.', Log::WARNING, self::LOG_CHANNEL);

            return null;
        }

        $priceRaw = $pfVariant['retail_price'] ?? $pfVariant['price'] ?? $variantDetails['retail_price'] ?? $variantDetails['price'] ?? 0.0;
        $price = (float) $priceRaw;

        $markup = (float) $this->params->get('price_markup_percent', 0);

        if ($markup !== 0.0) {
            $price = $price * (1 + ($markup / 100));
        }

        $price = round($price, 2);

        if ($price <= 0) {
            $this->skip($diagnostics, $variantId, 'api_result_item_invalid');
            Log::add('Variant ' . $variantId . ' has no valid price, skipping.', Log::WARNING, self::LOG_CHANNEL);

            return null;
        }

        $color = trim((string) ($pfVariant['color'] ?? $variantDetails['color'] ?? ''));
        $size = trim((string) ($pfVariant['size'] ?? $variantDetails['size'] ?? ''));

        $imageUrls = [];
        $files = $pfVariant['files'] ?? $variantDetails['files'] ?? [];

        if (is_array($files)) {
            foreach ($files as $file) {
                if (!is_array($file)) {
                    continue;
                }

                $url = trim((string) ($file['preview_url'] ?? $file['thumbnail_url'] ?? $file['url'] ?? ''));

                if ($url !== '') {
                    $imageUrls[] = $url;
                }
            }
        }

        if (empty($imageUrls)) {
            $thumbnail = trim((string) ($pfProduct['thumbnail_url'] ?? ''));

            if ($thumbnail !== '') {
                $imageUrls[] = $thumbnail;
            }
        }

        return [
            'variantId' => $variantId,
            'productId' => (int) ($pfProduct['id'] ?? $pfProduct['product_id'] ?? 0),
            'name' => $name,
            'description' => $description,
            'sku' => $externalId,
            'price' => $price,
            'images' => $imageUrls,
            'externalId' => $externalId,
            'mpn' => $externalId,
            'attributes' => [
                'color' => $color,
                'size' => $size,
            ],
            'slugReference' => $variantId,
        ];
    }

    /**
     * Determine whether a variant passes configured filters.
     *
     * @param   array  $product  Product payload.
     * @param   array  $variant  Variant payload.
     * @param   array  $filters  Configured filters.
     *
     * @return  bool|string  True if allowed, otherwise reason identifier.
     */
    private function passesFilters(array $product, array $variant, array $filters)
    {
        $variantDetails = is_array($variant['variant'] ?? null) ? $variant['variant'] : [];

        if (!empty($filters['onlyActive'])) {
            $isActive = true;

            if (isset($variant['sync_status'])) {
                $statusValue = $variant['sync_status'];

                if (is_numeric($statusValue)) {
                    $isActive = (int) $statusValue === 1;
                } else {
                    $statusString = strtolower((string) $statusValue);
                    $isActive = in_array($statusString, ['active', 'synced', 'enabled'], true);
                }
            } elseif (isset($variant['is_active'])) {
                $isActive = (bool) $variant['is_active'];
            } elseif (isset($variant['synced'])) {
                $isActive = (bool) $variant['synced'];
            } elseif (isset($variantDetails['is_visible'])) {
                $isActive = (bool) $variantDetails['is_visible'];
            } elseif (isset($variantDetails['availability_status'])) {
                $statusString = strtolower((string) $variantDetails['availability_status']);
                $isActive = !in_array($statusString, ['inactive', 'disabled'], true);
            }

            if (!$isActive) {
                return 'filtered_by_status_not_active';
            }
        }

        if (!empty($filters['onlyWarehouse'])) {
            $isWarehouse = (bool) ($variant['is_warehouse_product'] ?? $variantDetails['is_warehouse_product'] ?? $variantDetails['warehouse_product'] ?? false);

            if (!$isWarehouse) {
                return 'filtered_by_warehouse_only';
            }
        }

        return true;
    }

    /**
     * Match a VirtueMart product for the given Printful variant.
     *
     * @param   array  $mapping         Prepared mapping data.
     * @param   array  $pfVariant       Variant payload.
     * @param   int    $customFieldId   Custom field identifier storing the variant ID.
     *
     * @return  array{productId:?int,ambiguous:bool}
     */
    private function matchExistingProduct(array $mapping, array $pfVariant, int $customFieldId): array
    {
        $externalId = trim((string) ($mapping['externalId'] ?? ''));

        if ($externalId !== '') {
            $matches = $this->findProductIdsByExternalReference($externalId);

            if (count($matches) === 1) {
                return ['productId' => (int) $matches[0], 'ambiguous' => false];
            }

            if (count($matches) > 1) {
                return ['productId' => null, 'ambiguous' => true];
            }
        }

        $variantId = $mapping['variantId'];
        $byVariantId = $this->findProductIdByVariantId($variantId, $customFieldId);

        if ($byVariantId !== null) {
            return ['productId' => $byVariantId, 'ambiguous' => false];
        }

        $sku = trim((string) ($mapping['sku'] ?? ''));

        if ($sku !== '') {
            $matches = $this->findProductIdsBySku($sku);

            if (count($matches) === 1) {
                return ['productId' => (int) $matches[0], 'ambiguous' => false];
            }

            if (count($matches) > 1) {
                return ['productId' => null, 'ambiguous' => true];
            }
        }

        $variantDetails = is_array($pfVariant['variant'] ?? null) ? $pfVariant['variant'] : [];
        $ean = trim((string) ($pfVariant['ean'] ?? $pfVariant['upc'] ?? $pfVariant['barcode'] ?? $variantDetails['ean'] ?? $variantDetails['upc'] ?? ''));

        if ($ean !== '') {
            $matches = $this->findProductIdsByGtin($ean);

            if (count($matches) === 1) {
                return ['productId' => (int) $matches[0], 'ambiguous' => false];
            }

            if (count($matches) > 1) {
                return ['productId' => null, 'ambiguous' => true];
            }
        }

        return ['productId' => null, 'ambiguous' => false];
    }

    /**
     * Determine whether updating the given product would make changes.
     *
     * @param   int        $productId          VirtueMart product ID.
     * @param   array      $mapping            Prepared mapping data.
     * @param   int        $customFieldId      Custom field identifier.
     * @param   int|null   $colorFieldId       Colour custom field identifier.
     * @param   int|null   $sizeFieldId        Size custom field identifier.
     *
     * @return  array{hasChanges:bool,fields:array}
     */
    private function detectProductChanges(int $productId, array $mapping, int $customFieldId, ?int $colorFieldId = null, ?int $sizeFieldId = null): array
    {
        $db = Factory::getDbo();
        $changes = [];

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('product_sku'),
                $db->quoteName('product_in_stock'),
                $db->quoteName('product_parent_id'),
                $db->quoteName('product_mpn'),
                $db->quoteName('product_gtin'),
            ])
            ->from($db->quoteName('#__virtuemart_products'))
            ->where($db->quoteName('virtuemart_product_id') . ' = ' . (int) $productId);
        $db->setQuery($query);
        $productRow = $db->loadAssoc();

        if (!$productRow) {
            return ['hasChanges' => true, 'fields' => ['missing_product']];
        }

        if (trim((string) ($productRow['product_sku'] ?? '')) !== $mapping['sku']) {
            $changes[] = 'sku';
        }

        if ((int) ($productRow['product_in_stock'] ?? 0) !== 9999) {
            $changes[] = 'stock';
        }

        if ((int) ($productRow['product_parent_id'] ?? 0) !== (int) ($mapping['parentId'] ?? 0)) {
            $changes[] = 'parent';
        }

        if (trim((string) ($productRow['product_mpn'] ?? '')) !== (string) ($mapping['mpn'] ?? '')) {
            $changes[] = 'mpn';
        }

        $expectedExternal = trim((string) ($mapping['externalId'] ?? ''));

        if ($expectedExternal !== '' && trim((string) ($productRow['product_gtin'] ?? '')) !== $expectedExternal) {
            $changes[] = 'external_reference';
        }

        $langTable = $this->getProductLanguageTableName();

        if ($langTable !== null) {
            $query = $db->getQuery(true)
                ->select([$db->quoteName('product_name'), $db->quoteName('product_desc')])
                ->from($db->quoteName($langTable))
                ->where($db->quoteName('virtuemart_product_id') . ' = ' . (int) $productId);
            $db->setQuery($query, 0, 1);
            $langRow = $db->loadAssoc();

            if (!$langRow) {
                $changes[] = 'name';
                $changes[] = 'description';
            } else {
                if (trim((string) ($langRow['product_name'] ?? '')) !== $mapping['name']) {
                    $changes[] = 'name';
                }

                if (trim((string) ($langRow['product_desc'] ?? '')) !== $mapping['description']) {
                    $changes[] = 'description';
                }
            }
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName('product_price'))
            ->from($db->quoteName('#__virtuemart_product_prices'))
            ->where($db->quoteName('virtuemart_product_id') . ' = ' . (int) $productId)
            ->order($db->quoteName('virtuemart_product_price_id') . ' ASC');
        $db->setQuery($query, 0, 1);
        $priceRow = $db->loadAssoc();

        if (!$priceRow) {
            $changes[] = 'price';
        } else {
            $storedPrice = round((float) ($priceRow['product_price'] ?? 0), 2);

            if (abs($storedPrice - $mapping['price']) > 0.009) {
                $changes[] = 'price';
            }
        }

        if ($customFieldId > 0) {
            $query = $db->getQuery(true)
                ->select($db->quoteName('customfield_value'))
                ->from($db->quoteName('#__virtuemart_product_customfields'))
                ->where($db->quoteName('virtuemart_product_id') . ' = ' . (int) $productId)
                ->where($db->quoteName('virtuemart_custom_id') . ' = ' . (int) $customFieldId);
            $db->setQuery($query, 0, 1);
            $customValue = $db->loadResult();

            if ((string) $customValue !== (string) $mapping['variantId']) {
                $changes[] = 'customfield';
            }
        }

        $colorValue = trim((string) ($mapping['attributes']['color'] ?? ''));

        if ($colorFieldId > 0 && $colorValue !== '') {
            $existingColor = $this->getCustomFieldValue($productId, $colorFieldId);

            if ((string) $existingColor !== $colorValue) {
                $changes[] = 'color_customfield';
            }
        }

        $sizeValue = trim((string) ($mapping['attributes']['size'] ?? ''));

        if ($sizeFieldId > 0 && $sizeValue !== '') {
            $existingSize = $this->getCustomFieldValue($productId, $sizeFieldId);

            if ((string) $existingSize !== $sizeValue) {
                $changes[] = 'size_customfield';
            }
        }

        $existingHashes = $this->getExistingImageHashes($productId);
        $missingImages = false;

        foreach ($mapping['images'] as $url) {
            $hash = md5($url);

            if (!in_array($hash, $existingHashes, true)) {
                $missingImages = true;
                break;
            }
        }

        if ($missingImages) {
            $changes[] = 'images';
        }

        $changes = array_values(array_unique($changes));

        return ['hasChanges' => !empty($changes), 'fields' => $changes];
    }

    /**
     * Record a skipped variant with reason.
     *
     * @param   array   $diagnostics  Diagnostics array.
     * @param   mixed   $idOrSku      Identifier or reference for the skipped item.
     * @param   string  $reason       Reason identifier.
     *
     * @return  void
     */
    private function skip(array &$diagnostics, $idOrSku, string $reason): void
    {
        if (!isset($diagnostics['skipped'])) {
            $diagnostics['skipped'] = 0;
        }

        $diagnostics['skipped']++;

        if (!isset($diagnostics['skipSamples']) || !is_array($diagnostics['skipSamples'])) {
            $diagnostics['skipSamples'] = [];
        }

        if (count($diagnostics['skipSamples']) < 10) {
            $diagnostics['skipSamples'][] = [
                'ref' => (string) $idOrSku,
                'reason' => $reason,
            ];
        }
    }

    /**
     * Record an error sample.
     *
     * @param   array   $diagnostics  Diagnostics array.
     * @param   string  $id           Identifier of the failing item.
     * @param   string  $message      Error message.
     *
     * @return  void
     */
    private function recordError(array &$diagnostics, string $id, string $message): void
    {
        if (!isset($diagnostics['errorSamples']) || !is_array($diagnostics['errorSamples'])) {
            $diagnostics['errorSamples'] = [];
        }

        if (count($diagnostics['errorSamples']) >= 5) {
            return;
        }

        $diagnostics['errorSamples'][] = ['id' => $id, 'message' => $message];
    }

    /**
     * Locate products by Printful external reference stored in VirtueMart.
     *
     * @param   string  $externalId  External reference value.
     *
     * @return  array<int>
     */
    private function findProductIdsByExternalReference(string $externalId): array
    {
        if ($externalId === '') {
            return [];
        }

        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select($db->quoteName('virtuemart_product_id'))
            ->from($db->quoteName('#__virtuemart_products'))
            ->where('(' . $db->quoteName('product_mpn') . ' = ' . $db->quote($externalId) . ' OR '
                . $db->quoteName('product_gtin') . ' = ' . $db->quote($externalId) . ')')
            ->where($db->quoteName('product_parent_id') . ' <> 0');
        $db->setQuery($query);

        $ids = $db->loadColumn();

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * Locate products by SKU.
     *
     * @param   string  $sku  SKU value.
     *
     * @return  array<int>
     */
    private function findProductIdsBySku(string $sku): array
    {
        if ($sku === '') {
            return [];
        }

        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select($db->quoteName('virtuemart_product_id'))
            ->from($db->quoteName('#__virtuemart_products'))
            ->where($db->quoteName('product_sku') . ' = ' . $db->quote($sku));
        $db->setQuery($query);

        $ids = $db->loadColumn();

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * Locate products by GTIN/EAN/UPC.
     *
     * @param   string  $gtin  GTIN value.
     *
     * @return  array<int>
     */
    private function findProductIdsByGtin(string $gtin): array
    {
        if ($gtin === '') {
            return [];
        }

        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select($db->quoteName('virtuemart_product_id'))
            ->from($db->quoteName('#__virtuemart_products'))
            ->where($db->quoteName('product_gtin') . ' = ' . $db->quote($gtin));
        $db->setQuery($query);

        $ids = $db->loadColumn();

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * Ensure the VirtueMart parent product exists and is up-to-date.
     *
     * @param   array  $mapping  Parent mapping data.
     * @param   bool   $dryRun   Whether to skip persistence.
     *
     * @return  int|null
     */
    private function ensureParentProduct(array $mapping, bool $dryRun): ?int
    {
        $existingIds = $this->findProductIdsBySku($mapping['sku']);
        $productId = null;

        if (!empty($existingIds)) {
            $productId = (int) $existingIds[0];
        }

        if ($dryRun) {
            if ($productId === null) {
                Log::add('Dry-run: would create VirtueMart parent product for SKU ' . $mapping['sku'] . '.', Log::INFO, self::LOG_CHANNEL);
            } else {
                Log::add('Dry-run: would update VirtueMart parent product ' . $productId . ' for SKU ' . $mapping['sku'] . '.', Log::INFO, self::LOG_CHANNEL);
            }

            return $productId;
        }

        $productId = $this->storeVmProduct($productId, [
            'sku' => $mapping['sku'],
            'name' => $mapping['name'],
            'description' => $mapping['description'],
            'slugReference' => $mapping['slugReference'],
            'parentId' => 0,
            'mpn' => $mapping['mpn'],
            'externalId' => $mapping['externalId'],
        ]);

        Log::add(
            sprintf('%s VirtueMart parent product %d for SKU %s.', $existingIds ? 'Updated' : 'Created', $productId, $mapping['sku']),
            Log::INFO,
            self::LOG_CHANNEL
        );

        return $productId;
    }

    /**
     * Ensure the VirtueMart variant product exists and is up-to-date.
     *
     * @param   array       $mapping             Field mapping.
     * @param   array       $pfProduct           Printful product payload.
     * @param   array       $pfVariant           Printful variant payload.
     * @param   int         $customFieldId       Custom field identifier.
     * @param   bool        $dryRun              Whether to run without writing changes.
     * @param   int|null    $existingProductId   Previously matched product ID.
     * @param   int|null    $colorCustomFieldId  Custom field ID for colour attribute.
     * @param   int|null    $sizeCustomFieldId   Custom field ID for size attribute.
     *
     * @return  string  Either "created", "updated" or "skipped".
     */
    private function upsertVmProduct(array $mapping, array $pfProduct, array $pfVariant, int $customFieldId, bool $dryRun, ?int $existingProductId = null, ?int $colorCustomFieldId = null, ?int $sizeCustomFieldId = null): string
    {
        $db = Factory::getDbo();
        $variantId = $mapping['variantId'];
        $productId = $existingProductId;

        if ($productId === null) {
            $productId = $this->findProductIdByVariantId($variantId, $customFieldId);
        }
        $isNew = $productId === null;

        if ($dryRun) {
            Log::add(
                sprintf('Dry-run: would %s VirtueMart product for variant %s.', $isNew ? 'create' : 'update', $variantId),
                Log::INFO,
                self::LOG_CHANNEL
            );

            return $isNew ? 'created' : 'updated';
        }

        if ($isNew && $customFieldId <= 0) {
            Log::add('Unable to create product for variant ' . $variantId . ' because custom field ID is unavailable.', Log::ERROR, self::LOG_CHANNEL);

            return 'errors';
        }

        $db->transactionStart();

        try {
            $productId = $this->storeVmProduct($productId, $mapping);
            $this->ensureCategoryAssignment($productId);
            $this->ensurePrice($productId, $mapping['price']);
            $this->ensureCustomFieldValue($productId, $customFieldId, $variantId);
            if ($colorCustomFieldId > 0 && !empty($mapping['attributes']['color'])) {
                $this->ensureCustomFieldValue($productId, $colorCustomFieldId, $mapping['attributes']['color']);
            }

            if ($sizeCustomFieldId > 0 && !empty($mapping['attributes']['size'])) {
                $this->ensureCustomFieldValue($productId, $sizeCustomFieldId, $mapping['attributes']['size']);
            }
            $this->downloadAndAttachImages($mapping['images'], $productId, $mapping['name']);

            $db->transactionCommit();
        } catch (\Throwable $throwable) {
            $db->transactionRollback();

            throw $throwable;
        }

        Log::add(
            sprintf('%s VirtueMart product %d for variant %s.', $isNew ? 'Created' : 'Updated', $productId, $variantId),
            Log::INFO,
            self::LOG_CHANNEL
        );

        return $isNew ? 'created' : 'updated';
    }

    /**
     * Ensure the VirtueMart product base, language data and metadata are stored.
     *
     * @param   int|null  $productId  Existing product ID or null.
     * @param   array     $mapping    Prepared mapping array.
     *
     * @return  int  Product identifier.
     */
    private function storeVmProduct(?int $productId, array $mapping): int
    {
        $db = Factory::getDbo();
        $userId = $this->getCurrentUserId();
        $now = (new Date())->toSql();
        $vendorId = $this->getVendorId();
        $stock = 9999;
        $slug = $this->generateSlug($mapping['name'], (string) ($mapping['slugReference'] ?? $mapping['sku']));
        $parentId = (int) ($mapping['parentId'] ?? 0);
        $mpn = (string) ($mapping['mpn'] ?? $mapping['sku']);
        $externalId = (string) ($mapping['externalId'] ?? '');

        if ($productId === null) {
            $productObject = (object) [
                'virtuemart_vendor_id' => $vendorId,
                'product_parent_id' => $parentId,
                'product_sku' => $mapping['sku'],
                'product_mpn' => $mpn,
                'product_gtin' => $externalId,
                'product_in_stock' => $stock,
                'product_ordered' => 0,
                'published' => 1,
                'created_on' => $now,
                'created_by' => $userId,
                'modified_on' => $now,
                'modified_by' => $userId,
                'hits' => 0,
                'virtuemart_product_id' => null,
            ];

            $db->insertObject('#__virtuemart_products', $productObject, 'virtuemart_product_id');
            $productId = (int) $productObject->virtuemart_product_id;
        } else {
            $updateObject = (object) [
                'virtuemart_product_id' => $productId,
                'product_sku' => $mapping['sku'],
                'product_parent_id' => $parentId,
                'product_mpn' => $mpn,
                'product_gtin' => $externalId,
                'product_in_stock' => $stock,
                'modified_on' => $now,
                'modified_by' => $userId,
            ];

            $db->updateObject('#__virtuemart_products', $updateObject, 'virtuemart_product_id');
        }

        $langTable = $this->getProductLanguageTableName();

        if ($langTable !== null) {
            $langObject = (object) [
                'virtuemart_product_id' => $productId,
                'product_name' => $mapping['name'],
                'product_s_desc' => '',
                'product_desc' => $mapping['description'],
                'slug' => $slug,
                'metadesc' => '',
                'metakey' => '',
                'customtitle' => '',
                'modified_on' => $now,
                'modified_by' => $userId,
            ];

            $exists = $this->recordExists($langTable, 'virtuemart_product_id', $productId);

            if ($exists) {
                $db->updateObject($langTable, $langObject, 'virtuemart_product_id');
            } else {
                $langObject->created_on = $now;
                $langObject->created_by = $userId;
                $db->insertObject($langTable, $langObject);
            }
        }

        return $productId;
    }

    /**
     * Assign the default category if configured.
     *
     * @param   int  $productId  Product identifier.
     *
     * @return  void
     */
    private function ensureCategoryAssignment(int $productId): void
    {
        $categoryId = (int) $this->params->get('default_category_id', 0);

        if ($categoryId <= 0) {
            return;
        }

        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__virtuemart_product_categories'))
            ->where($db->quoteName('virtuemart_product_id') . ' = ' . (int) $productId)
            ->where($db->quoteName('virtuemart_category_id') . ' = ' . (int) $categoryId);
        $db->setQuery($query);

        if ((int) $db->loadResult() > 0) {
            return;
        }

        $object = (object) [
            'virtuemart_product_id' => $productId,
            'virtuemart_category_id' => $categoryId,
            'ordering' => 0,
        ];

        $db->insertObject('#__virtuemart_product_categories', $object);
    }

    /**
     * Ensure the product price exists.
     *
     * @param   int    $productId  Product identifier.
     * @param   float  $price      Price value.
     *
     * @return  void
     */
    private function ensurePrice(int $productId, float $price): void
    {
        $db = Factory::getDbo();
        $userId = $this->getCurrentUserId();
        $now = (new Date())->toSql();
        $vendorId = $this->getVendorId();
        $currencyId = $this->getVendorCurrencyId($vendorId);

        $query = $db->getQuery(true)
            ->select($db->quoteName('virtuemart_product_price_id'))
            ->from($db->quoteName('#__virtuemart_product_prices'))
            ->where($db->quoteName('virtuemart_product_id') . ' = ' . (int) $productId)
            ->where($db->quoteName('price_quantity_start') . ' IS NULL')
            ->where($db->quoteName('price_quantity_end') . ' IS NULL')
            ->order($db->quoteName('virtuemart_product_price_id') . ' ASC');

        $db->setQuery($query);
        $priceId = (int) $db->loadResult();

        $object = (object) [
            'virtuemart_product_id' => $productId,
            'virtuemart_vendor_id' => $vendorId,
            'product_price' => $price,
            'override' => 0,
            'product_currency' => $currencyId,
            'published' => 1,
            'modified_on' => $now,
            'modified_by' => $userId,
        ];

        if ($priceId > 0) {
            $object->virtuemart_product_price_id = $priceId;
            $db->updateObject('#__virtuemart_product_prices', $object, 'virtuemart_product_price_id');
        } else {
            $object->created_on = $now;
            $object->created_by = $userId;
            $db->insertObject('#__virtuemart_product_prices', $object, 'virtuemart_product_price_id');
        }
    }

    /**
     * Ensure the custom field entry storing a Printful attribute exists.
     *
     * @param   int    $productId      Product identifier.
     * @param   int    $customFieldId  Custom field identifier.
     * @param   string $value          Attribute value.
     *
     * @return  void
     */
    private function ensureCustomFieldValue(int $productId, int $customFieldId, string $value): void
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select($db->quoteName('virtuemart_customfield_id'))
            ->from($db->quoteName('#__virtuemart_product_customfields'))
            ->where($db->quoteName('virtuemart_product_id') . ' = ' . (int) $productId)
            ->where($db->quoteName('virtuemart_custom_id') . ' = ' . (int) $customFieldId)
            ->order($db->quoteName('virtuemart_customfield_id') . ' ASC');

        $db->setQuery($query);
        $existingId = (int) $db->loadResult();

        $object = (object) [
            'virtuemart_product_id' => $productId,
            'virtuemart_custom_id' => $customFieldId,
            'customfield_value' => $value,
            'published' => 1,
            'ordering' => 0,
            'customfield_params' => '',
            'override' => 0,
            'created_on' => (new Date())->toSql(),
        ];

        if ($existingId > 0) {
            $object->virtuemart_customfield_id = $existingId;
            $db->updateObject('#__virtuemart_product_customfields', $object, 'virtuemart_customfield_id');
        } else {
            $db->insertObject('#__virtuemart_product_customfields', $object, 'virtuemart_customfield_id');
        }
    }

    /**
     * Retrieve a custom field value for a product.
     *
     * @param   int  $productId      Product identifier.
     * @param   int  $customFieldId  Custom field identifier.
     *
     * @return  string|null
     */
    private function getCustomFieldValue(int $productId, int $customFieldId): ?string
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select($db->quoteName('customfield_value'))
            ->from($db->quoteName('#__virtuemart_product_customfields'))
            ->where($db->quoteName('virtuemart_product_id') . ' = ' . (int) $productId)
            ->where($db->quoteName('virtuemart_custom_id') . ' = ' . (int) $customFieldId)
            ->order($db->quoteName('virtuemart_customfield_id') . ' ASC');

        $db->setQuery($query, 0, 1);
        $value = $db->loadResult();

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Download remote images and attach them to the VirtueMart product.
     *
     * @param   array   $imageUrls   List of remote URLs.
     * @param   int     $productId   Product identifier.
     * @param   string  $productName Product name for metadata.
     *
     * @return  void
     */
    private function downloadAndAttachImages(array $imageUrls, int $productId, string $productName): void
    {
        if (empty($imageUrls)) {
            return;
        }

        $db = Factory::getDbo();
        $existingHashes = $this->getExistingImageHashes($productId);
        $vendorId = $this->getVendorId();
        $userId = $this->getCurrentUserId();
        $now = (new Date())->toSql();

        $directory = JPATH_ROOT . '/' . self::IMAGE_DIRECTORY;

        if (!Folder::exists($directory)) {
            Folder::create($directory);
        }

        $http = HttpFactory::getHttp();
        $ordering = $this->getNextMediaOrdering($productId);

        foreach ($imageUrls as $url) {
            $hash = md5($url);

            if (in_array($hash, $existingHashes, true)) {
                continue;
            }

            try {
                $response = $http->get($url);
            } catch (\Throwable $throwable) {
                Log::add('Failed to download image ' . $url . ': ' . $throwable->getMessage(), Log::WARNING, self::LOG_CHANNEL);
                continue;
            }

            $body = $response->body ?? null;

            if (!is_string($body) || $body === '') {
                Log::add('Empty response when downloading image ' . $url . '.', Log::WARNING, self::LOG_CHANNEL);
                continue;
            }

            $extension = $this->guessImageExtension($url, $response->headers ?? []);
            $fileName = 'printful_' . $hash . '.' . $extension;
            $relativePath = self::IMAGE_DIRECTORY . '/' . $fileName;
            $fullPath = JPATH_ROOT . '/' . $relativePath;

            if (!File::write($fullPath, $body)) {
                Log::add('Failed to save image to ' . $fullPath . '.', Log::WARNING, self::LOG_CHANNEL);
                continue;
            }

            $mediaObject = (object) [
                'virtuemart_vendor_id' => $vendorId,
                'media_name' => $productName,
                'file_title' => $productName,
                'file_description' => 'Imported from Printful',
                'file_meta' => 'printful_url_hash:' . $hash,
                'file_mimetype' => $this->guessMimeType($extension),
                'file_type' => 'product',
                'file_url' => $relativePath,
                'file_url_thumb' => '',
                'published' => 1,
                'file_is_downloadable' => 0,
                'file_is_forSale' => 0,
                'file_params' => '',
                'shared' => 1,
                'created_on' => $now,
                'created_by' => $userId,
                'modified_on' => $now,
                'modified_by' => $userId,
            ];

            $db->insertObject('#__virtuemart_medias', $mediaObject, 'virtuemart_media_id');
            $mediaId = (int) $mediaObject->virtuemart_media_id;

            $pivot = (object) [
                'virtuemart_product_id' => $productId,
                'virtuemart_media_id' => $mediaId,
                'ordering' => $ordering++,
            ];

            $db->insertObject('#__virtuemart_product_medias', $pivot);
            $existingHashes[] = $hash;
        }
    }

    /**
     * Ensure a VirtueMart custom field exists for storing Printful metadata.
     *
     * @param   string  $name     Custom field title.
     * @param   bool    $dryRun   Whether execution is dry-run.
     * @param   string  $tip      Helper text for the custom field.
     * @param   bool    $hidden   Whether the field should be hidden in the shop front-end.
     *
     * @return  int
     */
    private function ensureCustomField(string $name, bool $dryRun, string $tip = 'Printful variant reference', bool $hidden = true): int
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select($db->quoteName('virtuemart_custom_id'))
            ->from($db->quoteName('#__virtuemart_customs'))
            ->where($db->quoteName('custom_title') . ' = ' . $db->quote($name))
            ->order($db->quoteName('virtuemart_custom_id') . ' ASC');
        $db->setQuery($query);
        $id = (int) $db->loadResult();

        if ($id > 0) {
            return $id;
        }

        if ($dryRun) {
            Log::add('Dry-run: custom field "' . $name . '" does not exist and would be created.', Log::INFO, self::LOG_CHANNEL);

            return 0;
        }

        $now = (new Date())->toSql();
        $userId = $this->getCurrentUserId();
        $vendorId = $this->getVendorId();

        $customObject = (object) [
            'virtuemart_vendor_id' => $vendorId,
            'custom_jplugin_id' => 0,
            'custom_parent_id' => 0,
            'custom_element' => 'printful_variant',
            'custom_title' => $name,
            'custom_tip' => $tip,
            'custom_value' => '',
            'custom_desc' => '',
            'field_type' => 'S',
            'is_list' => 0,
            'is_hidden' => $hidden ? 1 : 0,
            'is_cart_attribute' => 0,
            'is_input' => 0,
            'searchable' => 0,
            'published' => 1,
            'layout_pos' => '',
            'custom_params' => '',
            'shared' => 1,
            'admin_only' => 0,
            'created_on' => $now,
            'created_by' => $userId,
            'modified_on' => $now,
            'modified_by' => $userId,
        ];

        $db->insertObject('#__virtuemart_customs', $customObject, 'virtuemart_custom_id');

        return (int) $customObject->virtuemart_custom_id;
    }

    /**
     * Find an existing VirtueMart product by Printful variant ID.
     *
     * @param   string  $variantId      Variant identifier.
     * @param   int     $customFieldId  Custom field identifier.
     *
     * @return  int|null
     */
    private function findProductIdByVariantId(string $variantId, int $customFieldId): ?int
    {
        if ($customFieldId <= 0) {
            return null;
        }

        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select($db->quoteName('virtuemart_product_id'))
            ->from($db->quoteName('#__virtuemart_product_customfields'))
            ->where($db->quoteName('virtuemart_custom_id') . ' = ' . (int) $customFieldId)
            ->where($db->quoteName('customfield_value') . ' = ' . $db->quote($variantId))
            ->order($db->quoteName('virtuemart_customfield_id') . ' ASC');

        $db->setQuery($query, 0, 1);
        $result = $db->loadResult();

        return $result ? (int) $result : null;
    }

    /**
     * Retrieve hashes for already attached images.
     *
     * @param   int  $productId  Product identifier.
     *
     * @return  array<int, string>
     */
    private function getExistingImageHashes(int $productId): array
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['m.file_meta']))
            ->from($db->quoteName('#__virtuemart_product_medias', 'pm'))
            ->innerJoin($db->quoteName('#__virtuemart_medias', 'm') . ' ON m.' . $db->quoteName('virtuemart_media_id') . ' = pm.' . $db->quoteName('virtuemart_media_id'))
            ->where('pm.' . $db->quoteName('virtuemart_product_id') . ' = ' . (int) $productId);
        $db->setQuery($query);
        $rows = $db->loadColumn();
        $hashes = [];

        foreach ($rows as $meta) {
            if (!is_string($meta)) {
                continue;
            }

            if (strpos($meta, 'printful_url_hash:') === 0) {
                $hashes[] = substr($meta, strlen('printful_url_hash:'));
            }
        }

        return $hashes;
    }

    /**
     * Guess an image extension.
     *
     * @param   string  $url      Remote URL.
     * @param   mixed   $headers  Response headers.
     *
     * @return  string
     */
    private function guessImageExtension(string $url, $headers): string
    {
        $extension = strtolower((string) File::getExt(parse_url($url, PHP_URL_PATH) ?? ''));

        if ($extension !== '') {
            return $extension;
        }

        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'content-type') {
                    if (is_array($value)) {
                        $value = reset($value);
                    }

                    if (is_string($value) && strpos($value, '/') !== false) {
                        $parts = explode('/', $value);
                        $extension = end($parts);

                        return preg_replace('/[^a-z0-9]/i', '', strtolower((string) $extension)) ?: 'jpg';
                    }
                }
            }
        }

        return 'jpg';
    }

    /**
     * Guess MIME type from extension.
     *
     * @param   string  $extension  File extension.
     *
     * @return  string
     */
    private function guessMimeType(string $extension): string
    {
        switch (strtolower($extension)) {
            case 'png':
                return 'image/png';
            case 'gif':
                return 'image/gif';
            case 'webp':
                return 'image/webp';
            default:
                return 'image/jpeg';
        }
    }

    /**
     * Determine whether synchronisation should be a dry-run.
     *
     * @return  bool
     */
    private function isDryRun(): bool
    {
        $value = $this->params->get('sync_dry_run', '1');

        if (is_string($value)) {
            return $value !== '0';
        }

        return (bool) $value;
    }

    /**
     * Generate a slug for VirtueMart.
     *
     * @param   string  $name       Product name.
     * @param   string  $variantId  Variant identifier.
     *
     * @return  string
     */
    private function generateSlug(string $name, string $reference): string
    {
        $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) ?? '');

        if ($slug === '') {
            $slug = 'printful-' . $reference;
        }

        return rtrim($slug, '-');
    }

    /**
     * Retrieve the VirtueMart vendor identifier.
     *
     * @return  int
     */
    private function getVendorId(): int
    {
        if (!class_exists('VmConfig')) {
            return 1;
        }

        $mode = (string) \VmConfig::get('multix', 'none');

        if ($mode === 'none') {
            return 1;
        }

        $vendorId = (int) \VmConfig::get('default_vendor_id', 1);

        return $vendorId > 0 ? $vendorId : 1;
    }

    /**
     * Retrieve the default vendor currency identifier.
     *
     * @param   int  $vendorId  Vendor identifier.
     *
     * @return  int
     */
    private function getVendorCurrencyId(int $vendorId): int
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select($db->quoteName('vendor_currency'))
            ->from($db->quoteName('#__virtuemart_vendors'))
            ->where($db->quoteName('virtuemart_vendor_id') . ' = ' . (int) $vendorId);
        $db->setQuery($query);
        $currency = (int) $db->loadResult();

        return $currency > 0 ? $currency : 0;
    }

    /**
     * Determine the next ordering value for media attachments.
     *
     * @param   int  $productId  Product identifier.
     *
     * @return  int
     */
    private function getNextMediaOrdering(int $productId): int
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select('MAX(' . $db->quoteName('ordering') . ')')
            ->from($db->quoteName('#__virtuemart_product_medias'))
            ->where($db->quoteName('virtuemart_product_id') . ' = ' . (int) $productId);
        $db->setQuery($query);
        $ordering = (int) $db->loadResult();

        return $ordering + 1;
    }

    /**
     * Get the VirtueMart product language table name.
     *
     * @return  string|null
     */
    private function getProductLanguageTableName(): ?string
    {
        if (!class_exists('VmConfig')) {
            return '#__virtuemart_products_en_gb';
        }

        $lang = '';

        if (isset(\VmConfig::$vmlang) && is_string(\VmConfig::$vmlang) && \VmConfig::$vmlang !== '') {
            $lang = \VmConfig::$vmlang;
        }

        if ($lang === '') {
            $lang = (string) \VmConfig::get('vmlang', 'en_gb');
        }

        if ($lang === '') {
            $lang = 'en_gb';
        }

        return '#__virtuemart_products_' . strtolower($lang);
    }

    /**
     * Check whether a record exists.
     *
     * @param   string  $table   Table name.
     * @param   string  $key     Primary key column.
     * @param   int     $value   Value to check.
     *
     * @return  bool
     */
    private function recordExists(string $table, string $key, int $value): bool
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName($table))
            ->where($db->quoteName($key) . ' = ' . (int) $value);
        $db->setQuery($query);

        return (int) $db->loadResult() > 0;
    }

    /**
     * Get the Joomla user ID of the current actor.
     *
     * @return  int
     */
    private function getCurrentUserId(): int
    {
        $identity = Factory::getApplication()->getIdentity();

        return $identity ? (int) $identity->id : 0;
    }
}
