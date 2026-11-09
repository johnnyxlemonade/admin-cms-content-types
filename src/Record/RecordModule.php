<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use Lemonade\Admin\Audit\AuditEventDefinition;
use Lemonade\Admin\Authorization\PermissionDefinition;
use Lemonade\Admin\Authorization\PermissionSection;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Module\AdminModuleMetadata;
use Lemonade\Admin\Module\AdminNavigationGroup;
use Lemonade\Admin\Module\Contract\AdminModuleDefinitionInterface;
use Lemonade\Admin\Module\Contract\AdminModuleFileCapabilitiesInterface;
use Lemonade\Admin\Presentation\AdminFileCapabilities;
use Lemonade\Cms\Content\CmsContentDefinition;

/**
 * Pripojuje ulozeny typ k existujicim Admin registrum bez dalsiho Composer modulu
 */
final readonly class RecordModule implements AdminModuleDefinitionInterface, AdminModuleFileCapabilitiesInterface
{
    /**
     * Nastavuje stabilni identitu a uzivatelsky nazev typu
     */
    public function __construct(
        private int $id,
        private string $name,
        private string $typeCode,
        private bool $enabled,
        private ?string $description = null,
        private bool $galleryEnabled = false,
        private bool $attachmentsEnabled = false,
    ) {}

    /**
     * Povoli pouze systemove media usages aktivni v definici typu
     */
    public function fileCapabilities(): AdminFileCapabilities
    {
        return new AdminFileCapabilities(
            gallery: $this->galleryEnabled,
            attachments: $this->attachmentsEnabled,
        );
    }

    /**
     * Urcuje dostupnost systemove galerie v editoru zaznamu
     */
    public function galleryEnabled(): bool
    {
        return $this->galleryEnabled;
    }

    /**
     * Urcuje dostupnost systemovych priloh v editoru zaznamu
     */
    public function attachmentsEnabled(): bool
    {
        return $this->attachmentsEnabled;
    }

    public function code(): string
    {
        return 'cms.type_' . $this->id;
    }

    /**
     * Vrati nekolidujici segment sdilenych administracnich rout
     */
    public function segment(): string
    {
        return 'type-' . str_replace('_', '-', $this->typeCode);
    }

    public function adminMetadata(): AdminModuleMetadata
    {
        return new AdminModuleMetadata(
            'records.module.name',
            AdminIcon::Boxes,
            AdminNavigationGroup::Content,
            60,
            $this->segment(),
            $this->code() . '.view_own',
            name: $this->name,
            navigationVisible: $this->enabled,
            permissionSection: PermissionSection::ContentTypes,
            description: $this->description,
        );
    }

    /**
     * Ohranici vsechny CMS dotazy typem ve sdilenych tabulkach
     */
    public function contentDefinition(): CmsContentDefinition
    {
        return new CmsContentDefinition(
            moduleCode: $this->code(),
            entityType: 'cms_content_record',
            rootTable: 'cms_content_record',
            translationTable: 'cms_content_record_translation',
            translationForeignKey: 'record_id',
            tagTable: 'cms_content_record_translation_tag',
            translationUniqueConstraint: 'uq_cms_content_record_translation_locale',
            validationPrefix: 'records',
            dataClass: RecordData::class,
            rootScope: ['type_id' => $this->id],
        );
    }

    public function permissionDefinitions(): array
    {
        return [
            new PermissionDefinition($this->code() . '.view_own', $this->code(), 'records.permissions.view_own'),
            new PermissionDefinition($this->code() . '.view', $this->code(), 'records.permissions.view', requires: [$this->code() . '.view_own']),
            new PermissionDefinition(
                $this->code() . '.create',
                $this->code(),
                'records.permissions.create',
                requires: [$this->code() . '.view_own'],
            ),
            new PermissionDefinition(
                $this->code() . '.edit_own',
                $this->code(),
                'records.permissions.edit_own',
                requires: [$this->code() . '.view_own'],
            ),
            new PermissionDefinition(
                $this->code() . '.edit',
                $this->code(),
                'records.permissions.edit',
                requires: [$this->code() . '.edit_own', $this->code() . '.view'],
            ),
            new PermissionDefinition(
                $this->code() . '.publish_own',
                $this->code(),
                'records.permissions.publish_own',
                requires: [$this->code() . '.view_own'],
            ),
            new PermissionDefinition(
                $this->code() . '.publish',
                $this->code(),
                'records.permissions.publish',
                requires: [$this->code() . '.publish_own', $this->code() . '.view'],
            ),
            new PermissionDefinition(
                $this->code() . '.delete_own',
                $this->code(),
                'records.permissions.delete_own',
                requires: [$this->code() . '.view_own'],
            ),
            new PermissionDefinition(
                $this->code() . '.delete',
                $this->code(),
                'records.permissions.delete',
                requires: [$this->code() . '.delete_own', $this->code() . '.view'],
            ),
            new PermissionDefinition(
                $this->code() . '.restore_own',
                $this->code(),
                'records.permissions.restore_own',
                requires: [$this->code() . '.view_own'],
            ),
            new PermissionDefinition(
                $this->code() . '.restore',
                $this->code(),
                'records.permissions.restore',
                requires: [$this->code() . '.restore_own', $this->code() . '.view'],
            ),
            new PermissionDefinition(
                $this->code() . '.assign_owner',
                $this->code(),
                'records.permissions.assign_owner',
                requires: [$this->code() . '.view_own'],
            ),
        ];
    }

    public function auditEventDefinitions(): array
    {
        return [
            new AuditEventDefinition('created', 'records.audit.events.created', AdminIcon::PlusLg),
            new AuditEventDefinition('updated', 'records.audit.events.updated', AdminIcon::PencilSquare),
            new AuditEventDefinition('translation_created', 'records.audit.events.translation_created', AdminIcon::PlusLg),
            new AuditEventDefinition('translation_updated', 'records.audit.events.translation_updated', AdminIcon::PencilSquare),
            new AuditEventDefinition('published', 'records.audit.events.published', AdminIcon::CheckCircle),
            new AuditEventDefinition('unpublished', 'records.audit.events.unpublished', AdminIcon::PauseCircle),
            new AuditEventDefinition('deleted', 'records.audit.events.deleted', AdminIcon::Trash3),
            new AuditEventDefinition('restored', 'records.audit.events.restored', AdminIcon::ArrowCounterclockwise),
            new AuditEventDefinition('owner_transferred', 'records.audit.events.owner_transferred', AdminIcon::PersonGear),
        ];
    }

    /**
     * Vraci stabilni identitu obsahoveho typu
     */
    public function id(): int
    {
        return $this->id;
    }

    /**
     * Vraci uzivatelsky nazev obsahoveho typu
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Vraci stabilni technicky kod obsahoveho typu
     */
    public function typeCode(): string
    {
        return $this->typeCode;
    }

    /**
     * Urcuje zda je obsahovy typ aktivni
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Vraci uzivatelsky popis obsahoveho typu
     */
    public function description(): ?string
    {
        return $this->description;
    }
}
