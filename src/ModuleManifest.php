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
use Lemonade\Framework\Database\Migration\MigrationInterface;

/**
 * Deklaruje prehled definic obsahovych typu a jeho schema
 */
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
     * Pripoji schema definic do globalniho migration lifecycle
     *
     * @return list<class-string<MigrationInterface>>
     */
    public function migrations(): array
    {
        return [
            Migrations\CreateContentTypes::class,
            Migrations\CreateContentFieldDefinitions::class,
            Migrations\CreateContentRecords::class,
            Migrations\AddContentTypeSystemMedia::class,
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
     * Deklaruje cteni a oddelenou spravu definic
     *
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
     * Deklaruje auditovane zmeny cele definice
     *
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
