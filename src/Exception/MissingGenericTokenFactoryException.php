<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Exception;

/**
 * A `Payum\Core\Security\GenericTokenFactoryInterface` nem érkezett meg
 * a Convert-akcióhoz.
 *
 * Ez programozási hiba, nem futásidejű adathiány: a Payum minden gateway-t
 * a `GenericTokenFactoryExtension`-nel épít fel
 * (`Payum\Core\PayumBuilder::buildGateway()`), ami minden
 * `GenericTokenFactoryAwareInterface`-t megvalósító akciónak automatikusan
 * beinjektálja a gyárat — külön szolgáltatás-bekötés nélkül. Ha ez mégis
 * hiányzik, az akciót nem a Payum gateway futtatta, hanem valami más
 * (például egy hiányos teszt-összeállítás).
 */
final class MissingGenericTokenFactoryException extends \LogicException implements SyliusSimplePayException
{
}
