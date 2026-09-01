<?php

declare(strict_types=1);

namespace CodeConjure\SyliusSimplePayPlugin\Command;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SyliusSimplePayPlugin\Debug\RecordingHttpClient;
use CodeConjure\SyliusSimplePayPlugin\Exception\SyliusSimplePayException;
use CodeConjure\SyliusSimplePayPlugin\Gateway\GatewayConfigReader;
use CodeConjure\SyliusSimplePayPlugin\Money\SyliusAmountConverter;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Core\Payum;
use Payum\Core\Request\Refund;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface as BasePaymentInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Jóváírás indítása parancssorból.
 *
 * Admin felület szándékosan nincs: ez a parancs elég ahhoz, hogy egy valódi
 * jóváírást elindítsunk és a `--record` kapcsolóval rögzítsük a nyers
 * választ — amivel a protokoll-csomag egyik nyitott kérdése lezárható.
 *
 * A TELJES ÖSSZEG KISZÁMÍTÁSA ITT TÖRTÉNIK, nem a `RefundAction`-ben. Az
 * action sosem alapértelmez „teljes összeg"-re; a döntés itt születik, ahol
 * látható, naplózható, és az operátor a képernyőn is látja.
 *
 * ISMERT KORLÁT, amit ez a parancs láthatóvá tesz, de nem javít: a 2.
 * rétegbeli `StatusMap` a `REFUND` állapotot mindig `markRefunded()`-ra
 * képezi le a `remainingTotal`-tól függetlenül, az átmenet-őr pedig minden
 * későbbi `Sync` állapotváltást elutasít a `REFUND` után — egy RÉSZLEGES
 * jóváírás így teljesnek látszik a Payum felől, és két részleges jóváírás
 * összege helyett csak az utolsó összeg marad a details-ben. Ez a plugin
 * ezt a korlátot nem javítja és nem kerüli meg — csak nem tesz rosszabbá.
 */
