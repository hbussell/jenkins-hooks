<?php

require_once __DIR__.'/../vendor/autoload.php';
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Jenkins\Jenkins;

$app = new Silex\Application();

if (!file_exists(__DIR__.'/../config/config.php')) {
  print 'Please copy config.php.j2 to config.php and configure your settings.';
  die();
}


require_once __DIR__.'/../config/config.php';

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
));

$app->before(function (Request $request) {
    if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
        $data = json_decode($request->getContent(), true);
        $request->request->replace(is_array($data) ? $data : array());
    }
});

/**
 * Return the last build status or triger a build if needed.
 *
 * This will check the jenkins /lastBuild/api/json page for the current build details,
 * returning the build status if present, if not present a new build will be triggered.
 */

/**
 * Return the current build status if the job is building,
 * or create a new build.
 *
 * This action also will create the job if needed making it the best
 * single url to access from external systems.
 *
 * If a job has been created or is still running save an
 * entry to the database to allow a post back when the build completes.
 */
$app->get('/build-job-create', function (Request $request) use ($app) {
  $branch = $request->get('branch');
  $project = $request->get('project');
  $postbackUri = $request->get('postback_uri');

  if (!$branch || !$project || !$postbackUri) {
    return renderConditional($app, $request, 'error.html.twig', array('message'=>'Missing required fields: branch, project, postback_uri'), 400);
  }

  $jenkins = new Jenkins($app['jenkins_uri']);
  $jobName = Jenkins::getJobName($request, $app['twig'], $branch, $project);

  // First check if the job exists.
  $response = $jenkins->request("/job/$jobName/api/json");
  if ($response && $response->isSuccessful()) {

    $buildingResponse = getBuildingResponse($app, $jenkins, $request, $jobName);
    if ($buildingResponse) {
      return $buildingResponse;
    }

    // Trigger new build.
    return createBuildResponse($jenkins, $request->getRequestUri(), $postbackUri, $jobName);

  }

  // Job does not exist so lets create it.
  try {
    $response = $jenkins->createJob($app, $jobName, $project, $branch);
  }
  catch(\Exceptoin $e) {
    return renderConditional($app, $request, 'error.html.twig', array('message'=>$e->getMessage()), 400);
  }

  // Trigger new build.
  return createBuildResponse($jenkins, $request->getRequestUri(), $postbackUri, $jobName);


  //return 'Submit build by passing branch, project and postback_uri';
});

/**
 * Return the current build status if the job is building,
 * or create a new build.
 *
 * If a job has been created or is still running save an
 * entry to the database to allow a post back when the build completes.
 */
$app->get('/build-create', function (Request $request) use ($app) {

  $branch = $request->get('branch');
  $project = $request->get('project');
  $postbackUri = $request->get('postback_uri');

  if (!$branch || !$project || !$postbackUri) {
    return renderConditional($app, $request, 'error.html.twig', array('message'=>'Missing required fields: branch, project, postback_uri'), 400);
  }

  $jenkins = new Jenkins($app['jenkins_uri']);
  $jobName = Jenkins::getJobName($request, $app['twig'], $branch, $project);

  // Get a response if there is currently a build in progress.
  $buildingResponse = getBuildingResponse($app, $jenkins, $request, $jobName);
  if ($buildingResponse) {
    return $buildingResponse;
  }

  // Trigger new build.
  return createBuildResponse($jenkins, $request->getRequestUri(), $postbackUri, $jobName);
});


/**
 * Create new Jenkins job if none found for the the given branch.
 *
 * If a job alread exists return the job details.
 */
$app->get('/job', function (Request $request) use ($app) {

  $branch = $request->get('branch');
  $project = $request->get('project');

  if (!$branch || !$project) {
    return renderConditional($app, $request, 'error.html.twig', array('message'=>'Missing required fields: branch, project'), 400);
  }

  $jenkins = new Jenkins($app['jenkins_uri']);
  $jobName = Jenkins::getJobName($request, $app['twig'], $branch, $project);

  // First check if there is a job already.
  $response = $jenkins->request("/job/$jobName/api/json");
  if ($response && $response->isSuccessful()) {
    // There is a job so return its details.
    $jobDetails = $response->json();
    $data = array(
      'project' => $project,
      'branch' => $branch,
      'status' => 'job_exists',
      'message' => 'Current Job Details',
      'job' => $jobDetails['displayName'],
      'url' => $jobDetails['url'],
      'healthReport' => (array_key_exists('healthReport', $jobDetails) && !empty($jobDetails['healthReport'])) ? $jobDetails['healthReport'][0]['description'] : '',
      'lastBuildUrl' => $jobDetails['lastBuild']['url']
    );
    return renderConditional($app, $request, 'job.html.twig', $data, 202);
  }

  // Create a new job using this project configuration.
  try {
    $response = $jenkins->createJob($app, $jobName, $project, $branch);
  }
  catch(\Exceptoin $e) {
    return renderConditional($app, $request, 'error.html.twig', array('message'=>$e->getMessage()), 400);
  }
  $data = array(
    'message' => $response->isSuccessful() ? 'Job Created' : 'Job Failed',
    'jenkins_status_code' => $response->getStatusCode(),
    'job' => $jobName,
    'url' => $app['jenkins_uri'] . "/job/$jobName/"
  );

  $statusCode = $response->isSuccessful() ? 201 : 400;

  return renderConditional($app, $request, 'jobSuccess.html.twig', $data, $statusCode);
});

