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
inspector and Select options. Supported fields are
text, textarea, rich text, integer, decimal, boolean, date, datetime, select,
media and relation.
Fields support defaults, validation rules, localized values and presentation
attributes. Definitions, typed values and record relations are stored relationally,
without JSON persistence; decimal values retain their precision without float conversion.

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
restoring the definition reconnects the same collection. Relation fields select one
or more records of a configured Content Type, including the same type. Their links
are shared across translations and retain selection order. Groups and repeaters are
not supported.
Required relations require an assigned link on publication. New links must point
to existing authorized targets of the configured type. Later deletion or
unpublishing of a target preserves the stored link; public reads omit targets that
are no longer visible. Applications that require a published target can enforce
that domain rule when saving their content.
Each content type can independently enable the shared CMS thumbnail, gallery,
attachments and localized tags. All four are off by default. Disabling a feature
hides its editor component and rejects its submitted or file values without deleting
existing data; enabling it again restores access to the same values. System media
usages remain independent of custom field collections. Optional localized route
prefixes affect only newly reserved canonical URLs; existing `cms_route` paths keep
their directory when their final URL segment is edited.
Existing CMS modules are not migrated. The package provides publication-scoped
reading through `PublishedRecordReadModel`. The host application owns public
frontend rendering and presentation-specific data mapping; this package generates
no frontend. New Content Type tables have no system `summary` or `content` columns.
Put these values in fields defined by the Builder. This schema change applies to
new installations; previously created database tables are not altered automatically.

## Public records

`PublishedRecordReadModel` accepts Content Type and field codes. Its locale argument
is the existing `PublicLocaleResolution` from CMS routing. It checks publication,
time, language, module and type availability, nonempty title and soft deletion
for every record it returns.
Unknown type or field codes raise `InvalidArgumentException` when the module is
available; an unavailable module returns no public content without querying
its tables. `record()` returns
`null` for an unavailable record or disabled type. `others()` returns the
newest visible records of the same type except the current ID, with optional
localized tag filtering. `isPublicInAnyLocale($typeCode, $id)` checks ownership
visibility for attachments, including published records without a public route.
`collection()` and `referencing()` always return a `PublishedRecordCollection`, including an empty
collection for disabled types or no matches. `related()` returns an empty list
when a valid relation has no visible targets. `relatedForRecords()` includes an
entry for every requested source ID, with an empty list when that source has no
visible targets.

```php
$news = $content->collection('workplace_news', $locale, relations: ['workplace' => $workplaceId], page: 1, perPage: 25);
$generalNews = $content->collection('news', $locale, relationPresence: ['workplace' => false]);
$allWorkplaceNews = $content->collection('news', $locale, relationPresence: ['workplace' => true]);
$filtered = $content->collection(
    'publication',
    $locale,
    filters: ['publisher' => 'Archive'],
    relations: ['workplace' => [$firstWorkplaceId, $secondWorkplaceId]],
    search: 'history',
    searchFields: ['perex', 'annotation', 'year'],
    searchRelations: ['workplace'],
    ranges: ['year' => ['from' => '2020', 'to' => '2026']],
    sortBy: 'year',
    sortDirection: 'desc',
    page: 1,
    perPage: 12,
);
$contacts = $content->referencing('contact', 'workplace', $workplaceId, $locale);
$others = $content->others('article', $articleId, $locale, limit: 3);
$articles = $content->related('article', $articleId, 'related_articles', $locale);
$articlesBySource = $content->relatedForRecords('article', $articleIds, 'related_articles', $locale);
foreach ($news->getItems() as $item) {
    $description = $item->getField('description')->getValue();
    $thumbnail = $item->getThumbnail();
    $gallery = $item->getGallery();
    $attachments = $item->getAttachments();
    $heroImages = $item->getFieldFiles('hero_image');
}
```

`relationPresence` selects records with (`true`) or without (`false`) a stored
target in a named relation field. It combines with other filters before the
database count and page limit; unknown or non-relation fields are rejected.

