<?php

require_once __DIR__.'/../vendor/autoload.php';
use Symfony\Component\HttpFoundation\Request;

$app = new Silex\Application();

require_once __DIR__.'/../config.php';

$app->before(function (Request $request) {
    if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
        $data = json_decode($request->getContent(), true);
        $request->request->replace(is_array($data) ? $data : array());
    }
});

$app->get('/create-build', function (Request $request) use ($app) {
    $branch = $request->get('branch');
    $project = $request->get('project');
    $postbackUrl = $request->get('postback_url');

    if ($branch && $project && $postbackUrl) {

      $branchParts = explode('/', $branch);
      $branchName = $branchParts[count($branchParts) - 1];
      $jobName = $project . '-' . $branchName;
      $jenkins_path = $app['jenkins_path'];
      $jobUrl = $jenkins_path . '/job/'. $jobName . '/lastBuild/api/json';

      $client = new Guzzle\Http\Client();
      //$request = $client->createRequest('GET', $jobUrl);
      //$response = $client->send($request);

      //$statusCode = $response->getStatusCode();
      //$body = $response->getBody();
      //var_export($res->json());
      return 'Building branch: '. $jobName . ' - '. $jobUrl;
    }

    return 'Submit build by passing branch, project and postback_url';
});


$app->get('/', function () {
    return 'Test homepage';
});

$app->run();
