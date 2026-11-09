<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use Lemonade\Admin\Cms\Content\CmsDisplaySettings;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Validation\ValidationSchema;

/**
 * Sklada frameworkovou validaci create a update vstupu editoru Aktualit
 */
final readonly class RecordEditorValidationSchema
{
    /**
     * Nastavuje prekladovy zdroj chyb editorove validace
     */
    public function __construct(private TranslatorInterface $translator) {}

    /**
     * Ponechava create bez formulovych hodnot pro okamzite zalozeni draftu
     */
    public function forCreate(): ValidationSchema
    {
        return ValidationSchema::create();
    }

    /**
     * Vymezuje flat payload jedne lokalizovane mutation a globalnich metadat
     */
    public function forUpdate(): ValidationSchema
    {
        return CmsDisplaySettings::validation(ValidationSchema::create()
            ->field('locale', $this->translator->get('records.editor.content'))
                ->required($this->translator->get('records.validation.locale_required'))
            ->field('title', $this->translator->get('records.fields.title'))
                ->maxLength(180, $this->translator->get('records.validation.title_max_length'))
            ->field('author_name', $this->translator->get('records.fields.author_name'))
                ->maxLength(255, $this->translator->get('records.validation.author_max_length'))
            ->field('page_title', $this->translator->get('admin.seo.page_title'))
                ->maxLength(180, $this->translator->get('records.validation.page_title_max_length'))
            ->field('meta_description', $this->translator->get('admin.seo.meta_description'))
                ->maxLength(255, $this->translator->get('records.validation.meta_description_max_length'))
            ->field('publication_state', $this->translator->get('records.fields.publication_state'))
                ->inList(array_keys(CmsPublicationState::editorOptionLabelKeys()), $this->translator->get('records.validation.publication_invalid'))
            ->field('published_at', $this->translator->get('records.fields.published_at'))
            ->field('summary', $this->translator->get('records.fields.summary'))
            ->field('content', $this->translator->get('records.fields.content'))
            ->field('tags', $this->translator->get('records.fields.tags'))
            ->end(), $this->translator);
    }
}
