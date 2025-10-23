<?php
/**
 * @package     PrintfulVirtueMart
 * @subpackage  Plugin.System.PrintfulSync
 *
 * @copyright   Copyright (C) 2024 Printful
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Plugin\System\Printfulsync\Service;

use InvalidArgumentException;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Http\HttpFactory;
use Joomla\Registry\Registry;
use RuntimeException;
use Joomla\Plugin\System\Printfulsync\Service\CustomFieldManager;
use Joomla\Plugin\System\Printfulsync\Service\ProductManager;
use JsonException;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function http_build_query;
use function in_array;
use function is_array;
use function json_decode;
use function json_last_error_msg;
use function rawurlencode;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Main synchronisation service orchestrating the VirtueMart data updates.
 */
final class PrintfulSyncService
{
    private Registry $params;

    private CMSApplicationInterface $application;

    private CustomFieldManager $customFieldManager;

    private ProductManager $productManager;

    /**
     * @param  Registry                 $params      Plugin parameters.
     * @param  CMSApplicationInterface  $application Joomla application for
     *                                              logging and localisation.
     */
    public function __construct(Registry $params, CMSApplicationInterface $application)
    {
        $this->params      = $params;
        $this->application = $application;
        $this->customFieldManager = new CustomFieldManager($params, $application);
        $this->productManager     = new ProductManager($params, $application, $this->customFieldManager);
    }

    /**
     * Synchronises a single Printful product with VirtueMart.
     *
     * @param  array<string, mixed>  $printfulProduct Product structure as
     *                                                delivered by the Printful
     *                                                products API.
     */
    public function syncPrintfulProductToVM(array $printfulProduct): void
    {
        $this->validatePayload($printfulProduct);

        $groupTitle     = (string) $this->params->get('group_title', 'Generic Child Variant');
        $colorFieldName = (string) $this->params->get('color_field_title', 'Farbe');
        $sizeFieldName  = (string) $this->params->get('size_field_title', 'Größe');

        $groupId     = $this->customFieldManager->ensureCustomFieldGroup($groupTitle);
        $colorFieldId = $this->customFieldManager->ensureListCustomField($colorFieldName, $groupId, 0);
        $sizeFieldId  = $this->customFieldManager->ensureListCustomField($sizeFieldName, $groupId, 1);

        $colorMapping = $this->parseValueMap('value_map_color');
        $sizeMapping  = $this->parseValueMap('value_map_size');

        $colors = $this->collectDistinctAttributeValues($printfulProduct['variants'], 'color', $colorMapping);
        $sizes  = $this->collectDistinctAttributeValues($printfulProduct['variants'], 'size', $sizeMapping);

        $this->customFieldManager->updateListOptions($colorFieldId, $colors);
        $this->customFieldManager->updateListOptions($sizeFieldId, $sizes);

        $parentId = $this->productManager->ensureParentProduct($printfulProduct);
        $this->productManager->ensureGenericChildVariantPluginOnParent($parentId, [
            'layout_pos'       => 'addtocart',
            'show_parent'      => 0,
            'parent_orderable' => 0,
            'show_price'       => 1,
            'ajax_in_category' => (int) $this->params->get('category_ajax_on_list', 1),
        ]);

        $seenSkus = [];
        foreach ($printfulProduct['variants'] as $variant) {
            $sku = $this->productManager->createChildSku($printfulProduct, $variant);
            $childId = $this->productManager->ensureChildProduct($parentId, $printfulProduct, $variant, $sku);
            $this->productManager->setChildCustomFieldValue($childId, $colorFieldId, $this->mapAttributeValue($variant['color'] ?? '', $colorMapping));
            $this->productManager->setChildCustomFieldValue($childId, $sizeFieldId, $this->mapAttributeValue($variant['size'] ?? '', $sizeMapping));
            $seenSkus[] = $sku;
        }

        if ((int) $this->params->get('delete_missing_variants', 0) === 1) {
            $this->productManager->unpublishOrDeleteMissingChildren($parentId, $seenSkus);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @param  string                             $attributeKey
     * @param  string                             $mappingParamKey
     *
     * @return string[]
     */
    private function collectDistinctAttributeValues(array $variants, string $attributeKey, array $mapping): array
    {
        $values = [];
        foreach ($variants as $variant) {
            if (!array_key_exists($attributeKey, $variant)) {
                continue;
            }

            $value = $this->mapAttributeValue((string) $variant[$attributeKey], $mapping);
            if ($value === '') {
                continue;
            }

            $values[] = $value;
        }

        $values = array_unique($values);

        return $values;
    }

    /**
     * Ensures that the payload adheres to the expected structure.
     *
     * @param  array<string, mixed>  $payload
     */
    private function validatePayload(array $payload): void
    {
        foreach (['id', 'name', 'variants'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $payload)) {
                throw new InvalidArgumentException(sprintf('Missing required key "%s" in Printful payload.', $requiredKey));
            }
        }

        if (!is_array($payload['variants']) || count($payload['variants']) === 0) {
            throw new InvalidArgumentException('Printful product payload must contain at least one variant.');
        }
    }

    /**
     * Parses a JSON based mapping from plugin parameters.
     *
     * @return array<string, string>
     */
    private function parseValueMap(string $paramName): array
    {
        $json = (string) $this->params->get($paramName, '');
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Invalid JSON provided for %s: %s', $paramName, json_last_error_msg()));
        }

        $mapping = [];
        foreach ($decoded as $key => $value) {
            $mapping[(string) $key] = (string) $value;
        }

        return $mapping;
    }

