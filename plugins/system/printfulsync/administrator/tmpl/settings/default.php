<?php
\defined('_JEXEC') || die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;

$base   = $displayData['routeBase'] ?? '';
$apply  = $base . '&view=settings&task=apply';
$save   = $base . '&view=settings&task=save';
$params = $displayData['params'] ?? new \Joomla\Registry\Registry();
?>
<div class="container">
  <h1>Printful Sync – <?= Text::_('PLG_PFS_SETTINGS'); ?></h1>
  <form action="<?= $apply; ?>" method="post" class="form-validate">
    <div class="row"><div class="col-lg-6">
      <div class="control-group">
        <label for="pfs_api_key"><?= Text::_('PLG_PFS_API_KEY'); ?></label>
        <input id="pfs_api_key" type="password" name="jform[api_key]" class="form-control"
               value="<?= htmlspecialchars($params->get('api_key',''), ENT_QUOTES); ?>" />
      </div>
      <div class="control-group">
        <label for="pfs_only_parent"><?= Text::_('PLG_PFS_ONLY_PARENT_VISIBLE'); ?></label>
        <select id="pfs_only_parent" name="jform[only_parent_in_visible]" class="form-select">
          <option value="1" <?= $params->get('only_parent_in_visible',1)==1?'selected':''; ?>><?= Text::_('JYES'); ?></option>
          <option value="0" <?= $params->get('only_parent_in_visible',1)==0?'selected':''; ?>><?= Text::_('JNO'); ?></option>
        </select>
      </div>
      <div class="control-group">
        <label for="pfs_hidden_cat"><?= Text::_('PLG_PFS_HIDDEN_CAT_ID'); ?></label>
        <input id="pfs_hidden_cat" type="number" name="jform[hidden_category_id]" class="form-control"
               value="<?= (int)$params->get('hidden_category_id',0); ?>" />
      </div>
    </div></div>
    <div class="btn-toolbar" style="margin-top:12px;">
      <button type="submit" class="btn btn-primary"><?= Text::_('JAPPLY'); ?></button>
      <button type="submit" formaction="<?= $save; ?>" class="btn btn-success"><?= Text::_('JSAVE'); ?></button>
      <?= HTMLHelper::_('form.token'); ?>
    </div>
  </form>
</div>
