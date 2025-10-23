<?php
/**
 * @package     PrintfulVirtueMart
 * @subpackage  Plugin.System.PrintfulSync.Administrator
 *
 * @copyright   Copyright (C) 2024 Printful
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Plugin\System\Printfulsync\Administrator\Controller;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Plugin\System\Printfulsync\Service\PrintfulSyncService;
use Joomla\Input\Input;
use RuntimeException;
use VmConfig;
use Throwable;
use function trim;

/**
 * Controller handling the Printful Sync control panel interactions.
 */
final class ControlPanelController extends BaseController
{
    /**
     * The default view for the controller.
     *
     * @var string
     */
    protected $default_view = 'controlpanel';

    /**
     * Class constructor.
     *
     * @param  array<string, mixed>        $config  Controller configuration.
     * @param  MVCFactoryInterface|null    $factory MVC factory.
     * @param  CMSApplicationInterface|null $app     Application instance.
     * @param  Input|null                  $input   Request input.
     */
    public function __construct(
        array $config = [],
        ?MVCFactoryInterface $factory = null,
        ?CMSApplicationInterface $app = null,
        ?Input $input = null
    ) {
        parent::__construct($config, $factory, $app, $input);
    }

    /**
     * Persists the configuration changes submitted through the control panel.
     */
    public function save(): bool
    {
        try {
            /** @var \Joomla\Plugin\System\Printfulsync\Administrator\Model\ControlPanelModel $model */
            $model = $this->getModel('ControlPanel');

            $data = (array) $this->input->get('jform', [], 'array');

            if (!$model->save($data)) {
                $this->setMessage(Text::_('PLG_SYSTEM_PRINTFULSYNC_SETTINGS_SAVE_ERROR'), 'error');
                $this->setRedirect(Route::_('index.php?option=plg_printfulsync', false));

                return false;
            }
        } catch (Throwable $throwable) {
            $this->setMessage(
                Text::sprintf('PLG_SYSTEM_PRINTFULSYNC_SETTINGS_SAVE_ERROR_WITH_MESSAGE', $throwable->getMessage()),
                'error'
            );
            $this->setRedirect(Route::_('index.php?option=plg_printfulsync', false));

            return false;
        }

        $this->setMessage(Text::_('PLG_SYSTEM_PRINTFULSYNC_SETTINGS_SAVE_SUCCESS'));
        $this->setRedirect(Route::_('index.php?option=plg_printfulsync', false));

        return true;
    }

    /**
     * Navigates back to the plugin manager without persisting changes.
     */
    public function cancel(): bool
    {
        $this->setRedirect(Route::_('index.php?option=com_plugins&view=plugins&filter[folder]=system'), null);

        return true;
    }

    /**
     * Executes a manual Printful synchronisation using the provided payload.
     */
    public function sync(): bool
    {
        $payload = (string) $this->input->get('payload', '', 'raw');

        if ($payload === '') {
            $this->setMessage(Text::_('PLG_SYSTEM_PRINTFULSYNC_PAYLOAD_REQUIRED'), 'warning');
            $this->setRedirect(Route::_('index.php?option=plg_printfulsync', false));

            return false;
        }

        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            $this->setMessage(Text::_('PLG_SYSTEM_PRINTFULSYNC_PAYLOAD_INVALID'), 'error');
            $this->setRedirect(Route::_('index.php?option=plg_printfulsync', false));

            return false;
        }

        try {
            PluginHelper::importPlugin('system', 'printfulsync');

            $results = (array) $this->app->triggerEvent('onPrintfulSyncProduct', [$decoded]);

            if (in_array(false, $results, true)) {
                $this->setMessage(Text::_('PLG_SYSTEM_PRINTFULSYNC_SYNC_ERROR_GENERIC'), 'error');
            } else {
                $this->setMessage(Text::_('PLG_SYSTEM_PRINTFULSYNC_SYNC_SUCCESS'));
            }
        } catch (Throwable $throwable) {
            $this->setMessage(
                Text::sprintf('PLG_SYSTEM_PRINTFULSYNC_SYNC_ERROR', $throwable->getMessage()),
                'error'
            );
        }

        $this->setRedirect(Route::_('index.php?option=plg_printfulsync', false));

        return true;
    }

    /**
     * Executes an automatic synchronisation run against the Printful API.
     */
    public function syncAll(): bool
    {
        try {
            /** @var \Joomla\Plugin\System\Printfulsync\Administrator\Model\ControlPanelModel $model */
            $model  = $this->getModel('ControlPanel');
            $params = $model->getParams();

            $apiToken = trim((string) $params->get('api_token', ''));
            $storeId  = trim((string) $params->get('store_id', ''));

            if ($apiToken === '' || $storeId === '') {
                $this->setMessage(Text::_('PLG_SYSTEM_PRINTFULSYNC_SYNC_CREDENTIALS_MISSING'), 'warning');
                $this->setRedirect(Route::_('index.php?option=plg_printfulsync', false));

                return false;
            }

            PluginHelper::importPlugin('system', 'printfulsync');

            if (!class_exists('VmConfig')) {
                throw new RuntimeException('VirtueMart configuration is not available.');
            }

            VmConfig::loadConfig();

            $service = new PrintfulSyncService($params, $this->app);
            $synced  = $service->syncAllFromPrintful();

            if ($synced === 0) {
                $this->setMessage(Text::_('PLG_SYSTEM_PRINTFULSYNC_SYNC_ALL_SUCCESS_NONE'));
            } elseif ($synced === 1) {
                $this->setMessage(Text::_('PLG_SYSTEM_PRINTFULSYNC_SYNC_ALL_SUCCESS_SINGLE'));
            } else {
                $this->setMessage(Text::sprintf('PLG_SYSTEM_PRINTFULSYNC_SYNC_ALL_SUCCESS_MULTIPLE', $synced));
            }
        } catch (Throwable $throwable) {
            $this->setMessage(
                Text::sprintf('PLG_SYSTEM_PRINTFULSYNC_SYNC_ALL_ERROR', $throwable->getMessage()),
                'error'
            );
        }

        $this->setRedirect(Route::_('index.php?option=plg_printfulsync', false));

        return true;
    }

    /**
     * Populates the document with canonical URL information.
     */
    protected function prepareExecute(): void
    {
        parent::prepareExecute();

        if ($this->app !== null) {
            $uri = Uri::getInstance();
            $this->app->getDocument()->setMetaData('canonical', (string) $uri);
        }
    }
}