#[AsCommand(
    name: 'simplepay:refund',
    description: 'SimplePay jóváírás indítása egy rendelés befejezett fizetésére.',
)]
final class RefundCommand extends Command
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Payum $payum,
        private readonly EntityManagerInterface $entityManager,
        private readonly RecordingHttpClient $recordingHttpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('orderNumber', InputArgument::REQUIRED, 'A rendelés száma.')
            ->addOption(
                'amount',
                null,
                InputOption::VALUE_REQUIRED,
                'A jóváírandó összeg Sylius-egységben (1/100). Megadás nélkül a teljes fizetett összeg.',
            )
            ->addOption(
                'record',
                null,
                InputOption::VALUE_NONE,
                'A nyers HTTP kérés és válasz mentése fájlba, a válaszalak méréséhez.',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $orderNumber = $input->getArgument('orderNumber');

        if (!is_string($orderNumber)) {
            throw new \LogicException('Az "orderNumber" argumentum értéke string kell legyen.');
        }

        $order = $this->orderRepository->findOneBy(['number' => $orderNumber]);

        if (!$order instanceof OrderInterface) {
            $io->error(sprintf('Nincs "%s" számú rendelés.', $orderNumber));

            return Command::FAILURE;
        }

        $payment = $this->findRefundablePayment($order);

        if (!$payment instanceof PaymentInterface) {
            $io->error(sprintf(
                'A(z) "%s" rendeléshez nincs jóváírható SimplePay fizetés. '
                . 'A fizetésnek "%s" állapotúnak kell lennie.',
                $orderNumber,
                BasePaymentInterface::STATE_COMPLETED,
            ));

            return Command::FAILURE;
        }

        if ($input->getOption('record')) {
            $this->recordingHttpClient->enable();
            $io->note('A nyers HTTP forgalom rögzítése bekapcsolva.');
        }

        // Az --amount feldolgozása KÜLÖN, saját hibaágon fut: ez tisztán a
        // parancssori bemenet ellenőrzése, nem a SimplePay-protokoll hibája,
        // tehát nem tartozik a lenti, domain-kivételekre szabott catch alá.
        // Enélkül egy negatív vagy nulla --amount kiszökne a try/catch alól,
        // és Symfony nyers hibakiírását adná a kecses magyar üzenet helyett.
        try {
            $syliusAmount = $this->syliusAmount($input, $payment);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        try {
            $method = $payment->getMethod() ?? throw new \LogicException(
                'A jóváírható fizetéshez tartoznia kell fizetési módnak.',
            );

            $settings = GatewayConfigReader::read($method);

            // A Payum regiszterben a gateway a `gatewayName` alatt fut, ami a
            // fizetési mód kódjából generálódik (kisbetűsítve, aláhúzással) —
            // NEM egyezik meg mindig a `getCode()` nyers értékével. A `getCode()`
            // itt téves gateway-nevet adna minden olyan kódnál, amiben szóköz,
            // kötőjel vagy nagybetű van, és a `getGateway()` hívás egy valódi
            // jóváírás közben futna el hangos hibával.
            $gatewayName = $method->getGatewayConfig()?->getGatewayName() ?? throw new \LogicException(
                'A SimplePay gateway konfigurációjából hiányzik a Payum gateway neve.',
            );

            $minorUnits = SyliusAmountConverter::toMinorUnits($syliusAmount, $settings->currency);

            $details = $payment->getDetails();
            $details[Details::REFUND_KEY] = ['amount' => $minorUnits];
            $payment->setDetails($details);

            $io->text(sprintf(
                'Jóváírás indítása: %d %s (a(z) %d. fizetésre).',
                $minorUnits,
                $settings->currency->value,
                (int) $payment->getId(),
            ));

            $this->payum->getGateway($gatewayName)->execute(new Refund($payment));

            $this->entityManager->flush();
        } catch (
            SyliusSimplePayException
            | \CodeConjure\SimplePay\Exception\SimplePayException
            | \CodeConjure\SimplePayPayum\Exception\SimplePayPayumException $exception
        ) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $state = TransactionState::fromArray($this->stateArray($payment->getDetails()));

        $io->success('A jóváírás megtörtént.');
        $io->definitionList(
            ['Jóváírás tranzakcióazonosító' => $state->refundTransactionId ?? '—'],
            ['Jóváírt összeg' => null === $state->refundTotal ? '—' : (string) $state->refundTotal],
            ['Hátralévő összeg' => null === $state->remainingTotal ? '—' : (string) $state->remainingTotal],
        );

        foreach ($this->recordingHttpClient->recordedFiles() as $file) {
            $io->text(sprintf('Rögzítve: %s', $file));
        }

        return Command::SUCCESS;
    }

    private function findRefundablePayment(OrderInterface $order): ?PaymentInterface
    {
        foreach ($order->getPayments() as $payment) {
            if (!$payment instanceof PaymentInterface) {
                continue;
            }

            $method = $payment->getMethod();

            if (null === $method || !GatewayConfigReader::isSimplePay($method)) {
                continue;
            }

            if (BasePaymentInterface::STATE_COMPLETED !== $payment->getState()) {
                continue;
            }

            return $payment;
        }

        return null;
    }

    private function syliusAmount(InputInterface $input, PaymentInterface $payment): int
    {
        $option = $input->getOption('amount');

        if (null === $option) {
            // A teljes összeg kiszámítása ITT történik, nem a RefundAction-ben.
            return $payment->getAmount() ?? throw new \LogicException(
                'A befejezett fizetéshez tartoznia kell összegnek.',
            );
        }

        if (!is_string($option) || 1 !== preg_match('/^\d+$/', $option)) {
            throw new \InvalidArgumentException(sprintf(
                'Az --amount értéke pozitív egész szám kell legyen, Sylius-egységben (1/100). Kapott érték: "%s".',
                is_string($option) ? $option : gettype($option),
            ));
        }

        $amount = (int) $option;

        // Jóváírás összege sosem lehet nulla vagy negatív — egy ilyen kérés a
        // valódi SimplePay API-nak menne ki, jel nélküli ellenőrzés nélkül
        // (`Money::fromMinorUnits()` a 2. rétegben nem validál előjelet).
        if ($amount <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'Az --amount értéke pozitív egész szám kell legyen, Sylius-egységben (1/100). Kapott érték: "%d".',
                $amount,
            ));
        }

        return $amount;
    }

    /**
     * @param array<array-key, mixed> $details
     *
     * @return array<string, mixed>
     */
    private function stateArray(array $details): array
    {
        $state = $details[Details::STATE_KEY] ?? [];

        if (!is_array($state)) {
            return [];
        }

        $typed = [];

        foreach ($state as $key => $value) {
            if (is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }
}
