<?php
// Get github credential

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

require __DIR__ . '/../vendor/autoload.php';

# Manage container and conf
$container = new DI\Container();

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$debug = (bool)getenv('DEBUG') ?: false;
# /Manage conf

# Create App
Slim\Factory\AppFactory::setContainer($container);
$app = Slim\Factory\AppFactory::create();
# /Create App

# A few methods
function getFile(Request $req, Response $resp)
{
    $filename = '/build/' . $req->getUri()->getPath();
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $contentType = $ext === 'json' ? 'application/json' : 'text/html';
    if (!file_exists($filename)) {
       throw new HttpNotFoundException($req);
    }
    $resp->getBody()->write(file_get_contents($filename));

    return $resp->withHeader('Content-Type', $contentType);
}

function executeSatis(Request $req, Response $resp, array $cmd)
{
    $cmd = array_merge(['/satis/bin/satis','--no-ansi', '--no-interaction'], $cmd);
    file_put_contents('php://stdout', 'Run command: ' . implode(' ', $cmd));

    $process = new Process($cmd);
    $process->run();

    if (!$process->isSuccessful()) {
        throw new HttpBadRequestException($req, cleanOutput($process->getErrorOutput()));
    }

    $resp->getBody()
        ->write(json_encode(['message' => cleanOutput($process->getOutput())]));

    return $resp->withHeader('Content-Type', 'application/json');
}

function cleanOutput(string $output)
{
    $output = trim($output);
    $output = preg_replace('/\s\s+/', '. ', $output); // Extra spaces
    $output = str_replace("\n", '. ', $output); // New lines.

    return $output;
}

# Inject functions and variables to container
$container->set('satisConfig', '/build/satis.json');

# Manage Errors
$app->addRoutingMiddleware();
$errHandler = function (Request $req, Throwable $except) use ($app, $debug) {
    $code = $except->getCode();
    $message = $except->getMessage();
    if (empty($code)) {
        $code = 500;
        $message = $debug ? $message : 'Server Error';
    }

    $payload = json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);

    $resp = $app->getResponseFactory()->createResponse();
    $resp->getBody()->write($payload);

    return $resp
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($code);
};
$errorMiddleware = $app->addErrorMiddleware($debug, $debug, $debug);
if ($debug === false) {
    $errorMiddleware->setDefaultErrorHandler($errHandler);
}
# /Manage Errors

# Routes
// Satis Commands
$app->post('/init', function (Request $req, Response $resp) {
    $body = $req->getParsedBody();
    if (file_exists($this->get('satisConfig'))) {
        if (empty($body['force'])) {
            $errMsg = 'Already initialized, you must set force to true to overwrite';
            throw new HttpBadRequestException($req, $errMsg);
        }

        unlink($this->get('satisConfig'));
    }

    if (empty($body['name']) || empty($body['homepage'])) {
        $errMsg = "You must send a 'name' and a 'homepage'";
        throw new HttpBadRequestException($req, $errMsg);
    }

    return executeSatis($req, $resp, [
        'init',
        '--name',
        $body['name'],
        '--homepage',
        $body['homepage'],
        $this->get('satisConfig')
    ]);
});

$pkgMatch = '{package:[a-z0-9\-]+/[a-z0-9\-]+}';

$app->post("/{$pkgMatch}", function (Request $req, Response $resp, array $args) {
    $body = $req->getParsedBody();
    if (empty($body['url'])) {
        $errMsg = "You must set a 'url' in the body";
        throw new HttpBadRequestException($req, $errMsg);
    }

    return executeSatis($req, $resp, [
        'add',
        '--name',
        $args['package'],
        $body['url'],
        $this->get('satisConfig')
    ]);
});

$app->get("/build", function (Request $req, Response $resp) {

    return executeSatis($req, $resp, [
        'build',
        $this->get('satisConfig'),
        '/build',
    ]);
});

$app->get("/build/{$pkgMatch}", function (Request $req, Response $resp, array $args) {

    return executeSatis($req, $resp, [
        'build',
        $this->get('satisConfig'),
        '/build',
        $args['package'],
    ]);
});

$app->delete("/{$pkgMatch}", function (Request $req, Response $resp, array $args) {
    if (!file_exists($this->get('satisConfig'))) {
        throw new HttpNotFoundException($req);
    }
    $deleted = false;
    $conf = json_decode(file_get_contents($this->get('satisConfig')), true);
    foreach ($conf['repositories'] as $key => $val) {
        if ($val['name'] === $args['package']) {
            unset($conf['repositories'][$key]);
            $deleted = true;
            break;
        }
    }

    if ($deleted === false) {
        throw new HttpBadRequestException(
            $req,
            'No package matching ' . $args['package']
        );
    }

    file_put_contents($this->get('satisConfig'), json_encode($conf, JSON_PRETTY_PRINT));
    $resp->getBody()->write(json_encode(['message' => 'Package deleted']));

    return $resp->withHeader('Content-Type', 'application/json');
});
// /Satis Commands

// static statis files
$app->get('/index.html', 'getFile');
$app->get('/packages.json', 'getFile');
$app->get('/include/{filename:[0-9a-zA-Z\$]+}.json', 'getFile');
// /static statis files

$app->run();
