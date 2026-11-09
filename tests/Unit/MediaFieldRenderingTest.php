<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use DOMDocument;
use DOMXPath;
use Lemonade\Admin\Action\Presentation\AdminActionFormDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorBuilder;
use Lemonade\Admin\Editor\AdminEditor\Layout\CustomViewBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldColumn;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderContext;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderer;
use Lemonade\Admin\Presentation\AdminFilePresentation;
use Lemonade\Admin\Presentation\AdminFileUploadCollection;
use Lemonade\Admin\Presentation\AdminFileUploadProfilePresentation;
use Lemonade\Framework\Localization\Config\LocalizationConfig;
use Lemonade\Framework\Localization\Config\LocalizationUrlConfig;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Security\Csrf\CsrfViewHelper;
use Lemonade\Framework\View\View;
use Lemonade\Framework\View\ViewHelpers;
use Lemonade\Framework\View\ViewResourceRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MediaFieldRenderingTest extends TestCase
{
    public function testCollectionsRenderInColumnsWithReadOnlyAccess(): void
    {
        $resources = new ViewResourceRegistry();
        $resources->register('content-types', dirname(__DIR__, 2) . '/src/Resources/views');
        $collectionFile = (new ReflectionClass(AdminFileUploadCollection::class))->getFileName();
        self::assertIsString($collectionFile);
        $resources->register('admin', dirname($collectionFile, 2) . '/Resources/views');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('get')->willReturnArgument(0);
        $helpers = new ViewHelpers(
            new UrlGenerator(new Router()),
            (new ReflectionClass(CsrfViewHelper::class))->newInstanceWithoutConstructor(),
            $translator,
            new LocalizationConfig('cs', 'en', ['cs', 'en'], new LocalizationUrlConfig(false, '', '', 'locale', false)),
        );
        $view = new View(resources: $resources);
        $view->share('helpers', $helpers);
        foreach (['image', 'gallery', 'file', 'files'] as $kind) {
            foreach ([true, false] as $editable) {
                $image = in_array($kind, ['image', 'gallery'], true);
                $multiple = in_array($kind, ['gallery', 'files'], true);
                $collection = new AdminFileUploadCollection(
                    moduleCode: 'cms.type_1',
                    entityId: 12,
                    usage: 'field_8',
                    files: [['id' => 41, 'display_name' => 'Example', 'original_filename' => 'example.pdf', 'extension' => 'pdf', 'metadata' => '8 B', 'preview_url' => '/image']],
                    startUrl: '/start',
                    appendUrlTemplate: '/append',
                    completeUrlTemplate: '/complete',
                    abortUrlTemplate: '/abort',
                    removeUrlTemplate: '/remove',
                    reorderUrl: '/reorder',
                    renameModalUrlTemplate: '/rename',
                    labelKey: 'label',
                    helpKey: 'help',
                    kind: $image ? 'image' : 'file',
                    multiple: $multiple,
                    sortable: $multiple,
                    editable: $editable,
                    profile: new AdminFileUploadProfilePresentation('.pdf,.png', ['pdf', 'png'], 1000, '1 KB'),
                    presentation: AdminFilePresentation::Standard,
                    fallback: null,
                    alt: 'Example',
                );
                $editor = AdminEditorBuilder::create('media-test')
                    ->form(new AdminActionFormDefinition('media-form', '/save', csrf: false))
                    ->block(new FieldGroupBlock([new FieldColumn(new CustomViewBlock('content-types::media-field', [
                        'definition' => ['id' => 8, 'code' => 'image', 'name' => 'Field label', 'html_id' => 'custom-media', 'css_class' => 'custom-media-class', 'help' => 'Field help'],
                        'uploadCollection' => $collection,
                    ]), md: 6)]))
                    ->build();
                $html = (new AdminEditorRenderer($view))->render($editor, new AdminEditorRenderContext());
                $document = new DOMDocument();
                @$document->loadHTML($html);
                $xpath = new DOMXPath($document);
                self::assertStringContainsString('Field label', $document->textContent);
                self::assertStringContainsString('Field help', $document->textContent);
                self::assertStringContainsString('Example', $document->textContent);
                $inputs = $xpath->query('//input[@type="file"]');
                self::assertNotFalse($inputs);
                self::assertSame($editable ? 1 : 0, $inputs->length);
                if (!$editable) {
                    $buttons = $xpath->query('//button');
                    self::assertNotFalse($buttons);
                    self::assertSame(0, $buttons->length);
                }
            }
        }
    }
}
