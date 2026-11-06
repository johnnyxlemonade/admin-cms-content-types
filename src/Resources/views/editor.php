<?php

declare(strict_types=1);

use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorFieldRenderer;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderContext;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderer;
use Lemonade\Admin\Localization\ClientTranslationVersion;
use Lemonade\Framework\View\View;
use Lemonade\Framework\View\ViewHelpers;

/**
 * @var ViewHelpers $helpers
 * @var ClientTranslationVersion $clientTranslationVersion
 * @var View $this
 * @var AdminEditorDefinition $adminEditor
 * @var AdminEditorRenderContext $adminEditorContext
 * @var AdminEditorFieldRenderer $adminEditorFields
 */
?>
<div class="lm-field-builder-editor" data-lemonade-i18n-namespace="content-types" data-lemonade-i18n-namespace-source="<?= e($helpers->url('admin.resources.i18n', ['group' => 'content-types'])) ?>?locale={locale}&amp;v={version}" data-lemonade-i18n-namespace-versions="<?= e(json_encode($clientTranslationVersion->versions('content-types'), JSON_THROW_ON_ERROR)) ?>">
    <?= (new AdminEditorRenderer($this, $helpers, $adminEditorFields))->render($adminEditor, $adminEditorContext) ?>
</div>