    private function mapAttributeValue(string $value, array $mapping): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return $mapping[$value] ?? $value;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPrintfulProducts(string $apiToken, string $storeId): array
    {
        $listResponse = $this->requestPrintful(
            sprintf('stores/%s/products', rawurlencode($storeId)),
            $apiToken,
            $storeId
        );

        $items = $this->extractResultList($listResponse);

        $products = [];

        foreach ($items as $item) {
            if (!is_array($item) || !array_key_exists('id', $item)) {
                continue;
            }

            $detailResponse = $this->requestPrintful(
                sprintf('stores/%s/products/%s', rawurlencode($storeId), rawurlencode((string) $item['id'])),
                $apiToken,
                $storeId
            );

            $product = $this->convertPrintfulProduct($detailResponse);

            if (!empty($product)) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractResultList(array $response): array
    {
        if (isset($response['result'])) {
            $result = $response['result'];

            if (is_array($result)) {
                if (isset($result['items']) && is_array($result['items'])) {
                    return array_values(array_filter(
                        $result['items'],
                        static fn($value): bool => is_array($value)
                    ));
                }

                if (array_is_list($result)) {
                    return array_values(array_filter(
                        $result,
                        static fn($value): bool => is_array($value)
                    ));
                }
            }
        }

        return [];
    }

    /**
     * Converts the Printful API payload into the structure expected by the synchroniser.
     *
     * @return array<string, mixed>
     */
    private function convertPrintfulProduct(array $response): array
    {
        $result  = $response['result'] ?? [];
        $product = is_array($result) ? ($result['sync_product'] ?? []) : [];
        $variants = is_array($result) ? ($result['sync_variants'] ?? []) : [];

        if (!is_array($product) || !array_key_exists('id', $product)) {
            return [];
        }

        $payload = [
            'id'          => $product['id'] ?? $product['sync_product_id'] ?? 0,
            'external_id' => $product['external_id'] ?? '',
            'name'        => $product['name'] ?? '',
            'description' => $product['description'] ?? '',
            'variants'    => [],
        ];

        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $quantity = 0;

            if (isset($variant['quantity'])) {
                $quantity = (int) $variant['quantity'];
            } elseif (
                isset($variant['warehouse_product_variant'])
                && is_array($variant['warehouse_product_variant'])
                && isset($variant['warehouse_product_variant']['quantity'])
            ) {
                $quantity = (int) $variant['warehouse_product_variant']['quantity'];
            }

            $payload['variants'][] = [
                'id'           => $variant['id'] ?? $variant['sync_variant_id'] ?? 0,
                'sku'          => $variant['sku'] ?? ($variant['external_id'] ?? ''),
                'color'        => $this->extractVariantAttribute($variant, 'color'),
                'size'         => $this->extractVariantAttribute($variant, 'size'),
                'quantity'     => $quantity,
                'is_available' => $this->extractVariantAvailability($variant),
                'price'        => $variant['retail_price'] ?? $variant['price'] ?? '',
            ];
        }

        return $payload;
    }

    private function extractVariantAttribute(array $variant, string $key): string
    {
        $candidates = [
            $variant[$key] ?? null,
            $variant['product'][$key] ?? null,
            $variant['options'][$key] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return (string) $candidate;
            }
        }

        return '';
    }

    private function extractVariantAvailability(array $variant): bool
    {
        if (array_key_exists('is_available', $variant)) {
            return (bool) $variant['is_available'];
        }

        if (array_key_exists('is_disabled', $variant)) {
            return !(bool) $variant['is_disabled'];
        }

        if (isset($variant['availability_status'])) {
            return $variant['availability_status'] === 'active';
        }

        return true;
    }

    private function requestPrintful(string $endpoint, string $apiToken, string $storeId, array $query = []): array
    {
        $baseUrl = 'https://api.printful.com/';
        $url     = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $apiToken,
            'Content-Type'  => 'application/json',
            'X-PF-Store-Id' => $storeId,
        ];

        $http     = HttpFactory::getHttp();
        $response = $http->get($url, $headers);

        if ($response->code >= 400) {
            throw new RuntimeException(sprintf('Printful API request failed (%d): %s', $response->code, $response->body));
        }

        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to decode Printful API response: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected Printful API response structure.');
        }

        return $decoded;
    }
}
