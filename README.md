# Lemonade CMS Content Types

[![PHPStan](https://github.com/johnnyxlemonade/admin-cms-content-types/actions/workflows/phpstan.yml/badge.svg)](https://github.com/johnnyxlemonade/admin-cms-content-types/actions/workflows/phpstan.yml)
[![PHPUnit](https://github.com/johnnyxlemonade/admin-cms-content-types/actions/workflows/phpunit.yml/badge.svg)](https://github.com/johnnyxlemonade/admin-cms-content-types/actions/workflows/phpunit.yml)
[![Coding Standards](https://github.com/johnnyxlemonade/admin-cms-content-types/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/johnnyxlemonade/admin-cms-content-types/actions/workflows/coding-standards.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](composer.json)

`johnnyxlemonade/admin-cms-content-types` is an optional CMS module with the
`cms.content-types` code. It provides a visual Content Type Builder at
`<admin-base>/cms/content-types` and generic CRUD for records of each defined type,
without generating separate Composer packages, PHP classes or database tables.

Definitions use the shared AdminEditor, with drag-and-drop field layout, an
inspector, Select options and a responsive live preview. Supported fields are
text, textarea, rich text, integer, decimal, boolean, date, datetime and select.
Fields support defaults, validation rules, localized values and presentation
attributes. Definitions and typed values are stored relationally, without JSON
persistence; decimal values retain their precision without float conversion.

Active types appear in the Content navigation group with a standard DataGrid and
AdminEditor. Records reuse CMS title, SEO, publication, ownership, audit, soft
delete and restore. Custom values and system data share one CMS lifecycle
transaction. Drafts may omit required values; publication and edits to published
content enforce them. Type and range validation also applies to drafts.

The Admin UI supports CS/EN; user-entered names, help and Select labels remain
unchanged when switching the UI language. Deactivating a type hides its navigation
item and prevents new records while preserving existing data and permissions.
Groups, repeaters, relations and custom media fields are not supported yet.
Existing CMS modules are not migrated. The host application owns public frontend
rendering and enforces publication visibility; this package generates no frontend.

## Installation

The package requires PHP `>=8.3 <8.6`, `johnnyxlemonade/framework`,
`johnnyxlemonade/admin-platform`, and `johnnyxlemonade/cms-core`.

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
with PHPUnit on PHP 8.3, 8.4 and 8.5.
