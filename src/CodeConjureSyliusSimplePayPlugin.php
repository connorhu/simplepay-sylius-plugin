<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin;

use CodeConjure\SyliusSimplePayPlugin\DependencyInjection\CodeConjureSyliusSimplePayExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class CodeConjureSyliusSimplePayPlugin extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new CodeConjureSyliusSimplePayExtension();
    }
}
