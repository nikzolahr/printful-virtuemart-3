<?php
namespace Joomla\Plugin\System\Printfulsync;

\defined('_JEXEC') || die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Table\Table;
use Joomla\Registry\Registry;

final class PlgSystemPrintfulsync extends CMSPlugin
{
    protected $app;
    protected $autoloadLanguage = true;

    public function onBeforeCompileHead(): void
    {
        if (!$this->app->isClient('administrator')) return;
        $wa = $this->app->getDocument()->getWebAssetManager();
        $wa->useStyle('plg.printfulsync.admin');
        $wa->useScript('plg.printfulsync.admin');
    }

    /**
     * Aufruf im Backend:
     * /administrator/index.php?option=com_ajax&plugin=printfulsync&group=system&format=html&view=controlpanel
     */
    public function onAjaxPrintfulsync()
    {
        if (!$this->app->isClient('administrator')) {
            throw new \RuntimeException('Forbidden', 403);
        }
        $user = Factory::getUser();
        if (!$user->authorise('core.manage')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $in   = $this->app->getInput();
        $view = $in->getCmd('view', 'controlpanel');
        $task = $in->getCmd('task', '');

        $params = $this->loadParams();

        if (in_array($task, ['save','apply','sync'], true)) {
            $this->app->checkToken('post') || die('Invalid Token');
            if ($task === 'sync') {
                $this->runSyncIfAvailable();
                $view = 'controlpanel';
            } else {
                $jform = (array) $in->get('jform', [], 'array');
                $this->saveParams($jform);
                $params = $this->loadParams();
                if ($task === 'save') {
                    $url = Route::_('index.php?option=com_plugins&view=plugins&filter[folder]=system', false);
                    return '<script>location.href="'. $url .'";</script>';
                }
                $view = 'settings';
            }
        }

        $routeBase = Route::_('index.php?option=com_ajax&plugin=printfulsync&group=system&format=html', false);
        $displayData = ['routeBase'=>$routeBase,'view'=>$view,'params'=>$params];

        $layoutBase = JPATH_PLUGINS . '/system/printfulsync/administrator/tmpl';
        $layoutName = ($view === 'settings') ? 'settings/default' : 'controlpanel/default';
        return (new FileLayout($layoutName, $layoutBase))->render($displayData);
    }

    private function loadParams(): Registry
    {
        $t = Table::getInstance('extension');
        $t->load(['type'=>'plugin','folder'=>'system','element'=>'printfulsync']);
        return new Registry($t->params ?: '{}');
    }

    private function saveParams(array $jform): void
    {
        $t = Table::getInstance('extension');
        $t->load(['type'=>'plugin','folder'=>'system','element'=>'printfulsync']);
        $cur = new Registry($t->params ?: '{}');
        foreach ($jform as $k=>$v) $cur->set($k,$v);
        $t->params = (string)$cur;
        if (!$t->check() || !$t->store()) throw new \RuntimeException('Failed to save plugin params');
    }

    private function runSyncIfAvailable(): void
    {
        if (class_exists('\\PlgSystemPrintfulsync\\SyncService')) {
            try { (new \PlgSystemPrintfulsync\SyncService())->run(); }
            catch (\Throwable $e) { Factory::getApplication()->enqueueMessage('Sync error: '.$e->getMessage(),'error'); }
        } else {
            Factory::getApplication()->enqueueMessage('SyncService not found – skipped.','warning');
        }
    }
}
