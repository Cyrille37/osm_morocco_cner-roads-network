#!/usr/bin/env php
<?php
/**
 * Extract Axes for Shapefile and save each as geojson into folder "splits_extract".
 * Also create an "axes.csv" file to store reference and some properties.
 */

declare(strict_types=1);
error_reporting(-1);

require('vendor/autoload.php');

use GeoJson\Feature\Feature;
use GeoJson\Feature\FeatureCollection;
use Shapefile\ShapefileException;
use Shapefile\ShapefileReader;
use GeoJson\Geometry\LineString;
use GeoJson\Geometry\MultiLineString;

if ($argc != 2)
    die('Need a shapefile.' . "\n");

include(__DIR__ . '/confing.inc.php');

$outputFolder = 'splits_extract';
if (! is_dir($outputFolder)) {
    echo 'Creating output folder: ' . realpath($outputFolder);
    if (! mkdir($outputFolder))
        die('Failed to create folder' . "\n");
}

$shapefile = new ShapefileReader($argv[1]);

$stats = [
    'records' => 0,
    'records_deleted' => 0,
    'axes_count' => 0,
];

$axes = [];

echo 'Reading ', $argv[1], "\n";

try {
    while ($geometry = $shapefile->fetchRecord()) {
        $stats['records']++;
        // Skip the record if marked as "deleted"
        if ($geometry->isDeleted()) {
            $stats['records_deleted']++;
            continue;
        }
        /* DBF Data
        var_export($geometry->getDataArray());
        array (
            'CAT' => 'N',
            'AXE' => 'N6',
            'ORDREARC' => '20',
            'ETAT' => '1',
            'AXE_OLD' => 'N6',
            'CAT_OLD' => 'N',
            'ORDRE_OLD' => '20',
            )

        l'attribut "ETAT" signifie:
            1 : Route revetue
            0 : Route non revetue
            -1 : tracé non identifié sur le terrain

        "ORDREARC" permet de trier les segments

        */
        $geomData = $geometry->getDataArray();
        $geomArray = $geometry->getArray();

        if (! isset($axes[$geomData['AXE']])) {
            $stats['axes_count']++;
            $axes[$geomData['AXE']] = [
                'category' => $geomData['CAT'],
                'axe_old' => $geomData['AXE_OLD'],
                'segments' => [],
            ];
        }

        // Points & Parts
        $points = [];
        if (isset($geomArray['points'])) {
            $points = $geomArray['points'];
        } else if (isset($geomArray['parts'])) {
            foreach ($geomArray['parts'] as $i => $p) {
                $points = \array_merge($points, $p['points']);
            }
        } else
            throw new \RuntimeException('Failed parsing geom parts');

        $axes[$geomData['AXE']]['segments'][] = [
            'attributes' => $geomData,
            'ordreArc' => \intval($geomData['ORDREARC']),
            'points' => $points,
        ];

        //var_export($geometry->getArray());
        //break;
        //die('ABORT');
    }
} catch (ShapefileException $e) {
    // Print detailed error information
    echo "Error Type: " . $e->getErrorType()
        . "\nMessage: " . $e->getMessage()
        . "\nDetails: " . $e->getDetails();
}

echo 'Writing CSV & GeoJson into "' . $outputFolder . '" ... ', "\n";

$axesCsv = fopen($outputFolder . '/axes.csv', 'w');

fputcsv($axesCsv, array_keys($config['axes_csv']['columns']));

$filenames = [];

foreach ($axes as $k => &$axe) {

    // Order segments
    $arr = $axe['segments'];
    usort($arr, function ($a, $b) {
        return ($a['ordreArc'] < $b['ordreArc']) ? -1 : 1;
    });
    $axe['segments'] = $arr;

    $features = [];
    $etatPaved = $etatUnpaved = $etatUnknow = 0;
    foreach ($axe['segments'] as $seg) {

        switch ($seg['attributes']['ETAT']) {
            case '1':
                $etatPaved++;
                break;
            case '0':
                $etatUnpaved++;
                break;
            case '-1':
                $etatUnknow++;
                break;
        }

        //var_export($seg['points']);
        $points = [];
        foreach ($seg['points'] as $i => $p) {
            //echo $i,' ',var_export($p,true),"\n";
            $points[] = [$p['x'], $p['y']];
        }

        $features[] = new Feature(new LineString($points), [
            // "name" pour josm 😉
            'name' => $k,
            'axe' => $k,
            'axe_old' => $axe['axe_old'],
            'cat' => $axe['category'],
            'etat' => $seg['attributes']['ETAT'],
        ]);
    }

    // Output AXE list
    $row = [
        $config['axes_csv']['columns']['axe'] => $k,
        $config['axes_csv']['columns']['axe_old'] => $axe['axe_old'],
        $config['axes_csv']['columns']['category'] => $axe['category'],
        $config['axes_csv']['columns']['etatPaved'] => $etatPaved,
        $config['axes_csv']['columns']['etatUnpaved'] => $etatUnpaved,
        $config['axes_csv']['columns']['etatUnknow'] => $etatUnknow
    ];
    fputcsv($axesCsv, $row);

    $featureCollection = new FeatureCollection($features);

    $filename = $outputFolder . '/' . $k . '_rr.geojson';
    file_put_contents($filename, json_encode($featureCollection->jsonSerialize()));

    if (! preg_match('#^([A-Z])(\d+)$#', $k, $m))
        die('Failed to parse ref "' . $k . '"' . "\n");
    $filenames[] = [
        'ref' => $k,
        'refP' => $m[1],
        'refN' => $m[2],
        'cat' => $axe['category'],
        'filename' => $filename,
    ];
}

fclose($axesCsv);

html_index($filenames);

//echo 'Axes: ', var_export($axes['N6'], true), "\n";
echo 'Stats: ', var_export($stats, true), "\n";

function html_index($data)
{
    global $config, $outputFolder;

    echo 'Writing Index', "\n";

    $cats = [];
    foreach ($data as $idx => $datum) {
        if (! isset($cats[$datum['cat']])) {
            $cats[$datum['cat']] = [];
        }
        $cats[$datum['cat']][] = $datum;
    }

    $title = 'Maroc réseau routier 2025-01';

    $html = '';
    $html .= '<!doctype html>
<html>
 <head>
  <meta charSet="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>' . $title . '</title>
</head>
<body>';
    $html .= '<h1>' . $title . '</h1>' . "\n";
    $html .= '<p>Découpage au format GeoJson du fichier <a href="https://drive.google.com/open?id=1qqO2IOFTaGE5Lzw15W5t2DM6T8LWG5yn">reseau_routier</a>.</p>';
    $html .= '<p>Résumé au format tableau (CSV) <a href="axes.csv">axes.csv</a></p>';
    foreach ($cats as $cat => $datum) {
        $html .= '<h2>' . $cat . '</h2>' . "\n";
        $html .= '<p>' . count($datum) . ' tracés:' . '</p>' . "\n";
        usort($datum, function ($a, $b) {
            return ($a['refN'] < $b['refN']) ? -1 : 1;
        });
        $html .= '<p>';
        foreach ($datum as $d) {
            $html .= ' - <a href="' . $d['filename'] . '">' . $d['ref'] . '</a>';
        }
        $html .= '</p>';
    }
    $html .= '
</body>
</html>';

    file_put_contents($outputFolder . '/index.html', $html);
}
