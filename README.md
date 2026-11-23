# Lemonade CMS Content Types

[![Tests](https://img.shields.io/github/actions/workflow/status/johnnyxlemonade/admin-cms-content-types/phpunit.yml?branch=main&label=Tests&logo=githubactions&logoColor=white&labelColor=0f766e)](https://github.com/johnnyxlemonade/admin-cms-content-types/actions/workflows/phpunit.yml)
[![PHP 8.3–8.6](https://img.shields.io/badge/PHP-8.3%20%7C%208.4%20%7C%208.5%20%7C%208.6-777BB4?logo=php&logoColor=white)](#requirements)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/johnnyxlemonade/admin-cms-content-types/phpstan.yml?branch=main&label=PHPStan&logo=php&logoColor=white&labelColor=5b4baf)](https://github.com/johnnyxlemonade/admin-cms-content-types/actions/workflows/phpstan.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-green.svg)](LICENSE)

`johnnyxlemonade/admin-cms-content-types` is an optional CMS module with the
`cms.content-types` code. It provides a visual Content Type Builder at
`<admin-base>/cms/content-types` and generic CRUD for records of each defined type,
without generating separate Composer packages, PHP classes or database tables.

Definitions use the shared AdminEditor, with drag-and-drop field layout, an
an inspector and Select options. Supported fields are
text, textarea, rich text, integer, decimal, boolean, date, datetime and select.
Fields support defaults, validation rules, localized values and presentation
attributes. Definitions and typed values are stored relationally, without JSON
persistence; decimal values retain their precision without float conversion.

Active types appear in the Content navigation group with a standard DataGrid and
AdminEditor. Records reuse CMS title, display settings, SEO, publication, ownership,
audit, soft delete and restore. Content structure comes exclusively from the defined
custom fields; `textarea` and `richtext` fields remain available and rich-text images
and files use the shared Admin upload usages. Custom values and system data share one
CMS lifecycle transaction. Drafts may omit required values; publication and edits to
published content enforce them. Type and range validation also applies to drafts.

The Admin UI supports CS/EN; user-entered names, help and Select labels remain
unchanged when switching the UI language. Deactivating a type hides its navigation
item and prevents new records while preserving existing data and permissions.
Custom `image`, `gallery`, `file` and `files` fields use shared Admin file collections
in the Builder layout. Media are optional, non-localized and have no default value.
Each collection uses the record module code, root record ID and `field_<field ID>`;
file IDs are never duplicated into scalar value tables. Renaming or moving a field
preserves its collection. Archiving unregisters its usage without deleting files;
restoring the definition reconnects the same collection. Groups, repeaters and
relations are not supported.
Each content type can independently enable the shared CMS thumbnail, gallery,
attachments and localized tags. All four are off by default. Disabling a feature
hides its editor component and rejects its submitted or file values without deleting
existing data; enabling it again restores access to the same values. System media
usages remain independent of custom field collections. Optional localized route
prefixes affect only newly reserved canonical URLs; existing `cms_route` paths keep
their directory when their final URL segment is edited.
Existing CMS modules are not migrated. The host application owns public frontend
rendering and enforces publication visibility; this package generates no frontend.

## Requirements

- PHP 8.3 or newer, tested on PHP 8.3, 8.4, 8.5 and 8.6.
- `johnnyxlemonade/framework:dev-main`.
- `johnnyxlemonade/admin-platform:dev-main`.
- `johnnyxlemonade/cms-core:dev-main`.

## Installation

```bash
composer require johnnyxlemonade/admin-cms-content-types:dev-main
```

Composer package metadata discovers and activates the module. Run the host
application's controlled migration, permission-sync and Admin asset publication
flow before serving requests. The dependencies must include the shared field
builder assets and dynamic content lifecycle support.

Definition management uses `cms.content-types.view` and
`cms.content-types.manage_definitions`. Record access has separate per-type CMS
permissions, including owner-scoped operations. Assign permissions through Admin
Platform role management; permission synchronization does not grant them to roles.

## Package boundaries

- CMS Content Types owns type and field definitions, typed relational values,
  validation, and the generic record editor and DataGrid contributions.
- Admin Platform owns shared AdminEditor controls, DataGrid transport, navigation,
  role management, authorization, editor locks and audit infrastructure.
- CMS Core owns the shared content lifecycle, root/translation model, publication
  and canonical route reservations. Custom values join its existing transaction.
- The host owns public rendering, public request integration and deployment.

## Development and QA

From the package root, run:

```bash
composer cs:check
composer stan
composer test
composer qa
```

Use DDEV for local PHP and Composer commands. The default test suite is DB-free;
`composer qa` runs coding standards, PHPStan and PHPUnit. CI uses the same scripts,
with PHPUnit on PHP 8.3, 8.4, 8.5 and 8.6.
