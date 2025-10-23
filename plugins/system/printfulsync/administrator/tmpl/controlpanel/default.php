<?php
\defined('_JEXEC') || die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;

$base = $displayData['routeBase'] ?? '';
$settingsUrl = $base . '&view=settings';
$syncUrl     = $base . '&view=controlpanel&task=sync';
?>
<div class="container">
  <h1>Printful Sync – Control Panel</h1>
  <div class="btn-toolbar" style="margin:12px 0;">
    <a class="btn btn-primary" href="<?= $settingsUrl; ?>"><?= Text::_('PLG_PFS_BTN_SETTINGS'); ?></a>
    <form action="<?= $syncUrl; ?>" method="post" style="display:inline-block;margin-left:8px;">
      <button type="submit" class="btn btn-success"><?= Text::_('PLG_PFS_BTN_SYNC_NOW'); ?></button>
      <?= HTMLHelper::_('form.token'); ?>
    </form>
  </div>
  <div class="card"><div class="card-body">
    <p><?= Text::_('PLG_PFS_CP_HELP'); ?></p>
    <ul><li><?= Text::_('PLG_PFS_CP_ITEM1'); ?></li><li><?= Text::_('PLG_PFS_CP_ITEM2'); ?></li></ul>
  </div></div>
</div>
