<?php

declare(strict_types=1);

namespace Payum\Core\Request;

/**
 * PHPStan-stub: a vendor `Convert::getToken()` docblockja `@return
 * TokenInterface`-t ígér (nem nullable), de ez téves — a saját
 * konstruktora ellentmond neki:
 *
 *     public function __construct($source, $to, ?TokenInterface $token = null)
 *
 * A `$token` explicit nullable, alapértéke `null`. Mért kísérlet igazolta,
 * hogy ez nem csak elméleti eset: `new Convert($payment, 'array', null)`
 * — amit a vendor konstruktora kifejezetten megenged, és amit például egy
 * puszta `new Capture($payment)` nyomán induló Convert is előállíthat —
 * végigfuttatva a mi `ConvertPaymentAction::execute()`-unkon `null`
 * tokent ad vissza a `getToken()`-ből.
 *
 * A hibás docblock miatt a PHPStan (amely az annotációt megbízhatóbbnak
 * tekinti a tényleges kódnál) "mindig hamis" null-ellenőrzésnek jelezte
 * a mi védő guard-unkat, ami — ha eltávolítjuk — egy kifogott, névvel
 * ellátott kivétel helyett nyers `Error`-t enged kiszökni. Ez a stub
 * KIZÁRÓLAG ezt az egy metódust javítja a valós, nullable típusra; a
 * `codeconjure/simplepay-payum` és a `codeconjure/simplepay` csomagokat
 * nem érinti.
 *
 * A metódustörzs üresen maradhat — a PHPStan a stub fájlokból csak a
 * docblockot olvassa, nem futtatja a kódot.
 */
class Convert
{
    /**
     * @return \Payum\Core\Security\TokenInterface|null
     */
    public function getToken()
    {
    }
}

namespace Payum\Core\Security;

/**
 * PHPStan-stub: a stub fájlok elemzése a projekt többi részétől
 * FÜGGETLENÜL történik — minden típus, amire egy stub hivatkozik, saját
 * maga is stubként kell szerepeljen, különben a PHPStan "class.notFound"
 * hibát jelez, még akkor is, ha az osztály ténylegesen betölthető a
 * Composer autoloaderrel. Ez az üres váz KIZÁRÓLAG azért kell, hogy a
 * `Convert::getToken()` fenti visszatérési típusa feloldható legyen — nem
 * írja felül a `TokenInterface` egyetlen metódusát sem (a stubok a valós
 * reflection-t KIEGÉSZÍTIK, nem cserélik le), így a `getHash()` és a
 * többi metódus a vendor valós deklarációja szerint működik tovább.
 */
interface TokenInterface
{
}
