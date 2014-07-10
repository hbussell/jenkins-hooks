<?php

namespace Jenkins;

use Guzzle\Http\Client;
use Symfony\Component\Yaml\Yaml;

/**
 * Manage all requests to the jenkins server.
 */
class Jenkins {

  /**
   * @var string
   *   Jenkins server uri eg: http://jenkins.mydomin.com:8080
   */
  private $jenkinsServer;

  /**
   * Create new Jenkins handler using the given server uri.
   *
   * @param string $jenkinsServer
   *   jenkins server uri.
   */
  public function __construct($jenkinsServer) {
    $this->jenkinsServer = $jenkinsServer;
  }

  /**
   * Get the Jenkins job name for the given request.
   *
   * Find the job name using the request params, first looking for a "job_name" if set.
   * Otherwise render a twig template to create the job name.
   * The twig template file can be created with the format "myproject-jobName.html.twig"
   * to overide the default "jobName.html.twig"
   *
   * @param $request
   *   Http request object
   * @param $twig
   *   Twig render environment
   * @param string $branch
   *   branch name request param
   * @param string $project
   *   project request param
   *
   * @return string
   *   job name
   */
  public static function getJobName($request, $twig, $branch, $project) {
    $jobName = $request->get('job_name');
    if ($jobName) {
      return $jobName;
    }

    $template = 'jenkins/jobName.html.twig';
    if (file_exists(__DIR__ . '/web/views/jenkins/'.$project.'-jobName.html.twig')) {
      $template = 'jenkins/'.$project.'-jobName.html.twig';
    }
    $jobName = trim($twig->render($template, array('branch'=>$branch, 'project'=>$project)));
    return $jobName;
  }


  public function createJob($app, $jobName, $project, $branch) {

    $projectConfig = __DIR__. '/config/projects/'.$project.'.yml';
    if (!file_exists($projectConfig)) {
      throw new \Exception('Could not create Jenkins job as project config file is missing.  Create project config :: ' . $projectConfig);
    }
    $configData = Yaml::parse($projectConfig);
    $configData = array_merge($configData, array('project'=>$project, 'branch'=>$branch));
    // Update configuration values using twig to render values.
    // Allowing configuration to use {{ project }} or {{ branch }} values in yaml.
    $twig = $app['twig'];
    foreach ($configData as $key=>$value) {
      $old = $twig->getLoader();
      $twig->setLoader(new \Twig_Loader_String());
      $value = $twig->render($value, $configData);
      $twig->setLoader($old);
      $configData[$key] = $value;
    }

    // Render jenkins job template xml.
    $jobXml = $app['twig']->render('jobs/'. $project .'.xml.twig', $configData);
    // Send jenkins the job template to create a new job.
    $response = $this->request("/createItem?name=$jobName", $jobXml);
    return $response;
  }


  public function createBuild($app, $requestUri, $postbackUri, $jobName) {

    $response = $this->request("/job/$jobName/build");
    $statusCode = 201;
    $template = 'jobSuccess.html.twig';
    if ($response && $response->isSuccessful()) {
      sleep(1);
      $lastBuildResponse = $this->request("/job/$jobName/lastBuild/api/json");
      $lastBuild = $lastBuildResponse->json();
      // Record that a build is in progress to the db to check back later.
      $this->saveBuild($app, $requestUri, $postbackUri, $jobName, $lastBuild['number']);
      return;
    }

    throw new \Exception('Build could not be created: '.$response->getMessage());

  }

  /**
   * Send a jenkins post requst to it's api.
   *
   * @param string $path
   *   url path
   * @param string $content
   *   http content to send
   * @param string $contentType
   *   http content type ie text/xml or text/html
   */
  public function request($path, $content='', $contentType="text/xml") {
    $url = "$this->jenkinsServer$path";
    var_dump('making jenkins request :: '. $url);
    $client = new Client($url);

    $request = $client->post(
      '',
      array('Content-Type' => $contentType . '; charset=UTF8'),
      $content,
      array('timeout' => 120)
    );

    try {
      $response = $request->send();
      return $response;

    } catch(\Exception $e){
        return $e->getResponse();
    }
    return NULL;
  }

  /**
   * Record a when a new build has been created to the database.
   *
   * @param $app
   *   silex app instance with a db
   * @param string $requestUri
   *   current request uri
   * @param string $postbackUri
   *   where to send the postback to
   * @param string $jobName
   *   jenkins job name.
   * @param int $buildNumber
   *   the job build number.
   */

  function saveBuild($app, $requestUri, $postbackUri, $jobName, $buildNumber) {
    try {
      $app['db']->insert('builds',
        array(
          'requestUri'=>$requestUri,
          'postbackUri'=>$postbackUri,
          'jobName'=>$jobName,
          'buildNumber'=>$buildNumber,
          'created'=>date("Y-m-d H:i:s")
        )
      );
    }
    catch(\Exception $e) {
      // Unique constraint exceptions can be fired with dupliate buildNumber/jobs
      // but we don't need to handle those errors.
    }
  }

}
