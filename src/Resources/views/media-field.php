<?php

declare(strict_types=1);

use Lemonade\Admin\Presentation\AdminFileUploadCollection;
use Lemonade\Framework\View\View;
use Lemonade\Framework\View\ViewHelpers;

/**
 * @var array<string,mixed> $definition
 * @var AdminFileUploadCollection|null $uploadCollection
 * @var ViewHelpers $helpers
 * @var View $this
 */
$id = (string) ($definition['html_id'] ?? 'field-' . $definition['code']);
?>
<div id="<?= e($id) ?>" class="<?= e((string) ($definition['css_class'] ?? '')) ?>" role="group" aria-labelledby="<?= e($id) ?>-label">
    <div id="<?= e($id) ?>-label" class="lm-form-label"><?= e((string) $definition['name']) ?></div>
    <?php if ($uploadCollection !== null): ?>
        <?= $this->partial('admin::components.file-upload-collection', [
            'uploadCollection' => $uploadCollection,
            'singleImageLayout' => false,
        ]) ?>
    <?php endif ?>
    <?php if (($definition['help'] ?? '') !== ''): ?>
        <div class="lm-form-help"><?= e((string) $definition['help']) ?></div>
    <?php endif ?>
</div>
