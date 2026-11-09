<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use DomainException;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Cms\Content\CmsDisplaySettings;
use Lemonade\Admin\Cms\Content\CmsPublicationEditorValues;
use Lemonade\Admin\Editor\Contract\EditorAccessPolicyInterface;
use Lemonade\Admin\Editor\Contract\EditorFieldAccessPolicyInterface;
use Lemonade\Admin\Editor\Contract\EditorProviderInterface;
use Lemonade\Admin\Editor\EditorDefinition;
use Lemonade\Admin\Editor\EditorFieldDefinition;
use Lemonade\Admin\Editor\EditorSaveResult;
use Lemonade\Admin\Editor\Exception\EditorEntityNotFoundException;
use Lemonade\Admin\Editor\Exception\EditorValidationException;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Cms\Content\CmsContentInput;
use Lemonade\Cms\Content\CmsContentLifecycle;
use Lemonade\Cms\Content\CmsContentReadModel;
use Lemonade\Cms\Content\CmsContentValidationException;
use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Validation\ValidationSchema;

/**
 * Propojuje editor zaznamu s existujicim CMS lifecycle
 */
final readonly class RecordEditor implements
    EditorProviderInterface,
    EditorAccessPolicyInterface,
    EditorFieldAccessPolicyInterface
{
    /**
     * Pripojuje CMS persistenci, validaci, jazyky a autorizaci editoru
     *
     * @param CmsContentLifecycle<RecordData> $service
     */
    public function __construct(
        private RecordModule $module,
        private RecordValues $values,
        private CmsContentReadModel $content,
        private CmsContentLifecycle $service,
        private LanguageRegistry $languages,
        private LocalActorGuard $actors,
        private RecordEditorValidationSchema $validation,
        private AdminModuleRouteResolver $routes,
        private UrlGenerator $urls,
        private AuthorizationService $authorization,
        private TranslatorInterface $translator,
    ) {}

    /**
     * Deklaruje transportni pole editoru vcetne dynamic translations payloadu
     */
    public function editorDefinition(): EditorDefinition
    {
        return new EditorDefinition(
            loadPermission: $this->module->code() . '.view_own',
            savePermission: $this->module->code() . '.edit_own',
            createPermission: $this->module->code() . '.create',
            fields: [
                new EditorFieldDefinition(
                    name: 'publication_state',
                    type: 'text',
                    readOnly: false,
                    permission: $this->module->code() . '.publish_own',
                ),
                new EditorFieldDefinition(name: 'published_at', type: 'text', readOnly: false, permission: $this->module->code() . '.publish_own'),
                new EditorFieldDefinition(name: 'author_name', type: 'text', readOnly: false, permission: null),
                ...CmsDisplaySettings::fields(),
                ...array_map(static fn(array $field): EditorFieldDefinition => new EditorFieldDefinition(
                    'field_' . $field['id'],
                    'text',
                    false,
                    null,
                ), $this->values->activeFields()),
                new EditorFieldDefinition(name: 'locale', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'title', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'summary', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'content', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'page_title', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'meta_description', type: 'text', readOnly: false, permission: null),
                new EditorFieldDefinition(name: 'tags', type: 'text', readOnly: false, permission: null),
            ],
        );
    }

    /**
     * Overi globalni nebo vlastnicky pristup ke cteni zaznamu
     */
    public function loadAccessDecision(int $id): bool
    {
        return $this->authorization->canForOwner(
            $this->module->code() . '.view',
            $this->module->code() . '.view_own',
            $this->content->ownerId($id),
        );
    }

    /**
     * Overi globalni nebo vlastnicky pristup k beznemu ulozeni zaznamu
     */
    public function saveAccessDecision(int $id): bool
    {
        return $this->authorization->canForOwner(
            $this->module->code() . '.edit',
            $this->module->code() . '.edit_own',
            $this->content->ownerId($id),
        );
    }

    /**
     * Overi publish pole proti aktualnimu vlastnikovi root aggregate
     */
    public function fieldAccessDecision(int $id, string $field): ?bool
    {
        if (!in_array($field, ['publication_state', 'published_at'], true)) {
            return null;
        }

        return $this->authorization->canForOwner(
            $this->module->code() . '.publish',
            $this->module->code() . '.publish_own',
            $this->content->ownerId($id),
        );
    }

    /**
     * Nacte existujici obsahovy zaznam pro editaci
     */
    public function updateData(int $id): array
    {
        $article = $this->content->findForEditor($id);
        if ($article === null) {
            throw new EditorEntityNotFoundException('Content record not found.');
        }

        return ['article' => $article, 'locales' => $this->content->editorLocales($id)];
    }

    /**
     * Vrati prazdny editorovy stav pro nepouzitou fallback create route
     */
    public function createData(): array
    {
        $locales = $this->languages->enabledLocales();

        return [
            'article' => [
                'id' => null,
                'author_name' => null,
                ...CmsContentInput::DISPLAY_DEFAULTS,
                'translations' => [],
            ],
            'locales' => $locales,
        ];
    }

    /**
     * Vrati prazdnou validaci pro okamzite zalozeni draftu bez vstupu uzivatele
     */
    public function createValidationSchema(): ValidationSchema
    {
        return ValidationSchema::create();
    }

    /**
     * Vrati validaci pro zmenu globalnich dat a aktualni content mutation
     */
    public function updateValidationSchema(int $id): ValidationSchema
    {
        $schema = $this->validation->forUpdate();
        foreach ($this->values->activeFields() as $field) {
            $schema->field('field_' . $field['id'], (string) $field['name']);
        }
        return $schema;
    }

    /**
     * Ulozi predane neprazdne mutation a lifecycle zaznamu
     */
    public function update(int $id, array $data): EditorSaveResult
    {
        try {
            $actorId = $this->actors->requireLocalUser()->id();
            $this->service->save($actorId, $id, CmsContentInput::fromEditor($data), RecordData::fromEditor($data));
        } catch (DefinitionValidationException $exception) {
            throw new EditorValidationException(implode(' ', $exception->errors()), previous: $exception, errors: $exception->errors());
        } catch (CmsContentValidationException $exception) {
            throw new EditorValidationException(
                $exception->getMessage(),
                previous: $exception,
                errors: array_map($this->translator->get(...), $exception->errors()),
            );
        } catch (DomainException $exception) {
            throw new EditorValidationException($exception->getMessage(), previous: $exception);
        }

        return new EditorSaveResult(
            data: [
                'id' => $id,
                'editUrl' => $this->editUrl($id, (string) $data['locale']),
                'editorFields' => isset($data['publication_state'])
                    ? CmsPublicationEditorValues::forLocale($this->updateData($id)['article']['translations'], (string) $data['locale'])
                    : [],
            ],
            messageKey: 'records.editor.updated',
        );
    }

    /**
     * Zalozi minimalni draft v canonical vychozim content locale
     */
    public function create(array $data): EditorSaveResult
    {
        try {
            $actorId = $this->actors->requireLocalUser()->id();
            $locale = $this->languages->defaultLocale();
            $articleId = $this->service->createDraft($actorId, $locale);
        } catch (DefinitionValidationException $exception) {
            throw new EditorValidationException(implode(' ', $exception->errors()), previous: $exception, errors: $exception->errors());
        } catch (CmsContentValidationException $exception) {
            throw new EditorValidationException(
                $exception->getMessage(),
                previous: $exception,
                errors: array_map($this->translator->get(...), $exception->errors()),
            );
        } catch (DomainException $exception) {
            throw new EditorValidationException($exception->getMessage(), previous: $exception);
        }

        return new EditorSaveResult(
            data: [
                'id' => $articleId,
                'editUrl' => $this->editUrl($articleId, $locale),
            ],
            messageKey: 'records.editor.created',
        );
    }

    /**
     * Vytvori canonical edit URL se zachovanym content locale
     */
    private function editUrl(int $articleId, string $locale): string
    {
        return $this->urls->route(
            $this->routes->managementRouteName($this->module->segment(), 'edit'),
            ['module' => $this->module->segment(), 'id' => $articleId, 'locale' => $locale],
        );
    }
}
