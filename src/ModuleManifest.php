<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes;

use Lemonade\Admin\Audit\AuditEventDefinition;
use Lemonade\Admin\Authorization\PermissionDefinition;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Module\AdminModuleMetadata;
use Lemonade\Admin\Module\AdminNavigationGroup;
use Lemonade\Admin\Module\Contract\AdminModuleDefinitionInterface;
use Lemonade\Admin\Modules\Manifest\ModuleManifestInterface;
use Lemonade\Admin\Modules\Migration\ModuleMigrationManifestInterface;
use Lemonade\Cms\ContentTypes\Migrations\AddContentTypeSystemMedia;
use Lemonade\Cms\ContentTypes\Migrations\AddContentTypeThumbnail;
use Lemonade\Cms\ContentTypes\Migrations\CreateContentFieldDefinitions;
use Lemonade\Cms\ContentTypes\Migrations\CreateContentRecords;
use Lemonade\Cms\ContentTypes\Migrations\CreateContentTypes;
use Lemonade\Framework\Database\Migration\MigrationInterface;

final readonly class ModuleManifest implements ModuleManifestInterface, ModuleMigrationManifestInterface, AdminModuleDefinitionInterface
{
    public const string CODE = 'cms.content-types';

    public function code(): string
    {
        return self::CODE;
    }

    public function labelKey(): string
    {
        return 'content-types.module.name';
    }

    public function runtimeProvider(): string
    {
        return CmsContentTypesServiceProvider::class;
    }

    /**
     * @return list<class-string<MigrationInterface>>
     */
    public function migrations(): array
    {
        return [
            CreateContentTypes::class,
            CreateContentFieldDefinitions::class,
            CreateContentRecords::class,
            AddContentTypeSystemMedia::class,
            AddContentTypeThumbnail::class,
        ];
    }

    public function adminMetadata(): AdminModuleMetadata
    {
        return new AdminModuleMetadata(
            nameKey: $this->labelKey(),
            icon: AdminIcon::Boxes,
            navigationGroup: AdminNavigationGroup::Cms,
            navigationOrder: 50,
            routeSegment: 'content-types',
            navigationPermission: self::CODE . '.view',
            descriptionKey: 'content-types.module.description',
        );
    }

    /**
     * @return list<PermissionDefinition>
     */
    public function permissionDefinitions(): array
    {
        return [
            new PermissionDefinition(self::CODE . '.view', self::CODE, 'content-types.permissions.view'),
            new PermissionDefinition(self::CODE . '.manage_definitions', self::CODE, 'content-types.permissions.manage_definitions', requires: [self::CODE . '.view']),
        ];
    }

    /**
     * @return list<AuditEventDefinition>
     */
    public function auditEventDefinitions(): array
    {
        return [
            new AuditEventDefinition('created', 'content-types.audit.created', AdminIcon::PlusLg),
            new AuditEventDefinition('updated', 'content-types.audit.updated', AdminIcon::PencilSquare),
            new AuditEventDefinition('enabled', 'content-types.audit.enabled', AdminIcon::CheckCircle),
            new AuditEventDefinition('disabled', 'content-types.audit.disabled', AdminIcon::PauseCircle),
            new AuditEventDefinition('deleted', 'content-types.audit.deleted', AdminIcon::Trash3),
            new AuditEventDefinition('restored', 'content-types.audit.restored', AdminIcon::ArrowCounterclockwise),
        ];
    }
}