`PublishedRecordCollection::getPagination()` creates the framework's
`PaginationState` from the database count and requested page. Pass the public
route and the filters to preserve in navigation links, then render it with the
existing `PaginationComponent`:

```php
$page = $content->collection(
    typeCode: 'workplace_news',
    locale: $locale,
    filters: ['category' => 'internal'],
    relations: ['workplace' => $workplaceId],
    page: $pageNumber,
    perPage: 25,
);
$navigation = $paginationComponent->render($page->getPagination(
    basePath: $request->getUri()->getPath(),
    query: ['category' => 'internal', 'workplace' => $workplaceId],
));
```

`collection()` keeps its filtered `COUNT` and `LIMIT/OFFSET` queries; pagination
does not load all matching records. The same method works for collections returned
by `referencing()`.

The optional `search` term matches the title and explicitly listed text or numeric fields;
`searchRelations` also matches visible related record titles. `ranges` accepts
inclusive `from` and `to` bounds for numeric, date and datetime fields, and
`publishedAt` for the system publication time. Exact `filters`, `relations`,
localized tags, search and ranges combine with AND; searchable columns combine
with OR within the search term. A list of relation target IDs means any target
within that field. `sortBy` accepts `publishedAt`, `title`, an existing scalar
field code or `relationCode.title` for the first linked record;
`sortDirection` accepts `asc` or `desc`. Field codes and operators
are validated before SQL construction, and values are parameterized. Text
search uses `LIKE` and may scan large text fields; numeric and date value
tables retain their existing `(field_id, value, record_id)` indexes.

`record()` retrieves one published record. `sitemapEntries()` yields typed candidates
only for published records of enabled types with an active canonical route and
language. Each candidate provides `getPublicUrl()`, `getUpdatedAt()` and
`getTypeCode()`. Its update time is the latest change to the record, translation
or canonical route. A published relation target without its
own route is still readable, with `getPublicUrl() === null`. Relations are loaded
only when requested; reads never recursively expand related records. `PublishedRecord`
provides typed getters for title, timestamps, SEO, author, media, tags and display
settings. It implements `PublishedMediaInterface` from Admin Platform. Its
system media getters return `FileMetadata`; disabled capabilities return `null`
for the thumbnail or empty lists for gallery and attachments. Custom media
fields remain available through `getFieldFiles($code)`, without exposing file
usage codes to the host. Field values use `RecordFieldValue::getValue()` and
`getOptionLabel()`.

The host selects languages and Content Types in its existing framework sitemap
provider. The framework resolves the base URL and writes the XML:

```php
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\ContentTypes\Public\PublishedRecordReadModel;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Framework\Discovery\Sitemap\SitemapProviderInterface;
use Lemonade\Framework\Discovery\Sitemap\SitemapUrl;

/**
 * Supplies host-rendered Content Types to the framework sitemap
 */
final readonly class PublicContentSitemapProvider implements SitemapProviderInterface
{
    /**
     * Receives the published record reader and configured languages
     */
    public function __construct(
        private PublishedRecordReadModel $records,
        private LanguageRegistry $languages,
    ) {}

    /**
     * Yields sitemap URLs for Content Types rendered by this host
     *
     * @return iterable<SitemapUrl>
     */
    public function urls(): iterable
    {
        $defaultLocale = $this->languages->publicLocaleSnapshot()->getDefaultLocale();
        foreach ($this->languages->enabledLocales() as $language) {
            $locale = PublicLocaleResolution::route($defaultLocale, $language['code'], '');
            foreach ($this->records->sitemapEntries($locale) as $entry) {
                if ($entry->getTypeCode() !== 'workplace_news') {
                    continue;
                }

                yield SitemapUrl::create($entry->getPublicUrl(), $entry->getUpdatedAt());
            }
        }
    }
}
```

Register this provider under `config.sitemap.providers` in the host's
`Discovery.yaml`.
The sitemap query projects only the canonical path and last update time; it does
not hydrate fields, media, tags or relations.

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
