<?php
namespace Payum\Server;

require_once __DIR__.'/../vendor/autoload.php';

use Buzz\Client\Curl;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Registry\RegistryInterface;
use Payum\Core\Request\BinaryMaskStatusRequest;
use Payum\Core\Request\RedirectUrlInteractiveRequest;
use Payum\Core\Request\SecuredCaptureRequest;
use Payum\Core\Security\GenericTokenFactoryInterface;
use Payum\Core\Security\SensitiveValue;
use Payum\Core\Security\TokenInterface;
use Payum\Paypal\ExpressCheckout\Nvp\PaymentFactory;
use Payum\Paypal\ExpressCheckout\Nvp\Api;
use Payum\Core\Registry\SimpleRegistry;
use Payum\Core\Storage\FilesystemStorage;
use Payum\Server\Action\PaypalExpressCheckoutCaptureAction;
use Payum\Server\Security\HttpRequestVerifier;
use Payum\Server\Security\TokenFactory;
use Silex\Application;
use Silex\Provider\UrlGeneratorServiceProvider;
use Symfony\Component\HttpFoundation\Request;

$app = new Application;
$app['debug'] = true;
$app['payum.storage_dir'] = __DIR__.'/../storage';
$app['payum.model.payment_details_class'] = 'Payum\Server\Model\PaymentDetails';
$app['payum.model.security_token_class'] = 'Payum\Server\Model\SecurityToken';

$app->register(new UrlGeneratorServiceProvider());

$app['payum.security.token_storage'] = $app->share(function($app) {
    return new FilesystemStorage(
        $app['payum.storage_dir'],
        $app['payum.model.security_token_class'],
        'hash'
    );
});

$app['payum.security.http_request_verifier'] = $app->share(function($app) {
    return new HttpRequestVerifier($app['payum.security.token_storage']);
});

$app['payum.security.token_factory'] = $app->share(function($app) {
    return new TokenFactory(
        $app['url_generator'],
        $app['payum.security.token_storage'],
        $app['payum'],
        'capture',
        'notify'
    );
});

$app['payum'] = $app->share(function($app) {
    $detailsClass = $app['payum.model.payment_details_class'];

    $storages = array(
        'paypal' => array(
            $detailsClass => new FilesystemStorage($app['payum.storage_dir'], $detailsClass)
        )
    );

    $payments = array(
        'paypal' => PaymentFactory::create(new Api(new Curl, array(
                'username' => 'EDIT ME',
                'password' => 'EDIT ME',
                'signature' => 'EDIT ME',
                'sandbox' => true
            )
        ))
    );

    $payments['paypal']->addAction(new PaypalExpressCheckoutCaptureAction, true);

    return new SimpleRegistry($payments, $storages, null, null);
});

$app->get('/', function (Application $app) {
    if ($app['debug']) {
        return nl2br(file_get_contents('https://gist.githubusercontent.com/makasim/9543453/raw/2599127ba8c51106a9c15b7aded21f90f842d312/usecase.md'));
    }
});

$app->get('/capture/{payum_token}', function (Application $app, Request $request) {
    /** @var TokenInterface $token */
    $token = $app['payum.security.http_request_verifier']->verify($request);

    /** @var RegistryInterface $payum */
    $payum = $app['payum'];

    try {
        $payment = $payum->getPayment($token->getPaymentName());
        $payment->execute(new SecuredCaptureRequest($token));

    } catch (RedirectUrlInteractiveRequest $e) {
        return $app->redirect($e->getUrl());
    }

    $app['payum.security.http_request_verifier']->invalidate($token);

    return $app->redirect($token->getAfterUrl());
})->bind('capture');

$app->post('/api/payment', function (Application $app, Request $request) {
    if ('json' !== $request->getContentType()) {
        $app->abort(400, 'The request content type is invalid.');
    }

    $rawDetails = json_decode($request->getContent(), true);
    if (null ===  $rawDetails) {
        $app->abort(400, 'The request content is not valid json.');
    }
    if (empty($rawDetails['meta']['name'])) {
        $app->abort(400, 'The payment name must be set to meta.name.');
    }
    $name = $rawDetails['meta']['name'];

    if (empty($rawDetails['meta']['purchase_after_url'])) {
        $app->abort(400, 'The purchase after url has to be set to  meta.purchase_after_url.');
    }
    $afterUrl = $rawDetails['meta']['purchase_after_url'];

    if (empty($rawDetails['payment'])) {
        $app->abort(400, 'The payment details has to be set to payment.');
    }
    if (false == is_array($rawDetails['payment'])) {
        $app->abort(400, 'The payment details has to be an array');
    }

    /** @var RegistryInterface $payum */
    $payum = $app['payum'];
    /** @var GenericTokenFactoryInterface $tokenFactory */
    $tokenFactory = $app['payum.security.token_factory'];

    $storage = $payum->getStorageForClass($app['payum.model.payment_details_class'], $name);
    $details = $storage->createModel();

    ArrayObject::ensureArrayObject($details)->replace($rawDetails);

    $storage->updateModel($details);

    $captureToken = $tokenFactory->createCaptureToken($name, $details, $afterUrl);
    $getToken = $tokenFactory->createToken($name, $details, 'payment_get');

    $meta = $details['meta'];
    $meta['links'] = array(
        'purchase' => new SensitiveValue($captureToken->getTargetUrl()),
        'get' => $getToken->getTargetUrl(),
    );
    $details['meta'] = $meta;

    $storage->updateModel($details);

    return json_encode(iterator_to_array($details), JSON_PRETTY_PRINT);
})->bind('payment_create');

$app->get('/api/payment/{payum_token}', function (Application $app, Request $request) {
    /** @var TokenInterface $token */
    $token = $app['payum.security.http_request_verifier']->verify($request);

    /** @var RegistryInterface $payum */
    $payum = $app['payum'];

    $status = new BinaryMaskStatusRequest($token);
    $payum->getPayment($token->getPaymentName())->execute($status);

    return json_encode(array(
        'payment' => iterator_to_array($status->getModel()),
        'status' => $status->getStatus(),
    ));
})->bind('payment_get');

$app->get('/done', function () {
    return 'Done';
})->bind('done');

$app->run();