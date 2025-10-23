<?php
/**
 * @package     PrintfulVirtueMart
 * @subpackage  Plugin.System.PrintfulSync.Administrator
 *
 * @copyright   Copyright (C) 2024 Printful
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Plugin\System\Printfulsync\Administrator\Model;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Model\AdminModel;
use Joomla\CMS\Table\Table;
use Joomla\Registry\Registry;
use RuntimeException;

/**
 * Model for interacting with plugin configuration in the control panel.
 */
final class ControlPanelModel extends AdminModel
{
    /**
     * Cached plugin parameters.
     */
    private ?Registry $params = null;

    /**
     * Returns the JTable extension instance for the plugin.
     */
    public function getTable($type = 'extension', $prefix = 'JTable', $config = [])
    {
        return Table::getInstance($type, $prefix, $config);
    }

    /**
     * Loads the configuration form definition.
     */
    public function getForm($data = [], $loadData = true)
    {
        Form::addFormPath(__DIR__ . '/../../forms');

        $form = Form::getInstance('plg_system_printfulsync.controlpanel', 'controlpanel', [
            'control' => 'jform',
            'load_data' => $loadData,
        ]);

        if ($loadData) {
            $form->bind($this->loadFormData());
        }

        return $form;
    }

    /**
     * Persists the provided configuration values to the extensions table.
     *
     * @param  array<string, mixed> $data Form data.
     */
    public function save($data): bool
    {
        $table = $this->getTable();

        if (!$table->load(['type' => 'plugin', 'folder' => 'system', 'element' => 'printfulsync'])) {
            throw new RuntimeException(Text::_('PLG_SYSTEM_PRINTFULSYNC_EXTENSION_NOT_FOUND'));
        }

        $current = new Registry($table->params);
        $current->merge($data);

        $table->params = (string) $current;

        if (!$table->store()) {
            throw new RuntimeException(Text::_('PLG_SYSTEM_PRINTFULSYNC_SETTINGS_SAVE_ERROR'));
        }

        $this->params = $current;

        return true;
    }

    /**
     * Provides the plugin parameters as registry.
     */
    public function getParams(): Registry
    {
        if ($this->params instanceof Registry) {
            return $this->params;
        }

        $table = $this->getTable();

        if (!$table->load(['type' => 'plugin', 'folder' => 'system', 'element' => 'printfulsync'])) {
            throw new RuntimeException(Text::_('PLG_SYSTEM_PRINTFULSYNC_EXTENSION_NOT_FOUND'));
        }

        $this->params = new Registry($table->params);

        return $this->params;
    }

    /**
     * Loads the current configuration for the form binding.
     */
    protected function loadFormData(): array
    {
        return $this->getParams()->toArray();
    }
}
