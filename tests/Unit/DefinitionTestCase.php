<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Cms\ContentTypes\CmsContentTypesServiceProvider;
use Lemonade\Cms\ContentTypes\DefinitionValidation;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;
use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Support\Slug\Slugger;
use Lemonade\Framework\Validation\FormValidation;
use Lemonade\Framework\Validation\Rule\RuleRegistry;
use Lemonade\Framework\Validation\ValidationRuleResolver;
use PHPUnit\Framework\TestCase;

abstract class DefinitionTestCase extends TestCase
{
    protected function validation(): DefinitionValidation
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('get')->willReturnArgument(0);
        $container = new Container();
        $container->freeze();

        return new DefinitionValidation($this->types(), new FieldValidation(new FormValidation($translator, new ValidationRuleResolver(new RuleRegistry(), $container)), new Slugger(), $translator));
    }

    protected function types(): FieldTypeRegistry
    {
        $container = new Container();
        (new CmsContentTypesServiceProvider())->register($container);
        $container->freeze();
        return $container->get(FieldTypeRegistry::class);
    }
}
