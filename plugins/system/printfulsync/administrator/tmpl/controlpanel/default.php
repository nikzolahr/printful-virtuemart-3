<?php
/** @var Joomla\Plugin\System\Printfulsync\Administrator\View\ControlPanel\HtmlView $this */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

HTMLHelper::_('bootstrap.tab');
HTMLHelper::_('behavior.formvalidator');
HTMLHelper::_('behavior.keepalive');
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2><?php echo Text::_('PLG_SYSTEM_PRINTFULSYNC_TITLE_CONTROL_PANEL'); ?></h2>
            <p class="text-muted">
                <?php echo Text::_('PLG_SYSTEM_PRINTFULSYNC_CONTROL_PANEL_INTRO'); ?>
            </p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <form action="<?php echo Route::_('index.php?option=plg_printfulsync'); ?>"
                  method="post"
                  class="form-validate card"
                  id="adminForm">
                <div class="card-header">
                    <h3 class="card-title mb-0">
                        <?php echo Text::_('PLG_SYSTEM_PRINTFULSYNC_CONFIGURATION_LEGEND'); ?>
                    </h3>
                </div>
                <div class="card-body">
                    <?php echo $this->form->renderFieldset('general'); ?>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <?php echo Text::_('PLG_SYSTEM_PRINTFULSYNC_BUTTON_SAVE_SETTINGS'); ?>
                    </button>
                    <input type="hidden" name="task" value="controlpanel.save">
                    <?php echo HTMLHelper::_('form.token'); ?>
                </div>
            </form>
        </div>

        <div class="col-lg-6">
            <form action="<?php echo Route::_('index.php?option=plg_printfulsync'); ?>"
                  method="post"
                  class="card"
                  id="syncForm">
                <div class="card-header">
                    <h3 class="card-title mb-0">
                        <?php echo Text::_('PLG_SYSTEM_PRINTFULSYNC_SYNC_SECTION_TITLE'); ?>
                    </h3>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="payload">
                            <?php echo Text::_('PLG_SYSTEM_PRINTFULSYNC_SYNC_PAYLOAD_LABEL'); ?>
                        </label>
                        <textarea name="payload" id="payload" class="form-control" rows="12" required placeholder="<?php echo Text::_('PLG_SYSTEM_PRINTFULSYNC_SYNC_PAYLOAD_PLACEHOLDER'); ?>"></textarea>
                        <div class="form-text">
                            <?php echo Text::_('PLG_SYSTEM_PRINTFULSYNC_SYNC_PAYLOAD_DESC'); ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex flex-column flex-md-row justify-content-end gap-2">
                    <div class="d-grid d-md-inline-flex">
                        <button type="submit"
                                class="btn btn-success"
                                onclick="document.getElementById('syncTask').value='controlpanel.sync';">
                            <?php echo Text::_('PLG_SYSTEM_PRINTFULSYNC_BUTTON_RUN_SYNC'); ?>
                        </button>
                    </div>
                    <div class="d-grid d-md-inline-flex">
                        <button type="submit"
                                class="btn btn-primary"
                                onclick="document.getElementById('syncTask').value='controlpanel.syncAll';">
                            <?php echo Text::_('PLG_SYSTEM_PRINTFULSYNC_BUTTON_RUN_SYNC_ALL'); ?>
                        </button>
                    </div>
                    <input type="hidden" name="task" id="syncTask" value="controlpanel.sync">
                    <?php echo HTMLHelper::_('form.token'); ?>
                </div>
            </form>
        </div>
    </div>
</div>
