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

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use RuntimeException;
use VmModel;

use function array_key_exists;
use function implode;
use function in_array;
use function json_encode;
use function sprintf;
use function strip_tags;
use function trim;

/**
 * Handles VirtueMart product manipulation during the synchronisation process.
 */
final class ProductManager
{
    private Registry $params;

    private CMSApplicationInterface $application;

    private CustomFieldManager $customFieldManager;

    private DatabaseDriver $db;

    public function __construct(Registry $params, CMSApplicationInterface $application, CustomFieldManager $customFieldManager)
    {
        $this->params             = $params;
        $this->application        = $application;
        $this->customFieldManager = $customFieldManager;
        $this->db                 = Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * Ensures that the parent product exists and returns its ID.
     *
     * @param  array<string, mixed>  $printfulProduct
     */
    public function ensureParentProduct(array $printfulProduct): int
    {
        $sku     = $this->createParentSku($printfulProduct);
        $product = $this->loadProductBySku($sku);

        $vendorId = $product !== null ? (int) $product->virtuemart_vendor_id : 1;

        $data = [
            'virtuemart_product_id' => $product?->virtuemart_product_id ?? 0,
            'product_sku'           => $sku,
            'product_name'          => (string) $printfulProduct['name'],
            'slug'                  => OutputFilter::stringURLSafe((string) $printfulProduct['name']),
            'product_parent_id'     => 0,
            'published'             => 1,
            'product_desc'          => strip_tags((string) ($printfulProduct['description'] ?? '')),
            'virtuemart_vendor_id'  => $vendorId,
        ];

        $model = VmModel::getModel('product');
        $id    = (int) $model->store($data);

        if ($id <= 0) {
            throw new RuntimeException(sprintf('Unable to save VirtueMart product for Printful item "%s".', $printfulProduct['name']));
        }

        $this->log(sprintf('Synchronised parent product "%s" (SKU %s, ID %d).', $printfulProduct['name'], $sku, $id));

        return $id;
    }

    /**
     * Ensures that the Generic Child Variant plugin customfield is attached to the parent product.
     *
     * @param  array<string, mixed>  $pluginConfig
     */
    public function ensureGenericChildVariantPluginOnParent(int $parentId, array $pluginConfig): void
    {
        $customId = $this->customFieldManager->ensureGenericChildPluginCustom();

        $existing = $this->loadProductCustomField($parentId, $customId);
        $serialized = json_encode($pluginConfig, \JSON_THROW_ON_ERROR);

        if ($existing !== null) {
            $this->updateProductCustomField($existing->virtuemart_product_customfield_id, $serialized);
            return;
        }

        $columns = [
            'virtuemart_product_id',
            'virtuemart_custom_id',
            'customfield_value',
            'customfield_params',
            'ordering',
            'published',
        ];

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__virtuemart_product_customfields'))
            ->columns($this->db->quoteName($columns))
            ->values(':product_id, :custom_id, \'\', :params, 0, 1');

        $query->bind(':product_id', $parentId, ParameterType::INTEGER);
        $query->bind(':custom_id', $customId, ParameterType::INTEGER);
        $query->bind(':params', $serialized);

        $this->db->setQuery($query);
        $this->db->execute();

        $this->log(sprintf('Attached Generic Child Variant plugin to product %d.', $parentId));
    }

    /**
     * Creates a deterministic SKU for the child product.
     *
     * @param  array<string, mixed>  $printfulProduct
     * @param  array<string, mixed>  $variant
     */
    public function createChildSku(array $printfulProduct, array $variant): string
    {
        $base = $this->createParentSku($printfulProduct);
        $color = trim((string) ($variant['color'] ?? '')); 
        $size  = trim((string) ($variant['size'] ?? ''));

        $components = [$base];
        if ($color !== '') {
            $components[] = OutputFilter::stringURLSafe($color);
        }
        if ($size !== '') {
            $components[] = OutputFilter::stringURLSafe($size);
        }

        return strtoupper(implode('-', $components));
    }

    /**
     * Ensures that a child product exists for the Printful variant and returns its ID.
     *
     * @param  array<string, mixed>  $printfulProduct
     * @param  array<string, mixed>  $variant
     */
    public function ensureChildProduct(int $parentId, array $printfulProduct, array $variant, string $sku): int
    {
        $existing = $this->loadProductBySku($sku);
        $name     = sprintf('%s – %s / %s', $printfulProduct['name'], $variant['color'] ?? '-', $variant['size'] ?? '-');
        $stock    = (int) ($variant['quantity'] ?? 0);
        $published = array_key_exists('is_available', $variant) ? ((bool) $variant['is_available'] ? 1 : 0) : 1;

        $vendorId = $existing !== null ? (int) $existing->virtuemart_vendor_id : 1;

        $data = [
            'virtuemart_product_id' => $existing?->virtuemart_product_id ?? 0,
            'product_parent_id'     => $parentId,
            'product_sku'           => $sku,
            'product_name'          => $name,
            'slug'                  => OutputFilter::stringURLSafe($name),
            'product_in_stock'      => $stock,
            'published'             => $published,
            'virtuemart_vendor_id'  => $vendorId,
        ];

        $model = VmModel::getModel('product');
        $id    = (int) $model->store($data);

        if ($id <= 0) {
            throw new RuntimeException(sprintf('Unable to store VirtueMart child product for SKU %s.', $sku));
        }

        $this->log(sprintf('Synchronised child product %s (ID %d).', $sku, $id));

        return $id;
    }

    /**
     * Assigns the specific custom field value to the child product.
     */
    public function setChildCustomFieldValue(int $productId, int $customId, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        $existing = $this->loadProductCustomField($productId, $customId);
        if ($existing !== null) {
            $this->updateProductCustomFieldValue($existing->virtuemart_product_customfield_id, $value);
            return;
        }

        $columns = [
            'virtuemart_product_id',
            'virtuemart_custom_id',
            'customfield_value',
            'custom_param',
            'customfield_params',
            'ordering',
            'published',
        ];

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__virtuemart_product_customfields'))
            ->columns($this->db->quoteName($columns))
            ->values(':product_id, :custom_id, :value, \'\', \'\', 0, 1');

        $query->bind(':product_id', $productId, ParameterType::INTEGER);
        $query->bind(':custom_id', $customId, ParameterType::INTEGER);
        $query->bind(':value', $value);

        $this->db->setQuery($query);
        $this->db->execute();
    }

    /**
     * Depublishes or removes child products that are no longer present in Printful.
     */
    public function unpublishOrDeleteMissingChildren(int $parentId, array $keepSkus): void
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['virtuemart_product_id', 'product_sku']))
            ->from($this->db->quoteName('#__virtuemart_products'))
            ->where($this->db->quoteName('product_parent_id') . ' = :parentId');
        $query->bind(':parentId', $parentId, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $children = (array) $this->db->loadObjectList();

        foreach ($children as $child) {
            if (in_array($child->product_sku, $keepSkus, true)) {
                continue;
            }

            if ((int) $this->params->get('delete_missing_variants', 0) === 1) {
                $this->deleteProduct((int) $child->virtuemart_product_id);
            } else {
                $this->setProductPublished((int) $child->virtuemart_product_id, 0);
            }
        }
    }

    private function createParentSku(array $printfulProduct): string
    {
        $externalId = trim((string) ($printfulProduct['external_id'] ?? ''));
        if ($externalId !== '') {
            return strtoupper($externalId);
        }

        return 'PF-' . (int) $printfulProduct['id'];
    }

    private function loadProductBySku(string $sku): ?object
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__virtuemart_products'))
            ->where($this->db->quoteName('product_sku') . ' = :sku');
        $query->bind(':sku', $sku);

        $this->db->setQuery($query);
        $row = $this->db->loadObject();

        return $row ?: null;
    }

    private function loadProductCustomField(int $productId, int $customId): ?object
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__virtuemart_product_customfields'))
            ->where($this->db->quoteName('virtuemart_product_id') . ' = :productId')
            ->where($this->db->quoteName('virtuemart_custom_id') . ' = :customId');

        $query->bind(':productId', $productId, ParameterType::INTEGER);
        $query->bind(':customId', $customId, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $row = $this->db->loadObject();

        return $row ?: null;
    }

    private function updateProductCustomField(int $productCustomFieldId, string $params): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__virtuemart_product_customfields'))
            ->set($this->db->quoteName('customfield_params') . ' = :params')
            ->where($this->db->quoteName('virtuemart_product_customfield_id') . ' = :id');

        $query->bind(':params', $params);
        $query->bind(':id', $productCustomFieldId, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $this->db->execute();
    }

    private function updateProductCustomFieldValue(int $productCustomFieldId, string $value): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__virtuemart_product_customfields'))
            ->set($this->db->quoteName('customfield_value') . ' = :value')
            ->where($this->db->quoteName('virtuemart_product_customfield_id') . ' = :id');

        $query->bind(':value', $value);
        $query->bind(':id', $productCustomFieldId, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $this->db->execute();
    }

    private function deleteProduct(int $productId): void
    {
        $model = VmModel::getModel('product');
        $model->remove([$productId]);
        $this->log(sprintf('Removed obsolete child product ID %d.', $productId));
    }

    private function setProductPublished(int $productId, int $state): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__virtuemart_products'))
            ->set($this->db->quoteName('published') . ' = :state')
            ->where($this->db->quoteName('virtuemart_product_id') . ' = :id');

        $query->bind(':state', $state, ParameterType::INTEGER);
        $query->bind(':id', $productId, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $this->db->execute();

        $this->log(sprintf('Set publish state %d for product %d.', $state, $productId));
    }

    private function log(string $message): void
    {
        Log::add($message, Log::INFO, 'plg_system_printfulsync');
        $this->application->enqueueMessage($message, 'message');
    }
}