/**
 * Github post commit hook.
 */
$app->get('/github/commit', function (Request $request) use ($app) {
  $content = $request->get();
  // Get branch to build from github payload.
  $json = json_decode($content);
  $branch = $json['branch'];

  $modified = $json['modified'];

  $buildBranch = FALSE;
  
  if (in_array($branch, array('develop', 'stage', 'master'))) {
    $buildBranch = TRUE;
  } 
  else {
    foreach ($modified as $file) {
      if (preg_match($file, '/(\.php|\.inc|\.module)$/')) {
        $buildBranch = TRUE;
        break;
      }
    }
  }
    
  if ($buildBranch) {
    $jenkins = new Jenkins($app['jenkins_uri']);
    $jobName = Jenkins::getJobName($request, $app['twig'], $branch, $project);

    // Get a response if there is currently a build in progress.
    $buildingResponse = getBuildingResponse($app, $jenkins, $request, $jobName);
    if ($buildingResponse) {
      return $buildingResponse;
    }

    // Trigger new build.
    return createBuildResponse($jenkins, $request->getRequestUri(), $postbackUri, $jobName);
  }

  $data = array(
    'message' => 'Job not needed for build'
  );
  // Return default no build message
  return renderConditional($app, $request, 'jobNotCreated.html.twig', $data);
});

/**
 * Homepage route
 */
$app->get('/', function (Request $request) use ($app) {
  return renderConditional($app, $request, 'index.html.twig');
});

/**
 * Render the data as a html page or json depending on the request content-type.
 *
 * @param $app
 *   Silex app instance
 * @param Symfony\Component\HttpFoundation\Request $request
 *   Request object
 * @param string $template
 *   template path
 * @param array $data
 *   array sent to template or converted to json
 * @param int $statusCode
 *   http status code
 * @return Symfony\Component\HttpFoundation\Response
 *   response object
 */
function renderConditional($app, $request, $template, $data=array(), $statusCode=200){

  if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
    $response = new Response($app->json($data));
  } else {
    $response = new Response($app['twig']->render($template, $data));
  }
  $response->setStatusCode($statusCode);
  return $response;
}


function createBuildResponse($jenkins, $requestUri, $postbackUri, $jobName){
  $statusCode = 201;
  $template = 'jobSuccess.html.twig';

  try {
    $jenkins->createBuild($app, $requestUri, $postbackUri, $jobName);
    $data = array(
      'message' => 'Build created',
      'status' => 'build_created',
      'job' => $jobName,
    );
  }
  catch(\Exception $e) {
    $data = array(
      'message' => 'Build could not be created',
      'status' => 'build_failure',
      'job' => $jobName
    );
    $statusCode = 400;
    $template = 'error.html.twig';
  }

  return renderConditional($app, $request, $template, $data, $statusCode);
}

function getBuildingResponse($app, $jenkins, $request, $jobName) {
  $response = $jenkins->request("/job/$jobName/lastBuild/api/json");
  if ($response && $response->isSuccessful()) {

    $lastBuild = $response->json();
    if ($lastBuild['building'] == 'true') {
      // Record that a build is in progress to the db to check back later.
      $jenkins->saveBuild($app, $request->getRequestUri(), $postbackUri, $jobName, $lastBuild['number']);

      $data = array(
        'message' => 'Currently Building Job '. $lastBuild['number'],
        'status' => 'building',
        'job' => $jobName,
        'buildNumber' => $lastBuild['number'],
        'url' => "https://jenkins.aws.fclweb.net/job/$jobName/" . $lastBuild['number']
      );
      return renderConditional($app, $request, 'building.html.twig', $data, 202);
    }
  }

  return FALSE;
}

// Run the Silex application!
$app->run();
