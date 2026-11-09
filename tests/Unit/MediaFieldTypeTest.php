<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use InvalidArgumentException;
use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

final class MediaFieldTypeTest extends DefinitionTestCase
{
    /** @return iterable<string,array{string,bool,bool}> */
    public static function media(): iterable
    {
        yield 'image' => ['image', true, false];
        yield 'gallery' => ['gallery', true, true];
        yield 'file' => ['file', false, false];
        yield 'files' => ['files', false, true];
    }

    #[DataProvider('media')]
    public function testMediaContract(string $kind, bool $image, bool $multiple): void
    {
        $types = $this->types();
        $field = $this->validation()->field(['kind' => $kind, 'name' => 'Media', 'code' => 'media', 'sort_order' => 0])['values'];
        self::assertSame(0, $field['required']);
        self::assertSame(0, $field['localized']);
        self::assertNull($types->get($kind)->storage());
        self::assertNull($types->get($kind)->control('media'));
        self::assertSame([], $types->get($kind)->editorValues($field));
        $field += ['id' => 67, 'archived_at' => null];
        $usage = $types->mediaDefinition('cms.type_12', $field);
        self::assertNotNull($usage);
        self::assertSame('field_67', $usage->usage());
        self::assertSame('cms.type_12', $usage->moduleCode());
        self::assertSame($multiple, $usage->multiple());
        self::assertSame($multiple, $usage->sortable());
        self::assertSame($image ? 'image' : 'file', $usage->kind());
        self::assertSame($image ? null : 'admin-image', $usage->imageProfile());
        $field['name'] = 'Renamed';
        $field['row_id'] = 99;
        self::assertSame($usage->usage(), $types->mediaDefinition('cms.type_12', $field)?->usage());
        $field['archived_at'] = '2026-11-09 00:00:00';
        self::assertNull($types->mediaDefinition('cms.type_12', $field));
        $field['archived_at'] = null;
        self::assertSame('field_67', $types->mediaDefinition('cms.type_12', $field)?->usage());
    }

    #[DataProvider('media')]
    public function testUnsupportedSettingsAreRejected(string $kind, bool $image, bool $multiple): void
    {
        foreach (['required' => 1, 'localized' => 1, 'default_value' => '12', 'default_integer' => 12, 'min_length' => 1] as $setting => $value) {
            try {
                $this->validation()->field(['kind' => $kind, 'name' => 'Media', 'sort_order' => 0, $setting => $value]);
                self::fail('Unsupported media setting accepted: ' . $setting);
            } catch (DefinitionValidationException $exception) {
                self::assertArrayHasKey($setting, $exception->errors());
            }
        }
    }

    public function testUnpersistedFieldHasNoUsage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FieldTypeRegistry::mediaUsage(0);
    }
}
