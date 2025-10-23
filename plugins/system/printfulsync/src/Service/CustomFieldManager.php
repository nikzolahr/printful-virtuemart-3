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
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use RuntimeException;
use VmModel;

use function array_key_exists;
use function sprintf;
use function trim;

/**
 * Handles VirtueMart custom field creation and maintenance.
 */
final class CustomFieldManager
{
    private Registry $params;

    private CMSApplicationInterface $application;

    private DatabaseDriver $db;

    private bool $deactivateObsoleteValues;

    public function __construct(Registry $params, CMSApplicationInterface $application)
    {
        $this->params   = $params;
        $this->application = $application;
        $this->db       = Factory::getContainer()->get('DatabaseDriver');
        $this->deactivateObsoleteValues = (int) $params->get('deactivate_obsolete_values', 1) === 1;
    }

    /**
     * Ensures that the required custom field group exists.
     */
    public function ensureCustomFieldGroup(string $title): int
    {
        $existing = $this->loadCustomByTitle($title, 'G');
        if ($existing !== null) {
            return (int) $existing->virtuemart_custom_id;
        }

        $model = VmModel::getModel('custom');
        $data  = [
            'virtuemart_custom_id' => 0,
            'custom_title'         => $title,
            'custom_value'         => '',
            'field_type'           => 'G',
            'is_list'              => 0,
            'is_cart_attribute'    => 0,
            'is_input'             => 0,
            'is_searchable'        => 0,
            'layout_pos'           => '',
            'ordering'             => 0,
            'published'            => 1,
        ];

        $id = (int) $model->store($data);
        if ($id <= 0) {
            throw new RuntimeException(sprintf('Unable to create custom field group "%s".', $title));
        }

        $this->log(sprintf('Created custom field group "%s" (ID %d).', $title, $id));

        return $id;
    }

    /**
     * Ensures that the list based custom field exists and returns its ID.
     */
    public function ensureListCustomField(string $title, int $groupId, int $ordering): int
    {
        $existing = $this->loadCustomByTitle($title, 'S');
        if ($existing !== null) {
            if ((int) $existing->ordering !== $ordering) {
                $this->updateCustomOrdering((int) $existing->virtuemart_custom_id, $ordering);
            }

            return (int) $existing->virtuemart_custom_id;
        }

        $model = VmModel::getModel('custom');
        $data  = [
            'virtuemart_custom_id' => 0,
            'custom_title'         => $title,
            'custom_value'         => '',
            'field_type'           => 'S',
            'is_list'              => 1,
            'is_cart_attribute'    => 0,
            'is_input'             => 0,
            'is_searchable'        => 0,
            'layout_pos'           => '',
            'custom_parent_id'     => $groupId,
            'published'            => 1,
            'ordering'             => $ordering,
        ];

        $id = (int) $model->store($data);
        if ($id <= 0) {
            throw new RuntimeException(sprintf('Unable to create custom field "%s".', $title));
        }

        $this->log(sprintf('Created custom field "%s" (ID %d).', $title, $id));

        return $id;
    }

    /**
     * Updates the list options (custom values) for a list type custom field.
     *
     * @param  int        $customId Custom field identifier.
     * @param  string[]   $values   Distinct values derived from Printful.
     */
    public function updateListOptions(int $customId, array $values): void
    {
        $existing = $this->fetchCustomValues($customId);
        $ordering = 0;

        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            if (array_key_exists($value, $existing)) {
                $row = $existing[$value];
                if ((int) $row->published === 0) {
                    $this->updateCustomValuePublished((int) $row->virtuemart_customvalue_id, 1);
                }

                $this->updateCustomValueOrdering((int) $row->virtuemart_customvalue_id, $ordering);
                unset($existing[$value]);
                $ordering++;
                continue;
            }

            $this->insertCustomValue($customId, $value, $ordering);
            $ordering++;
        }

