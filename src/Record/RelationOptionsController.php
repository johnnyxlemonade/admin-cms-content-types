<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Cms\ContentTypes\Models\RecordModel;
use Lemonade\Framework\Core\Http\RequestData;
use Lemonade\Framework\Http\HttpStatus;
use Lemonade\Framework\Http\Response\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Lists authorized relation targets for an Admin select
 */
final readonly class RelationOptionsController
{
    private const int PAGE_SIZE = 25;
    // Keep offsets bounded for requests that skip far beyond the available records
    private const int MAX_PAGE = 10_000;
    // Record titles are limited to 180 characters
    private const int MAX_SEARCH_LENGTH = 180;

    /**
     * Receives the definitions, target query and authorization services
     */
    public function __construct(
        private DefinitionModel $definitions,
        private RecordModel $records,
        private LanguageRegistry $languages,
        private AuthorizationService $authorization,
        private Responses $responses,
    ) {}

    /**
     * Returns matching targets after validating the source and target permissions
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = new RequestData($request);
        $fieldId = $this->positiveInt($query->query('field'));
        $sourceValue = $query->query('source');
        $sourceId = $sourceValue === null ? null : $this->positiveInt($sourceValue);
        $locale = $query->query('locale');
        $invalidSource = $sourceValue !== null && $sourceId === null;
        $invalidLocale = !is_string($locale) || !$this->languages->isKnownLocale($locale);
        if ($fieldId === null || $invalidSource || $invalidLocale) {
            return $this->responses->json(['items' => []], HttpStatus::BAD_REQUEST->value);
        }
        $page = $this->positiveInt($query->query('page', 1));
        $selectedValue = $query->query('selected');
        $selectedId = $selectedValue === null ? null : $this->positiveInt($selectedValue);
        $search = $query->query('q', '');
        $invalidSelected = $selectedValue !== null && $selectedId === null;
        if ($page === null || $page > self::MAX_PAGE || $invalidSelected || !is_string($search)) {
            return $this->responses->json(['items' => []], HttpStatus::BAD_REQUEST->value);
        }
        $search = mb_substr(trim($search), 0, self::MAX_SEARCH_LENGTH);
        $pagination = ['page' => $page, 'perPage' => self::PAGE_SIZE, 'hasMore' => false];

        $field = $this->definitions->relationField($fieldId);
        if ($field === null) {
            return $this->responses->json(['items' => []], HttpStatus::NOT_FOUND->value);
        }

        $sourceTypeId = (int) $field['type_id'];
        $targetTypeId = (int) $field['relation_target_type_id'];
        if (!$this->canAccessSource($sourceTypeId, $sourceId)) {
            return $this->responses->json(['items' => []], HttpStatus::FORBIDDEN->value);
        }

        $targetModule = 'cms.type_' . $targetTypeId;
        $ownerId = null;
        if (!$this->authorization->hasPermission($targetModule . '.view')) {
            $ownerId = $this->authorization->currentUserId();
            if ($ownerId === null || !$this->authorization->hasPermission($targetModule . '.view_own')) {
                return $this->responses->json(['items' => []], HttpStatus::FORBIDDEN->value);
            }
        }

        $targetType = $this->definitions->type($targetTypeId);
        if ($targetType === null) {
            return $this->responses->json(['items' => [], 'pagination' => $pagination]);
        }
        if ($sourceId !== null && (int) $targetType['enabled'] !== 1) {
            return $this->responses->json(['items' => [], 'pagination' => $pagination]);
        }

        $excludeId = $sourceId !== null && $sourceTypeId === $targetTypeId ? $sourceId : null;
        $rows = $this->records->searchRelationTargets(
            typeId: $targetTypeId,
            locale: $locale,
            search: $search,
            limit: self::PAGE_SIZE + 1,
            offset: ($page - 1) * self::PAGE_SIZE,
            ownerId: $ownerId,
            excludeId: $excludeId,
            selectedId: $selectedId,
            includeDeleted: $sourceId === null,
        );
        $items = [];
        foreach (array_slice($rows, 0, self::PAGE_SIZE) as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $label = $title === '' ? '#' . $row['id'] : $title;
            if ($row['deleted_at'] !== null) {
                $label .= ' [deleted]';
            }
            $items[] = [
                'value' => (string) $row['id'],
                'label' => $label,
            ];
        }

        $pagination['hasMore'] = count($rows) > self::PAGE_SIZE;
        return $this->responses->json(['items' => $items, 'pagination' => $pagination]);
    }

    /**
     * Checks access to the source record or its create form
     */
    private function canAccessSource(int $sourceTypeId, ?int $sourceId): bool
    {
        $sourceModule = 'cms.type_' . $sourceTypeId;
        if ($sourceId === null) {
            if ($this->authorization->hasPermission($sourceModule . '.view')) {
                return true;
            }
            return $this->authorization->currentUserId() !== null
                && $this->authorization->hasPermission($sourceModule . '.view_own');
        }

        $source = $this->records->record($sourceTypeId, $sourceId);
        if ($source === null || $source['deleted_at'] !== null) {
            return false;
        }
        $ownerId = $source['owner_id'] === null ? null : (int) $source['owner_id'];
        return $this->authorization->canForOwner($sourceModule . '.edit', $sourceModule . '.edit_own', $ownerId);
    }

    /**
     * Parses a positive identifier from a query parameter
     */
    private function positiveInt(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($number) ? $number : null;
    }
}
