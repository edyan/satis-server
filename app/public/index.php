<?php
// Get github credential

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

require __DIR__ . '/../vendor/autoload.php';

# Manage container and conf
$container = new DI\Container();

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$debug = (bool)getenv('DEBUG') ?: false;
$container->set('artifactsDir', getenv('ARTIFACTS_DIR'));

$pkgMatch = '{package:[a-z0-9\-]+/[a-z0-9\-]+}';
# /Manage conf

# Create App
Slim\Factory\AppFactory::setContainer($container);
$app = Slim\Factory\AppFactory::create();
# /Create App

# A few methods
function getFile(Request $req, Response $resp)
{
    $filename = urldecode($req->getUri()->getPath());
    if (strpos($filename, '/build/') === false) {
        $filename = '/build/' . $filename;
    }

    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    switch ($ext) {
        case 'json':
        case 'zip':
            $contentType = 'application/' . $ext;
            break;
        default:
            $contentType = 'text/html';
    }
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
    $process->setWorkingDirectory('/build');
    $process->run();

    if (!$process->isSuccessful()) {
        throw new HttpBadRequestException($req, cleanOutput($process->getErrorOutput()));
    }

    $resp->getBody()->write(json_encode(['message' => cleanOutput($process->getOutput())]));

    return $resp->withHeader('Content-Type', 'application/json');
}

function cleanOutput(string $output)
{
    $output = trim($output);
    $output = preg_replace('/\s\s+/', '. ', $output); // Extra spaces
    $output = str_replace("\n", '. ', $output); // New lines.

    return $output;
}

function getPackageVersion(Request $req, string $filename): string
{
    $zip = new ZipArchive;
    if ($zip->open($filename) === false) {
        throw new HttpBadRequestException($req, 'zip does not seem valid');
    }

    $composerJson = $composerJson = $zip->getFromName('composer.json');
    if ($composerJson === false) {
        throw new HttpBadRequestException($req, 'No composer.json file in your zip');
    }

    $composerJson = json_decode($composerJson, true);
    $zip->close();
    if (empty($composerJson['version'])) {
        throw new HttpBadRequestException($req, 'composer.json does not contain a version');
    }

    return (string)$composerJson['version'];
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

    return $resp->withHeader('Content-Type', 'application/json')->withStatus($code);
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

    if (empty($body['name']) || empty($body['homepage'])) {
        $errMsg = "You must send a 'name' and a 'homepage'";
        throw new HttpBadRequestException($req, $errMsg);
    }

    $fs = new Filesystem();
    if ($fs->exists($this->get('satisConfig')) === true) {
        if (empty($body['force'])) {
            $errMsg = 'Already initialized, you must set force to true to overwrite';
            throw new HttpBadRequestException($req, $errMsg);
        }

        $fs->remove($this->get('satisConfig'));
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

$app->post("/{$pkgMatch}", function (Request $req, Response $resp, array $args) {
    $files = $req->getUploadedFiles();

    // user uploaded a zip file
    if (!empty($files['package'])) {
        if ($files['package']->getError() !== UPLOAD_ERR_OK) {
            throw new HttpBadRequestException($req, 'Error uploading file');
        }

        $fs = new Filesystem;

        $destDir = $this->get('artifactsDir') . '/' . $args['package'];

        // Create a temporary file to get the version
        $tmpfname = tempnam(sys_get_temp_dir(), 'satis');
        $fs->dumpFile($tmpfname, $files['package']->getStream());
        $filename = str_replace('/', '-', $args['package']) . '-' . getPackageVersion($req, $tmpfname) . '.zip';
        $fs->mkdir($destDir);
        // Move the file to the right dest
        $files['package']->moveTo($destDir . '/' . $filename);

        $resp->getBody()->write(json_encode(['message' => "File '$filename' written"]));

        return $resp->withHeader('Content-Type', 'application/json');
    }

    $body = $req->getParsedBody();
    // it's not an uploaded file
    if (empty($body['url'])) {
        $errMsg = "You must set a 'url' in the body or upload a file named 'package'";
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
    $fs = new Filesystem();
    if ($fs->exists($this->get('satisConfig')) === false) {
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

    $fs->dumpFile($this->get('satisConfig'), json_encode($conf, JSON_PRETTY_PRINT));
    $resp->getBody()->write(json_encode(['message' => 'Package deleted']));

    return $resp->withHeader('Content-Type', 'application/json');
});
// /Satis Commands

// static statis files
$app->get('/index.html', 'getFile');
$app->get('/packages.json', 'getFile');
$app->get('/include/{filename:[0-9a-zA-Z\$%]+}.json', 'getFile');
$app->get($container->get('artifactsDir') . '/{filename:[a-z0-9\-]+/[a-z0-9\-]+/[a-z0-9\-.]+}.zip', 'getFile');
// /static statis files

$app->run();
