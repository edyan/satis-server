<?php
// Move variable $filename to container
// Move default satis cmd to container
// Move debug to container
// Debug commands
// Get github credential
// ssh keyscan yes by default
// create a method delete

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

# Inject functions and variables to container
$container->set('getFile', function (Request $req, Response $res): Response {
    $filename = '/build/' . $req->getUri()->getPath();
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $contentType = $ext === 'json' ? 'application/json' : 'text/html';
    if (!file_exists($filename)) {
       throw new HttpNotFoundException($req);
    }
    $res->getBody()->write(file_get_contents($filename));

    return $res->withHeader('Content-Type', $contentType);
});

# Manage Errors
$app->addRoutingMiddleware();
$customErrHandler = function (Request $req, Throwable $except) use ($app, $debug) {
    $code = $except->getCode();
    $message = $except->getMessage();
    if (empty($code)) {
        $code = 500;
        $message = $debug ? $message : 'Server Error';
    }

    $payload = json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);

    $res = $app->getResponseFactory()->createResponse();
    $res->getBody()->write($payload);

    return $res
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($code);
};
$errorMiddleware = $app->addErrorMiddleware($debug, $debug, $debug);
$errorMiddleware->setDefaultErrorHandler($customErrHandler);
# /Manage Errors

# Routes
// Satis Commands
$app->post('/build', function (Request $req, Response $res) {
    $body = $req->getParsedBody();
    if (empty($body['package']) || !is_array($body['package'])) {
        $errMsg = "You must set at least one value to 'package[]'";
        throw new HttpBadRequestException($req, $errMsg);
    }

    $filename = '/build/satis.json';
    $process = new Process([
        '/satis/bin/satis',
        'build',
        '--no-ansi',
        '--no-interaction',
        '--no-html-output',
        $filename,
        '/build',
        implode(' ', $body['package']),
    ]);
    $process->run();

    $output = trim($process->getOutput());
    if (!$process->isSuccessful()) {
        throw new HttpBadRequestException($req, $output);
    }
    $res->getBody()->write(json_encode(['message' => $output]));

    return $res->withHeader('Content-Type', 'application/json');
});

$app->post('/init', function (Request $req, Response $res) {
    $filename = '/build/satis.json';
    $body = $req->getParsedBody();
    if (file_exists($filename)) {
        if (empty($body['force'])) {
            $errMsg = 'Already initialized, you must set force to true to owerwrite';
            throw new HttpBadRequestException($req, $errMsg);
        }

        unlink($filename);
    }

    if (empty($body['name']) || empty($body['homepage'])) {
        $errMsg = "You must send a 'name' and a 'homepage'";
        throw new HttpBadRequestException($req, $errMsg);
    }

    $process = new Process([
        '/satis/bin/satis',
        'init',
        '--no-ansi',
        '--no-interaction',
        '--name',
        $body['name'],
        '--homepage',
        $body['homepage'],
        $filename
    ]);
    $process->run();

    $output = trim($process->getOutput());
    if (!$process->isSuccessful()) {
        throw new HttpBadRequestException($req, $output);
    }
    $res->getBody()->write(json_encode(['message' => $output]));

    return $res->withHeader('Content-Type', 'application/json');
});

$app->post('/{package:[a-z/]+}', function (Request $req, Response $res, array $args) {
    $filename = '/build/satis.json';
    $body = $req->getParsedBody();
    if (empty($body['url'])) {
        $errMsg = "You must set a 'url' in the body";
        throw new HttpBadRequestException($req, $errMsg);
    }

    // Run Satis
    $process = new Process([
        '/satis/bin/satis',
        'add',
        '--no-ansi',
        '--no-interaction',
        '--name',
        $args['package'],
        $body['url'],
        $filename
    ]);
    $process->run();

    // Analysis the url to scan it

    $output = trim($process->getOutput());
    if (!$process->isSuccessful()) {
        throw new HttpBadRequestException($req, $output);
    }
    $res->getBody()->write(json_encode(['message' => $output]));

    return $res->withHeader('Content-Type', 'application/json');
});

$app->delete('/{package:[a-z/]+}', function (Request $req, Response $res, array $args) {
    $filename = '/build/satis.json';
    if (!file_exists($filename)) {
        throw new HttpNotFoundException($req);
    }
    $deleted = false;
    $conf = json_decode(file_get_contents($filename), true);
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

    file_put_contents($filename, json_encode($conf, JSON_PRETTY_PRINT));
    $res->getBody()->write(json_encode(['message' => 'Package deleted']));

    return $res->withHeader('Content-Type', 'application/json');
});

// /Satis Commands

// static statis files
$app->get('/index.html', $container->get('getFile'));
$app->get('/packages.json', $getFile);
$app->get('/include/{filename:[0-9a-zA-Z\$]+}.json', $getFile);
// /static statis files

$app->run();
