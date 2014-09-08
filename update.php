<?php
/*

@author Miguel Chateloin
@license GPLv2 (http://www.gnu.org/licenses/gpl-2.0.html)

*/

use Guzzle\Http\Client as GuzzleClient;
use Guzzle\Plugin\Cookie\CookiePlugin;
use Guzzle\Plugin\Cookie\CookieJar\ArrayCookieJar;

require 'vendor/autoload.php';
$config = require 'config.php';

const ENDPOINT_MOODLE_LOGIN = '/v2.1/login/index.php';
const ENDPOINT_MOODLE_NAVBRANCH = '/v2.1/lib/ajax/getnavbranch.php';
const REGEX_SESSKEY = '/"sesskey":"\w+"/';
const REGEX_INSTANCE = '/"instance":"\w+"/';
const REGEX_MONTH = '((jan|feb).*ary|march|april|may|june|july|august|(sep|oct|nov|dec).*ber)';

function hasMonth($str)
{
    return preg_match(REGEX_MONTH, $str) > 0 ? true : false;
}


function toFolderName($str)
{
    if(hasMonth($str) and strrpos($str, '-') > -1)
    {
        $dateRange = explode('-', $str);
        $weekOf = date('m-d', strtotime($dateRange[0]));
        return $weekOf;
    }

    return preg_replace('/[^\w\-]+/', '', strtolower($str));
}

function toFileName($str)
{
    $snakeCased = preg_replace('/ /', '-', trim(strtolower($str)));
    return preg_replace('/[^\w\.\-]+/', '', $snakeCased);
}

$client = new GuzzleClient($config['moodleHost']);
$cookiePlugin = new CookiePlugin(new ArrayCookieJar());
$client->addSubscriber($cookiePlugin);

$res =  $client
          ->post(ENDPOINT_MOODLE_LOGIN)
          ->addPostFields([
            'username' => $config['login']['username'],
            'password' => $config['login']['password'],
          ])
          ->send();

$sessKeyMatches = [];
$instanceMatches = [];
preg_match(REGEX_SESSKEY, $res->getBody(), $sessKeyMatches);
preg_match(REGEX_INSTANCE, $res->getBody(), $instanceMatches);
$defaultFormData = [
  'sesskey' => str_replace(['"sesskey":"', '"'], '', $sessKeyMatches[0]),
  'instance' => str_replace(['"instance":"', '"'], '', $instanceMatches[0]),
];

foreach($config['classes'] as $code => $class)
{
    echo "Checking $code\n";
    $resClass =   $client
                    ->post(ENDPOINT_MOODLE_NAVBRANCH)
                    ->addPostFields(array_merge($defaultFormData, [
                      'elementid' => "expandable_branch_20_{$class['id']}",
                      'id' => $class['id'],
                      'type' => 20,
                    ]))
                    ->send();
    $classHandle = json_decode($resClass->getBody());

    if(!$classHandle->haschildren) continue;

    foreach($classHandle->children as $i => $section)
    {
        if(in_array($section->name, $class['sectionIgnore'])) continue;

        echo "\t Checking {$section->key}:{$section->id}:{$section->name}\n";



        $resResources = $client
                          ->post(ENDPOINT_MOODLE_NAVBRANCH)
                          ->addPostFields(array_merge($defaultFormData, [
                            'elementid' => $section->id,
                            'id' => $section->key,
                            'type' => $section->type,
                          ]))
                          ->send();

        $resourcesHandle = json_decode($resResources->getBody());

        if(!$resourcesHandle->haschildren) continue;

        foreach($resourcesHandle->children as $i => $resource)
        {

            $folderTargetPath = "{$class['target']}/" . toFolderName($section->name);

            if(!file_exists($folderTargetPath))
            {
                mkdir($folderTargetPath, 0775);
            }

            if(!isset($resource->title)) continue;

            if($resource->title == 'File')
            {
                if(count(glob("$folderTargetPath/{$resource->key}-*")) > 0) continue;

                $tmpFile = tempnam(sys_get_temp_dir(), 'moodle-scraper-download');
                $fileHandle = fopen($tmpFile, 'w');

                $resFile =  $client
                              ->get($resource->link)
                              ->setResponseBody($tmpFile)
                              ->send();

               

                $contentDisposition = $resFile->getContentDisposition();
                $fileName = substr($contentDisposition, strpos($contentDisposition, 'filename="') + strlen('filename="'), -1);
              
                $properFilePath ="$folderTargetPath/{$resource->key}-" . toFileName($fileName);

                echo "\t\tDownloaded file $fileName to $properFilePath\n";                  

                fclose($fileHandle);
                rename($tmpFile, $properFilePath);
            }
            else
            {
                $fileTargetPath = "$folderTargetPath/" . toFileName($resource->name) . ".html";

                if(file_exists($fileTargetPath)) continue;

                $resPage =  $client
                              ->get($resource->link)
                              ->setResponseBody($fileTargetPath)
                              ->send();
                echo "\t\tDownloaded page {$resource->name} to $fileTargetPath\n";  
            }
        }

    }
}
