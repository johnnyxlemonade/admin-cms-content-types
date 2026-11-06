<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Actions;

use Lemonade\Admin\Action\Contract\ModuleActionHandlerInterface;
use Lemonade\Admin\Action\Exception\ModuleActionException;
use Lemonade\Admin\Action\ModuleActionResult;
use Lemonade\Admin\Editor\Exception\EditorCapabilityException;
use Lemonade\Admin\Editor\Exception\EditorEntityNotFoundException;
use Lemonade\Admin\Http\AdminErrorCode;
use Lemonade\Admin\Identity\LocalActorRequiredException;
use Lemonade\Cms\ContentTypes\DefinitionService;
use Lemonade\Framework\Http\HttpStatus;

/**
 * Predava zmenu dostupnosti typu do auditovane service
 */
final readonly class SetEnabledAction implements ModuleActionHandlerInterface
{
    /**
     * Nastavuje service a cilovy stav registrovane akce
     */
    public function __construct(private DefinitionService $definitions, private bool $enabled) {}

    public function execute(?int $id, array $payload): ModuleActionResult
    {
        if ($id === null) {
            throw new ModuleActionException(HttpStatus::NOT_FOUND, AdminErrorCode::NOT_FOUND, 'Content type not found.');
        }
        try {
            $this->definitions->setEnabled($id, $this->enabled);
        } catch (EditorCapabilityException $exception) {
            throw new ModuleActionException($exception->statusCode(), $exception->error(), $exception->getMessage());
        } catch (EditorEntityNotFoundException $exception) {
            throw new ModuleActionException(HttpStatus::NOT_FOUND, AdminErrorCode::NOT_FOUND, $exception->getMessage());
        } catch (LocalActorRequiredException $exception) {
            throw new ModuleActionException(HttpStatus::FORBIDDEN, AdminErrorCode::LOCAL_ACTOR_REQUIRED, $exception->getMessage());
        }

        return ModuleActionResult::success(
            $this->enabled ? 'content-types.actions.enabled' : 'content-types.actions.disabled',
            refreshPage: true,
        );
    }
}
