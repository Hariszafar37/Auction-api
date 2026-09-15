<?php

use App\Services\Payment\StripeService;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * StripeService::attachPaymentMethod() wiring.
 *
 * Attaching a PaymentMethod can MINT A NEW ID: Stripe's shared test tokens
 * (pm_card_visa and friends) are templates, so attach() returns a brand-new
 * pm_... belonging to the customer while the token itself stays unattached and
 * is NOT a valid invoice_settings.default_payment_method.
 *
 * Everything downstream — the default for off-session invoice charges, and the
 * pm_... handle persisted on user_billing_information — therefore has to key
 * off the id Stripe RETURNED, never the id we passed in.
 *
 * These run against a stub HTTP client installed into the Stripe SDK, so they
 * assert real SDK behaviour (URLs, request bodies, response hydration) while
 * staying completely offline and deterministic.
 */

/**
 * A Stripe HTTP client that answers from a canned response map and records
 * every request so the test can assert on the bodies we sent.
 *
 * @see \Stripe\HttpClient\ClientInterface
 */
final class RecordingStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, path: string, params: array}> */
    public array $calls = [];

    /** @param array<string, array> $responses Map of "METHOD /v1/path" => decoded JSON body */
    public function __construct(private array $responses) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);
        $key  = strtoupper($method) . ' ' . $path;

        $this->calls[] = ['method' => strtoupper($method), 'path' => $path, 'params' => $params];

        if (! array_key_exists($key, $this->responses)) {
            throw new RuntimeException("Unexpected Stripe request: {$key}");
        }

        return [json_encode($this->responses[$key]), 200, []];
    }

    /** The params of the first recorded call to the given "METHOD /v1/path". */
    public function paramsFor(string $key): ?array
    {
        foreach ($this->calls as $call) {
            if ($call['method'] . ' ' . $call['path'] === $key) {
                return $call['params'];
            }
        }

        return null;
    }
}

/**
 * Install a stub Stripe transport and return it for assertions.
 */
function fakeStripeTransport(array $responses): RecordingStripeHttpClient
{
    $client = new RecordingStripeHttpClient($responses);
    ApiRequestor::setHttpClient($client);

    return $client;
}

beforeEach(function () {
    config(['services.stripe.secret' => 'sk_test_dummy']);
});

afterEach(function () {
    // ApiRequestor holds the client in static state — always hand it back so a
    // stub cannot leak into another test file.
    ApiRequestor::setHttpClient(null);
});

it('sets the customer default to the id attach() returned, not the id passed in', function () {
    // A shared test token: unattached, and attach() mints a different id.
    $transport = fakeStripeTransport([
        'GET /v1/payment_methods/pm_card_visa' => [
            'id'       => 'pm_card_visa',
            'object'   => 'payment_method',
            'customer' => null,
            'card'     => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
        ],
        'POST /v1/payment_methods/pm_card_visa/attach' => [
            'id'       => 'pm_1AttachedConcrete',
            'object'   => 'payment_method',
            'customer' => 'cus_test_123',
            'card'     => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
        ],
        'POST /v1/customers/cus_test_123' => [
            'id'     => 'cus_test_123',
            'object' => 'customer',
        ],
    ]);

    $pm = app(StripeService::class)->attachPaymentMethod('cus_test_123', 'pm_card_visa');

    $update = $transport->paramsFor('POST /v1/customers/cus_test_123');

    expect($update['invoice_settings']['default_payment_method'])->toBe('pm_1AttachedConcrete');

    // And the caller gets the concrete PM back, so the pm_... handle and the
    // display metadata it persists refer to the same object Stripe will charge.
    expect($pm->id)->toBe('pm_1AttachedConcrete')
        ->and($pm->card->last4)->toBe('4242');
});

it('attaches an unattached payment method before setting it as the default', function () {
    $transport = fakeStripeTransport([
        'GET /v1/payment_methods/pm_1Fresh' => [
            'id'       => 'pm_1Fresh',
            'object'   => 'payment_method',
            'customer' => null,
            'card'     => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
        ],
        'POST /v1/payment_methods/pm_1Fresh/attach' => [
            'id'       => 'pm_1Fresh',
            'object'   => 'payment_method',
            'customer' => 'cus_test_123',
            'card'     => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
        ],
        'POST /v1/customers/cus_test_123' => ['id' => 'cus_test_123', 'object' => 'customer'],
    ]);

    $pm = app(StripeService::class)->attachPaymentMethod('cus_test_123', 'pm_1Fresh');

    expect($transport->paramsFor('POST /v1/payment_methods/pm_1Fresh/attach'))
        ->toBe(['customer' => 'cus_test_123']);

    expect($transport->paramsFor('POST /v1/customers/cus_test_123')['invoice_settings']['default_payment_method'])
        ->toBe('pm_1Fresh')
        ->and($pm->id)->toBe('pm_1Fresh');
});

it('does not re-attach a payment method already on the customer', function () {
    // No attach entry in the map — a second attach would blow up as "Unexpected".
    $transport = fakeStripeTransport([
        'GET /v1/payment_methods/pm_1Existing' => [
            'id'       => 'pm_1Existing',
            'object'   => 'payment_method',
            'customer' => 'cus_test_123',
            'card'     => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
        ],
        'POST /v1/customers/cus_test_123' => ['id' => 'cus_test_123', 'object' => 'customer'],
    ]);

    $pm = app(StripeService::class)->attachPaymentMethod('cus_test_123', 'pm_1Existing');

    expect($transport->paramsFor('POST /v1/payment_methods/pm_1Existing/attach'))->toBeNull();

    expect($transport->paramsFor('POST /v1/customers/cus_test_123')['invoice_settings']['default_payment_method'])
        ->toBe('pm_1Existing')
        ->and($pm->id)->toBe('pm_1Existing');
});
