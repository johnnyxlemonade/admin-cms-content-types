<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use Lemonade\Admin\Action\Presentation\AdminActionFormDefinition;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Cms\Content\CmsDisplaySettings;
use Lemonade\Admin\DataGrid\DataGridIndexViewModel;
use Lemonade\Admin\DataGrid\DataGridPrimaryAction;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextItem;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderContext;
use Lemonade\Admin\Editor\EditorLoaded;
use Lemonade\Admin\Editor\Exception\EditorEntityNotFoundException;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Identity\LocalUserDirectory;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Page\Contract\ModuleEditorPageProviderInterface;
use Lemonade\Admin\Page\Contract\ModuleIndexPageProviderInterface;
use Lemonade\Admin\Page\ModulePage;
use Lemonade\Admin\Presentation\AdminFileUploadCollection;
use Lemonade\Admin\Presentation\AdminFileUploadComponent;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;

final readonly class RecordPageProvider implements ModuleIndexPageProviderInterface, ModuleEditorPageProviderInterface
{
    public function __construct(
        private RecordModule $module,
        private RecordValues $values,
        private RecordDataGrid $grid,
        private AuthorizationService $authorization,
        private TranslatorInterface $translator,
        private UrlGenerator $urls,
        private AdminModuleRouteResolver $routes,
        private RecordEditorDefinition $adminEditorDefinitions,
        private LocalUserDirectory $users,
        private AdminFileUploadComponent $files,
    ) {}

    public function indexPermission(): string
    {
        return $this->module->code() . '.view_own';
    }

    public function index(string $locale): ModulePage
    {
        $viewModel = new DataGridIndexViewModel(
            moduleCode: $this->module->segment(),
            title: $this->module->name(),
            description: $this->translator->get('records.list.description'),
            endpoint: $this->urls->route(
                name: 'admin.api.datagrid.index',
                params: ['module' => $this->module->segment()],
            ),
            definition: $this->grid->dataGridDefinition(),
            primaryAction: $this->createAction(),
            loadingText: $this->translator->get('records.list.loading'),
            emptyText: $this->translator->get('records.list.empty'),
            errorText: $this->translator->get('admin.datagrid.errorDescription'),
            translationGroup: 'records',
            translateTitle: false,
        );

        return new ModulePage(
            view: 'admin::datagrid.index',
            title: $viewModel->title(),
            data: ['dataGrid' => $viewModel, 'locale' => $locale],
        );
    }

    private function createAction(): ?DataGridPrimaryAction
    {
        if (!$this->module->enabled() || !$this->authorization->hasPermission($this->module->code() . '.create')) {
            return null;
        }

        return DataGridPrimaryAction::action(
            translationKey: 'records.actions.create',
            icon: AdminIcon::PlusLg,
            form: new AdminActionFormDefinition(
                id: 'content-record-create-form',
                action: $this->urls->route(
                    name: $this->routes->managementRouteName($this->module->segment(), 'store'),
                    params: ['module' => $this->module->segment()],
                ),
                actionUrl: $this->urls->route(
                    name: $this->routes->managementRouteName($this->module->segment(), 'ajax.create'),
                    params: ['module' => $this->module->segment()],
                ),
                actionKey: 'create',
                navigateToEditAfterCreate: true,
            ),
        );
    }

    /**
     * Creation saves a draft immediately and redirects to its editor.
     *
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     * @param array<string,mixed> $query
     */
    public function create(EditorLoaded $editor, array $errors, array $input, string $locale, array $query = []): ModulePage
    {
        throw new EditorEntityNotFoundException('Content records do not provide a full-page create editor.');
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     * @param array<string,mixed> $query
     */
    public function editor(EditorLoaded $editor, array $errors, array $input, string $locale, array $query = []): ModulePage
    {
        $locales = $editor->data()['locales'];
        $selectedLocale = $this->resolveContentLocale($locales, $query);
        $article = $editor->data()['article'];
        $otherPublishedLocale = array_filter(
            $article['translations'],
            static fn(array $translation): bool => $translation['locale'] !== $selectedLocale['code']
                && (int) $translation['publication_state'] === CmsPublicationState::Published->value,
        ) !== [];
        $article['translations'] = array_values(array_filter(
            $article['translations'],
            static fn(array $translation): bool => $translation['locale'] === $selectedLocale['code'],
        ));
        $context = $this->localeContext(
            locales: $locales,
            selectedLocale: $selectedLocale['code'],
            articleId: $article['id'],
        );

        return new ModulePage(
            view: 'admin::editor',
            title: $this->translator->get('records.editor.title'),
            data: [
                'adminEditor' => $this->editorDefinition(
                    article: $article,
                    contentLocale: $selectedLocale['code'],
                    otherPublishedLocale: $otherPublishedLocale,
                    context: $context,
                ),
                'adminEditorContext' => new AdminEditorRenderContext(
                    values: $this->editorValues($article, $selectedLocale['code']),
                    oldInput: $input,
                    errors: $errors,
                    mode: 'edit',
                    moduleCode: $this->module->code(),
                    entityId: (int) $article['id'],
                ),
                'locale' => $locale,
            ],
        );
    }

    /**
     * @param array<string,mixed> $article
     */
    private function editorDefinition(
        array $article,
        string $contentLocale,
        bool $otherPublishedLocale,
        AdminEditorHeaderContextDefinition $context,
    ): AdminEditorDefinition {
        $ownerId = $article['owner_id'] === null ? null : (int) $article['owner_id'];
        $canAssignOwner = $this->authorization->hasPermission($this->module->code() . '.assign_owner')
            && $this->authorization->canForOwner($this->module->code() . '.view', $this->module->code() . '.view_own', $ownerId);
        $canEdit = $this->authorization->canForOwner(
            $this->module->code() . '.edit',
            $this->module->code() . '.edit_own',
            $ownerId,
        );

        $canPublish = $this->authorization->canForOwner(
            $this->module->code() . '.publish',
            $this->module->code() . '.publish_own',
            $ownerId,
        );
        $ownerOptions = $canAssignOwner ? $this->ownerOptions() : [];
        $articleId = (int) $article['id'];
        $galleryUpload = $this->module->galleryEnabled()
            ? $this->files->collection(
                module: $this->module->code(),
                entityId: $articleId,
                usage: 'gallery',
                labelKey: 'admin.file_upload.gallery',
                helpKey: 'admin.file_upload.gallery_help',
                editable: $canEdit,
            )
            : null;
        $attachmentUpload = $this->module->attachmentsEnabled()
            ? $this->files->collection(
                module: $this->module->code(),
                entityId: $articleId,
                usage: 'attachment',
                labelKey: 'admin.file_upload.attachments',
                helpKey: 'admin.file_upload.attachments_help',
                editable: $canEdit,
            )
            : null;
        $thumbnailUpload = $this->module->thumbnailEnabled()
            ? $this->files->collection(
                module: $this->module->code(),
                entityId: $articleId,
                usage: 'thumbnail',
                labelKey: 'records.fields.thumbnail',
                helpKey: 'records.editor.thumbnail.help',
                editable: $canEdit,
                alt: (string) ($article['translations'][0]['title'] ?? ''),
            )
            : null;

        return $this->adminEditorDefinitions->create(
            article: $article,
            contentLocale: $contentLocale,
            canPublish: $canPublish,
            canAssignOwner: $canAssignOwner,
            otherPublishedLocale: $otherPublishedLocale,
            ownerOptions: $ownerOptions,
            mediaUploads: $this->mediaUploads($articleId, $canEdit),
            context: $context,
            galleryUpload: $galleryUpload,
            attachmentUpload: $attachmentUpload,
            thumbnailUpload: $thumbnailUpload,
        );
    }

    /**
     * @param array<string,mixed> $article
     * @return array<string,mixed>
     */
    private function editorValues(array $article, string $contentLocale): array
    {
        $translation = $article['translations'][0] ?? [];
        $canonicalPath = trim((string) ($translation['canonical_path'] ?? ''), '/');
        $ownerId = $article['owner_id'] === null ? null : (int) $article['owner_id'];
        $publicationState = CmsPublicationState::from((int) ($translation['publication_state'] ?? CmsPublicationState::New->value));

        return [
            ...$this->values->editorValues((int) $article['id'], $contentLocale),
            'title' => (string) ($translation['title'] ?? ''),
            'author_name' => (string) ($article['author_name'] ?? ''),
            'tags' => $translation['tags'] ?? [],
            'summary' => (string) ($translation['summary'] ?? ''),
            'content' => (string) ($translation['content'] ?? ''),
            'page_title' => (string) ($translation['page_title'] ?? ''),
            'meta_description' => (string) ($translation['meta_description'] ?? ''),
            'url_segment' => $canonicalPath === '' ? '' : basename($canonicalPath),
            ...CmsDisplaySettings::values($article),
            'publication_state' => $publicationState->editorValue(),
            'published_at' => (string) ($translation['published_at'] ?? ''),
            'created_by_name' => (string) ($article['created_by_name'] ?? '—'),
            'created_at' => (string) ($article['created_at'] ?? ''),
            'updated_by_name' => (string) ($article['updated_by_name'] ?? '—'),
            'updated_at' => (string) ($article['updated_at'] ?? ''),
            'owner_name' => (string) ($article['owner_name'] ?? '—'),
            'owner_id' => $ownerId === null ? '' : (string) $ownerId,
            'deleted_by_name' => (string) ($article['deleted_by_name'] ?? '—'),
            'deleted_at' => (string) ($article['deleted_at'] ?? ''),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function ownerOptions(): array
    {
        $options = [];
        foreach ($this->users->assignableUsers() as $user) {
            $options[$user['id']] = $user['label'];
        }

        return $options;
    }

    /**
     * @param list<array<string,mixed>> $locales
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function resolveContentLocale(array $locales, array $query): array
    {
        $requested = strtolower(trim((string) ($query['locale'] ?? '')));
        if ($requested !== '') {
            foreach ($locales as $locale) {
                if ($locale['code'] === $requested) {
                    return $locale;
                }
            }

            throw new EditorEntityNotFoundException('Content locale is not available.');
        }

        foreach ($locales as $locale) {
            if ((int) ($locale['is_default'] ?? 0) === 1) {
                return $locale;
            }
        }

        return $locales[0] ?? throw new EditorEntityNotFoundException('Content locale is not available.');
    }

    /**
     * @param list<array<string,mixed>> $locales
     */
    private function localeContext(array $locales, string $selectedLocale, ?int $articleId): AdminEditorHeaderContextDefinition
    {
        $items = [];
        foreach ($locales as $locale) {
            $code = (string) $locale['code'];
            $enabled = (int) ($locale['enabled'] ?? 1) === 1;
            $hasTranslation = (int) ($locale['has_translation'] ?? 0) === 1;
            $statusKey = match (true) {
                !$enabled => 'records.editor.locale.disabled',
                $hasTranslation => 'records.editor.locale.existing_translation',
                default => 'records.editor.locale.new_translation',
            };
            $items[] = new AdminEditorHeaderContextItem(
                label: (string) $locale['name'],
                href: $this->urls->route(
                    name: $this->routes->managementRouteName($this->module->segment(), 'edit'),
                    params: ['module' => $this->module->segment(), 'id' => $articleId, 'locale' => $code],
                ),
                active: $code === $selectedLocale,
                secondary: $this->translator->get($statusKey),
                code: $code,
                translationExists: $hasTranslation,
            );
        }

        return new AdminEditorHeaderContextDefinition(
            label: $this->translator->get('records.editor.locale.label'),
            items: $items,
        );
    }

    /**
     * @return array<int,AdminFileUploadCollection>
     */
    private function mediaUploads(int $id, bool $editable): array
    {
        $collections = [];
        foreach ($this->values->activeFields() as $field) {
            if (!in_array($field['kind'], ['image', 'gallery', 'file', 'files'], true)) {
                continue;
            }
            $fieldId = (int) $field['id'];
            $image = in_array($field['kind'], ['image', 'gallery'], true);
            $collections[$fieldId] = $this->files->collection(
                module: $this->module->code(),
                entityId: $id,
                usage: FieldTypeRegistry::mediaUsage($fieldId),
                labelKey: 'content-types.kinds.' . $field['kind'],
                helpKey: $image ? 'admin.file_upload.gallery_help' : 'admin.file_upload.attachments_help',
                editable: $editable,
                alt: (string) $field['name'],
            );
        }
        return $collections;
    }
}
