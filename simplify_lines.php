#!/usr/bin/env php
<?php
/**
 * Generate simplified geojson files with Douglas-Peucker algorithm.
 * 
 * Read geojson files in folder "$config['splitsFolder']".
 * Write simplified geojson files in folder "$config['cacheFolder']".
 * 
 */

declare(strict_types=1);
error_reporting(-1);

require('vendor/autoload.php');

include(__DIR__ . '/config.inc.php');

use Cyrille\RrInspect\GeometrySimplifier;
use GeoJson\GeoJson;
use GeoJson\Feature\Feature;
use GeoJson\Feature\FeatureCollection;
use GeoJson\Geometry\LineString;

$inputFolder = $config['splitsFolder'];
$outputFolder = $config['cacheFolder'];

echo 'Input folder: ', $inputFolder, "\n";
echo 'Output folder: ', $outputFolder, "\n";

$epsilon = $config['simplifier_factor'];

echo 'Simplification factor: ', $epsilon, "\n";

$filesCount = 0;
foreach (new \DirectoryIterator($inputFolder) as $fileinfo) {
    if (! $fileinfo->isFile())
        continue;
    if ($fileinfo->getExtension() != 'geojson')
        continue;
    if (preg_match('#_simplified\.geojson$#', $fileinfo->getBasename())) {
        continue;
    }
    echo '.';
    $filesCount++;
    generateSimplifiedGeoJson($fileinfo, $epsilon);
}
echo "\n";
echo 'Processed ' . $filesCount, ' files.', "\n";

function generateSimplifiedGeoJson($geojsonFileInfo, $epsilon)
{
    global $inputFolder, $outputFolder;

    $inputFile = $inputFolder . '/' . $geojsonFileInfo->getBasename();
    $outputFile = $outputFolder . '/' . $geojsonFileInfo->getBasename('.geojson') . '_simplified.geojson';
    //echo $inputFile, ' -> ', $outputFile, "\n";

    $simplifier = new GeometrySimplifier($epsilon);

    $json = json_decode(file_get_contents($inputFile));

    $featureCollection = GeoJson::jsonUnserialize($json);
    $featureCollection = $simplifier->simplifyFeatureCollection($featureCollection);

    file_put_contents($outputFile, json_encode($featureCollection->jsonSerialize()));
}
