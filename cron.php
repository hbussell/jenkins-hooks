<?php

require_once __DIR__.'/vendor/autoload.php';

use Guzzle\Http\Client;
use Jenkins\Jenkins;

$app = new Silex\Application();

require_once __DIR__.'/config/config.php';


$sql = "SELECT * FROM builds order by created ASC limit 10";
$builds = $app['db']->fetchAll($sql);

$jenkins = new Jenkins($app['jenkins_uri']);
if (empty($builds)) {
  print 'No builds found.' . PHP_EOL;
}
foreach ($builds as $build) {
  $jobName = $build['jobName'];
  $response = $jenkins->request("/job/$jobName/lastBuild/api/json");
  if ($response && $response->isSuccessful()) {

    $lastBuild = $response->json();
    if ($lastBuild['building'] != 'true') {
      $number = $lastBuild['number'];
      if ($number >= $build['buildNumber']) {
        // last build is higher that the database record so it can be removed
        // and postback url can be fired.
        $postbackUri = $build['postbackUri'];
        $client = new Client($postbackUri);

        $contentType = 'application/json';
        $details = array(
          'jobName'=>$jobName,
          'buildNumber'=>$build['buildNumber'],
          'status'=>'built'
        );
        $content = json_encode($details);
        $request = $client->post(
          '',
          array('Content-Type' => $contentType . '; charset=UTF8'),
          $content,
          array('timeout' => 120)
        );
        try {
          $response = $request->send();
          print 'Postback sent!'.PHP_EOL;
        } catch(\Exception $e){
          print 'Postback failed to send!'.PHP_EOL;
        }
        $app['db']->delete('builds', array('id'=>$build['id']));
      }
    }
  }
}
