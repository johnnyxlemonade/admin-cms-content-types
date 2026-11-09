<?php

declare(strict_types=1);

use Lemonade\Framework\View\ViewHelpers;

/**
 * @var ViewHelpers $helpers
 */
?>
<div class="lm-content-type-navigation">
    <div class="lm-content-type-navigation-art" aria-hidden="true">
        <span class="lm-content-type-navigation-sheet lm-content-type-navigation-sheet-back"></span>
        <span class="lm-content-type-navigation-sheet lm-content-type-navigation-sheet-middle"></span>
        <span class="lm-content-type-navigation-sheet lm-content-type-navigation-sheet-front">
            <i class="bi bi-image"></i>
            <span class="lm-content-type-navigation-lines"><span></span><span></span></span>
            <span class="lm-content-type-navigation-switch"></span>
        </span>
    </div>
    <nav class="lm-content-type-navigation-links" aria-label="<?= e($helpers->lang('content-types.editor.section_navigation')) ?>" data-lemonade-i18n-aria-label="content-types.editor.section_navigation">
        <a href="#cms.content-types-section-type">
            <span class="lm-content-type-navigation-icon" aria-hidden="true"><i class="bi bi-gear"></i></span>
            <span class="lm-content-type-navigation-copy">
                <strong data-lemonade-i18n="content-types.editor.basic_information"><?= e($helpers->lang('content-types.editor.basic_information')) ?></strong>
                <small data-lemonade-i18n="content-types.editor.basic_information_navigation_help"><?= e($helpers->lang('content-types.editor.basic_information_navigation_help')) ?></small>
            </span>
        </a>
        <a href="#cms.content-types-section-system-features">
            <span class="lm-content-type-navigation-icon" aria-hidden="true"><i class="bi bi-images"></i></span>
            <span class="lm-content-type-navigation-copy">
                <strong data-lemonade-i18n="content-types.editor.system_features"><?= e($helpers->lang('content-types.editor.system_features')) ?></strong>
                <small data-lemonade-i18n="content-types.editor.system_features_navigation_help"><?= e($helpers->lang('content-types.editor.system_features_navigation_help')) ?></small>
            </span>
        </a>
    </nav>
</div>
