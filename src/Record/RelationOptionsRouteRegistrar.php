<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use Lemonade\Admin\Http\Middleware\AdminAuthenticationMiddleware;
use Lemonade\Admin\Routing\AdminRouteRegistrarInterface;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;

/**
 * Registers the authenticated relation option endpoint
 */
final readonly class RelationOptionsRouteRegistrar implements AdminRouteRegistrarInterface
{
    public function id(): string
    {
        return 'cms.content-types.relation-options';
    }

    public function priority(): int
    {
        return 350;
    }

    public function registerRoutes(Router $router): void
    {
        $router->getNamed(
            name: 'admin.cms.content-types.relation-options',
            path: '/cms/content-types/relation-options',
            action: ControllerAction::for(RelationOptionsController::class, 'index'),
        )->middleware(AdminAuthenticationMiddleware::class);
    }
}