        if ($existing !== [] && $this->deactivateObsoleteValues) {
            foreach ($existing as $row) {
                $this->updateCustomValuePublished((int) $row->virtuemart_customvalue_id, 0);
            }
        }
    }

    /**
     * Retrieves the generic child variant plugin custom ID.
     */
    public function ensureGenericChildPluginCustom(): int
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('virtuemart_custom_id'))
            ->from($this->db->quoteName('#__virtuemart_customs'))
            ->where($this->db->quoteName('field_type') . ' = ' . $this->db->quote('E'))
            ->where($this->db->quoteName('custom_element') . ' = ' . $this->db->quote('genericchild'));

        $this->db->setQuery($query);
        $id = (int) $this->db->loadResult();

        if ($id > 0) {
            return $id;
        }

        $model = VmModel::getModel('custom');
        $data  = [
            'virtuemart_custom_id' => 0,
            'custom_title'         => 'Generic Child Variant',
            'field_type'           => 'E',
            'is_list'              => 0,
            'is_cart_attribute'    => 0,
            'is_input'             => 0,
            'is_searchable'        => 0,
            'layout_pos'           => 'addtocart',
            'custom_element'       => 'genericchild',
            'published'            => 1,
        ];

        $id = (int) $model->store($data);
        if ($id <= 0) {
            throw new RuntimeException('Unable to create Generic Child Variant plugin custom field.');
        }

        $this->log(sprintf('Created Generic Child Variant plugin field (ID %d).', $id));

        return $id;
    }

    /**
     * Loads a custom field by its title and type.
     */
    private function loadCustomByTitle(string $title, string $fieldType): ?object
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__virtuemart_customs'))
            ->where($this->db->quoteName('custom_title') . ' = :title')
            ->where($this->db->quoteName('field_type') . ' = :type');

        $query->bind(':title', $title);
        $query->bind(':type', $fieldType);

        $this->db->setQuery($query);
        $row = $this->db->loadObject();

        return $row ?: null;
    }

    private function updateCustomOrdering(int $customId, int $ordering): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__virtuemart_customs'))
            ->set($this->db->quoteName('ordering') . ' = :ordering')
            ->where($this->db->quoteName('virtuemart_custom_id') . ' = :id');

        $query->bind(':ordering', $ordering, ParameterType::INTEGER);
        $query->bind(':id', $customId, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $this->db->execute();
    }

    /**
     * @return array<string, object>
     */
    private function fetchCustomValues(int $customId): array
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__virtuemart_customvalues'))
            ->where($this->db->quoteName('virtuemart_custom_id') . ' = :id');

        $query->bind(':id', $customId, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $rows = (array) $this->db->loadObjectList();

        $indexed = [];
        foreach ($rows as $row) {
            $value = trim((string) $row->custom_value);
            if ($value === '') {
                continue;
            }

            $indexed[$value] = $row;
        }

        return $indexed;
    }

    private function insertCustomValue(int $customId, string $value, int $ordering): void
    {
        $columns = [
            'virtuemart_custom_id',
            'custom_value',
            'custom_price',
            'custom_param',
            'ordering',
            'published',
            'custom_title',
        ];

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__virtuemart_customvalues'))
            ->columns($this->db->quoteName($columns))
            ->values(':custom_id, :value, 0, \'\', :ordering, 1, :title');

        $query->bind(':custom_id', $customId, ParameterType::INTEGER);
        $query->bind(':value', $value);
        $query->bind(':title', $value);
        $query->bind(':ordering', $ordering, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $this->db->execute();

        $this->log(sprintf('Added custom field option "%s" to custom ID %d.', $value, $customId));
    }

    private function updateCustomValuePublished(int $customValueId, int $state): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__virtuemart_customvalues'))
            ->set($this->db->quoteName('published') . ' = :state')
            ->where($this->db->quoteName('virtuemart_customvalue_id') . ' = :id');

        $query->bind(':state', $state, ParameterType::INTEGER);
        $query->bind(':id', $customValueId, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $this->db->execute();
    }

    private function updateCustomValueOrdering(int $customValueId, int $ordering): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__virtuemart_customvalues'))
            ->set($this->db->quoteName('ordering') . ' = :ordering')
            ->where($this->db->quoteName('virtuemart_customvalue_id') . ' = :id');

        $query->bind(':ordering', $ordering, ParameterType::INTEGER);
        $query->bind(':id', $customValueId, ParameterType::INTEGER);

        $this->db->setQuery($query);
        $this->db->execute();
    }

    private function log(string $message): void
    {
        Log::add($message, Log::INFO, 'plg_system_printfulsync');
        $this->application->enqueueMessage($message, 'message');
    }
}
