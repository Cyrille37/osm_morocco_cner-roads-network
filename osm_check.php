#!/usr/bin/env php
<?php
/**
 * 
 ** Les axes à traiter
 * 
 * Les référence des axes sont chargés depuis un fichier CSV
 * générés lors de la constitutions des Splits RR (cf. extract_shapefile).
 * 
 * Traiter un seul ou plusieurs axes routiers
 * 
 *  `./osm_check.php --process_only=P3430,P3412`
 * 
 ** Reset de l'analyse
 *
 * ```
 *  rm cache/*
 *  ./rr_inspect/simplify_lines.php
 * ```
 * 
 ** Ajuster les Splits RR
 *
 * Les Splits du RR sont le tracé de référence, leur modification ne doit donc
 * concerner que l'adaptation sur les rond-points ou autres petites bizarreries de traçés.
 * 
 * Pour adapter un Split du RR:
 * - conserver les data OSM pour ne pas les télécharger à chaque essais (option --cacheDontDeleteOnError)
 * - dans Josm
 *  - charger le tracé OSM
 *  - charger le Split RR non simplifié
 *  - corriger le Split RR et enregistrer
 * - relancer l'analyse sur le tracé (option --process_only)
 * 
 * `./osm_check.php --process_only=P3430 --cacheDontDeleteOnError`
 * 
 * Lors d'une erreur 'match_rr_cner' le Split RR simplifié (*_simplified.geojson) est supprimé.
 * Pour les regénérer voir le script 'simplify_lines.php'.
 * 
 * - Option pour enregistrer les rectangles
 *  `./osm_check.php --process_only=P3430 --cacheRects --cacheDontDeleteOnError`
 * 
 ** Options
 *
 *  - process_only : a list of Axe's ref to process.
 *  - cacheRects : to generate geojson of rectangles.
 *  - cacheDontDeleteOnError : to don't delete osm file on error.
 *  - overpassSleep : to override the default wait time after a overpass query.
 * 
 */

declare(strict_types=1);
error_reporting(-1);

require('vendor/autoload.php');

use Cyrille\RrInspect\Ansi;
use Cyrille\RrInspect\Common;
use Cyrille\RrInspect\GeometryTools;
use Cyrille\RrInspect\HistoryFile;
use GeoJson\Exception\UnserializationException;
use GeoJson\GeoJson;
use GeoJson\Feature\Feature;
use GeoJson\Feature\FeatureCollection;
use GeoJson\Geometry\LineString;

include(__DIR__ . '/config.inc.php');

readOptions();

// To save $stats in a file.
$resultFile = $config['analyze_results']['file'];
$resultFreq = $config['analyze_results']['save_freq'] ?? 0;

$stats = [
    'start_at' => time(),
    'end_at' => null,
    'processed_count' => 0,
    'skipped_count' => 0,
    'download_count' => 0,
    'download_bytes' => 0,
    'ways_count' => 0,
    'relations_count' => 0,
    'errors_effective_counts' => [],
    'errors_ignored_counts' => [],
    'errors_still_exists_count' => 0,
    'resultFile' => $resultFile,
    'axes' => [],
    'old_axes' => [],
    'config' => $config,
];

$common = new Common($config, $stats);

$historyFile = new HistoryFile($config['history']);

$axesFile = fopen($config['axes_csv']['file'], 'r');

// Handle Ctrl+C

pcntl_async_signals(TRUE);
pcntl_signal(SIGINT, 'pcntl_signal_handler');
function pcntl_signal_handler(int $signo, mixed $siginfo): void
{
    global $historyFile, $resultFile, $stats;
    echo 'Saving Analyze file', "\n";
    file_put_contents($resultFile, json_encode($stats));
    echo 'Saving History file', "\n";
    $historyFile->save();
    die();
}

// Read axes file

// Get current axes to find no more existing old_axe.
$axesActuals = [];

$headers = $row = fgetcsv($axesFile);
while ($row = fgetcsv($axesFile)) {

    $ref = $row[$config['axes_csv']['columns']['axe']];

    if (empty($ref) || ! preg_match('#^[NRP]\d+$#', $ref)) {
        echo 'Invalid ref:[' . $ref . '], check column "axe"', "\n";
        exit();
    }

    $axesActuals[$ref] = true;

    /*
    Process row if:
        - in process_only
        - or column "done" != -1 and != ''
    */

    if (isset($config['process_only']) && (count($config['process_only']) > 0)) {
        if (! in_array($ref, $config['process_only']))
            continue;
    } else {
        $stateDone = $row[$config['axes_csv']['columns']['done']];
        if (in_array($stateDone, ['', '-1'])) {
            continue;
        }
    }

    $stats['axes'][$ref] = [
        'start_at' => time(),
        'relation' => 0,
        'ways' => 0,
        'rr_etat' => [
            'paved' => $row[$config['axes_csv']['columns']['etatPaved']],
            'unpaved' => $row[$config['axes_csv']['columns']['etatUnpaved']],
            'unknow' => $row[$config['axes_csv']['columns']['etatUnknow']],
        ],
        'download' => null,
        'errors_ignored_count' => [],
        'errors' => [],
    ];

    if ($historyFile->needUpdate($ref, $stats['start_at']) || (! file_exists($common->osm_filename($ref)))) {

        echo Ansi::BOLD, 'Processing ', $ref, Ansi::CLOSE, Ansi::EOL;
        process_ref($row);
        $historyFile->update($ref, count($stats['axes'][$ref]['errors']) > 0 ? true : false);
        $stats['processed_count']++;
    } else {
        echo 'Skip ', $ref, Ansi::EOL;
        $stats['axes'][$ref]['start_at'] = $historyFile->getOkAt($ref);
        $stats['skipped_count']++;
    }

    // Periodically save $stats in a file.
    if ($resultFreq > 0) {
        if ($stats['processed_count'] % $resultFreq == 0) {
            file_put_contents($resultFile, json_encode($stats));
            $historyFile->save();
        }
    }
}
fclose($axesFile);

process_old_axes();

$historyFile->save();

$stats['end_at'] = time();
// Save $stats in a file.
file_put_contents($resultFile, json_encode($stats));

unset($stats['config']);
unset($stats['axes']);
unset($stats['old_axes']);
echo 'Stats: ', print_r($stats, true), "\n";

echo Ansi::BACKGROUND_GREEN, Ansi::BOLD, Ansi::WHITE, 'Done ', date_format(new DateTime('now', new DateTimeZone('Europe/Paris')), \DateTimeInterface::RFC2822), Ansi::CLOSE_AND_EOL;

//if ($stats['processed_count'] == 0)
//    echo Ansi::BACKGROUND_BLACK, Ansi::YELLOW, 'Nothing processed, check "--process_only" and/or "config.process_only".', Ansi::CLOSE, Ansi::EOL;

//
// ===== end =====
//

/**
 * Read command line options and override config.
 * 
 * @return void 
 */
function readOptions()
{
    global $config;

    $shortopts = '';
    $longopts = [
        'process_only:',
        'cacheRects',
        'cacheDontDeleteOnError',
        'overpassSleep:',
        'forceDownload',
    ];

    /*
    getopt() does not fail if an option is missing;
    instead the option is not included in the return value.
    */
    $options = getopt($shortopts, $longopts);

    if (isset($options['process_only'])) {
        $config['process_only'] = explode(',', $options['process_only']);
    }

    if (isset($options['cacheRects'])) {
        $config['cacheRects'] = true;
    }

    if (isset($options['cacheDontDeleteOnError'])) {
        $config['cacheDeleteOnError'] = false;
    } else {
        $config['cacheDeleteOnError'] = $config['cacheDeleteOnError'] ?? true;
    }

    if (isset($options['overpassSleep'])) {
        $n = floatval($options['overpassSleep']);
        $config['overpass']['sleep'] = $n > 0.0 ? $n : $config['overpass']['sleep'];
    }

    if (isset($options['forceDownload'])) {
        $config['download_force'] = true;
    }
}

function is_error_ignored($ref, $type): bool
{
    global $config;
    if (isset($config['errors']['ignore_types'][$type])) {
        $rule = $config['errors']['ignore_types'][$type];
        if (! is_array($rule))
            return true;
        else if (in_array($ref, $rule))
            return true;
    }
    return false;
}

function add_error($ref, $type, $err)
{
    global $config, $stats, $common;

    if (! in_array($type, $config['errors']['keys']))
        throw new \InvalidArgumentException('Unknow error type, sync your code by adding "' . $type . '" in config.errors.keys array');

    // initialisation
    if (empty($stats['errors_effective_counts'])) {
        foreach ($config['errors']['keys'] as $key) {
            $stats['errors_effective_counts'][$key] = 0;
            $stats['errors_ignored_counts'][$key] = 0;
        }
    }

    $skip = is_error_ignored($ref, $type);
    /*
    if (isset($config['errors']['ignore_types'][$type])) {
        $rule = $config['errors']['ignore_types'][$type];
        if (! is_array($rule))
            $skip = true;
        else if (in_array($ref, $rule))
            $skip = true;
    }*/

    if ($skip) {
        $stats['errors_ignored_counts'][$type]++;

        if (! isset($stats['axes'][$ref]['errors_ignored_count'][$type])) {
            $stats['axes'][$ref]['errors_ignored_count'][$type] = 1;
        } else {
            $stats['axes'][$ref]['errors_ignored_count'][$type]++;
        }
        return;
    }

    $stats['errors_effective_counts'][$type]++;

    if (! isset($stats['axes'][$ref]['errors'][$type]))
        $stats['axes'][$ref]['errors'][$type] = [];
    $stats['axes'][$ref]['errors'][$type][] = $err;

    if ($config['debug'])
        echo Ansi::YELLOW, $ref, ' ', $type, ' ', $err, Ansi::CLOSE, Ansi::EOL;

    if ($config['cacheDeleteOnError']) {
        // delete osm file in case of error
        $cacheFile = $common->osm_filename($ref);
        if (file_exists($cacheFile))
            unlink($common->osm_filename($ref));
    }
}

function process_ref(&$row)
{
    global $config, $stats, $common;

    $ref = $row[$config['axes_csv']['columns']['axe']];

    $xml = null;
    // Catch retry in case of response error.
    $retry_count = 3;
    $retry_sleep = 10;
    for ($i = 0; $i < $retry_count; $i++) {
        $result = $common->download_osm($ref);
        $stats['axes'][$ref]['download'] = $result;

        $osm_file = $config['cacheFolder'] . '/' . $ref . '_osm.osm';
        $xml = new SimpleXMLElement(file_get_contents($osm_file));
        if ($xml->getName() == 'osm') {
            // Ok, result is an OSM document
            break;
        }
        echo "\t", 'Invalid OSM document, sleeping ', $retry_sleep, ' seconds before retry ...', "\n";
        sleep($retry_sleep);
    }
    if (! $xml) {
        echo Ansi::BACKGROUND_RED, Ansi::WHITE, 'ERROR, failed to download osm for ref ', $ref, Ansi::CLOSE, Ansi::EOL;
        die();
    }

    $ways_id_in_relation = [];

    if (isset($xml->relation)) {

        if (count($xml->relation) > 1) {
            $stats['axes'][$ref]['relation'] = count($xml->relation);
            add_error($ref, 'relation_error', 'too many relations: ' . count($xml->relation));
            return;
        }

        $stats['relations_count']++;
        $stats['axes'][$ref]['relation'] = 1;
        $rel = $xml->relation[0];

        // Assert ref start with 'R'
        $relRef = Common::osm_object_get_tag($rel, 'ref');
        if (! $relRef || ! preg_match('#(?:^|;)R' . $ref . '(?:$|;)#', $relRef)) {
            add_error($ref, 'mismatch_ref', 'rel: ' . (string) $rel['id'] . ' ref: ' . $relRef);
        }
        // Assert has tag "highway"
        if (! Common::osm_object_has_tag($rel, 'highway')) {
            add_error($ref, 'relation_error', 'missing "highway" tag rel: ' . (string) $rel['id']);
        }

        // Store way members found in relation
        foreach ($rel->member as $member) {
            if ($member['type'] != 'way')
                continue;
            /** @disregard P1006 */
            $stats['axes'][$ref]['ways']++;
            $ways_id_in_relation[] = (string) $member['ref'];
        }
    } else {
        $stats['axes'][$ref]['relation'] = false;
        add_error($ref, 'relation_error', 'relation not found');
    }

    $nodesId = [];

    foreach ($xml->way as $way) {
        $stats['ways_count']++;
        $wayId = (string) $way['id'];

        // Assert ref start with 'R'
        $wayRef = Common::osm_object_get_tag($way, 'ref');
        if (! $wayRef || ! preg_match('#(?:^|;)R' . $ref . '(?:$|;)#', $wayRef)) {
            add_error($ref, 'mismatch_ref', 'way: ' . $wayId . ' ref: ' . $wayRef);
        }
        // Assert way has tag "surface" if it's not a "track"
        if (! Common::osm_object_has_tag($way, 'highway', 'track') && ! Common::osm_object_has_tag($way, 'surface')) {
            add_error($ref, 'missing_surface', 'way: ' . $wayId);
        }
        // Assert well formed "proposed"
        // @see https://osm.org/changeset/166553384
        if (Common::osm_object_has_tag($way, 'proposed') && ! Common::osm_object_has_tag($way, 'highway', 'proposed')) {
            add_error($ref, 'way_tags_error', 'malformed "proposed" on way: ' . $wayId);
        }

        // Assert way is in relation
        if (! in_array($wayId, $ways_id_in_relation)) {
            /** @disregard P1006 */
            $stats['axes'][$ref]['ways']++;
            add_error($ref, 'ways_not_in_relation', 'way: ' . $wayId);
        }

        // Store start & end nodes for continuity check
        foreach ($way->nd as $nd) {
            $ndId = (string) $nd['ref'];
            if (!isset($nodesId[$ndId]))
                //$nodesId[$ndId] = count($nodesId) == 0 ? 2 : 1;
                $nodesId[$ndId] = 1;
            else
                $nodesId[$ndId]++;
        }
    }

    // Check continuity
    if ($stats['axes'][$ref]['ways'] > 1) {
        foreach ($xml->way as $way) {
            $wayId = (string) $way['id'];
            $nsId = (string) $way->nd[0]['ref'];
            $neId = (string) $way->nd[count($way->nd) - 1]['ref'];

            if ($nodesId[$nsId] <= 1 && $nodesId[$neId] <= 1) {
                // Could be a "V" like here : P4020 /way/681502913
                $found = false;
                foreach ($way->nd as $nd) {
                    if (isset($nodesId[(string) $nd['ref']])) {
                        $found = true;
                        break;
                    }
                }
                if (! $found)
                    add_error($ref, 'missing_continuity', 'way ' . $wayId . ' nsId: ' . $nsId . ' neId: ' . $neId);
            }
        }
    }

    // For long route like RR & RN do not make the check if is ignored.
    if (is_error_ignored($ref, 'match_rr_cner')) {
        add_error($ref, 'match_rr_cner', 'ignore compare_with_rr_cner');
    } else {
        list($cnerMatchWays, $wayMatchCNER) = compare_with_rr_cner($ref, $xml->way);
        if (! $cnerMatchWays) {
            add_error($ref, 'match_rr_cner', 'cner dont match osm');
        }
        if (! $wayMatchCNER) {
            add_error($ref, 'match_rr_cner', 'osm dont match cner');
        }
    }
}

function compare_with_rr_cner($ref, $xmlWays)
{
    global $config;

    $cacheRects = $config['cacheRects'];

    $length = $config['geometry']['bouding-box']['padding-x'];
    $width = $config['geometry']['bouding-box']['padding-y'];

    $cacheRectsFeatures = [];

    // Make rectangles from OSM data

    $rectangles_osm = [];

    // Iterate OSM ways
    foreach ($xmlWays as $way) {
        $nc = count($way->nd);
        for ($i = 0; $i < $nc - 1; $i++) {

            $nodeId = (string) $way->nd[$i]['ref'];
            $node = $way->xpath('//node[@id="' . $nodeId . '"]')[0];
            if (! $node)
                throw new \Exception('Node not found');
            $lon1 = (float) $node['lon'];
            $lat1 = (float) $node['lat'];
            $nodeId = (string) $way->nd[$i + 1]['ref'];
            $node = $way->xpath('//node[@id="' . $nodeId . '"]')[0];
            $lon2 = (float) $node['lon'];
            $lat2 = (float) $node['lat'];

            $rect = GeometryTools::computeRectangle($lat1, $lon1, $lat2, $lon2, $width, $length);
            $rectangles_osm[] = $rect;

            if ($cacheRects) {
                $cacheRectsFeatures[] = new Feature(new LineString($rect), []);
            }
        }
    }

    if ($cacheRects) {
        /* cache osm rectangles in file */
        $featureCollection = new FeatureCollection($cacheRectsFeatures);
        file_put_contents($config['cacheFolder'] . '/' . $ref . '_rects_osm.geojson', json_encode($featureCollection->jsonSerialize()));
    }

    // Check RR points are in OSM rectangles

    $featureCollection = getRrGeojson($ref);

    $cnerMatchWays = true;

    $rectangles_rr_cner = [];
    $cacheRectsFeatures = [];

    // Iterate RR features
    foreach (
        $featureCollection as
        /** @var \GeoJson\Feature\Feature $feature */
        $feature
    ) {

        $props = $feature->getProperties();
        $rr_etat = $props['etat'] ?? null;

        $coords = $feature->getGeometry()->getCoordinates();
        $coordsCount = count($coords);
        for ($i = 0; $i < $coordsCount; $i++) {
            $coord = $coords[$i];
            $point = [$coord[0], $coord[1]];

            // Build rectangle
            if ($i < $coordsCount - 1) {
                $coord2 = $coords[$i + 1];
                $rect = GeometryTools::computeRectangle($point[1], $point[0], $coord2[1], $coord2[0], $width, $length);
                $rectangles_rr_cner[] = $rect;
                if ($cacheRects) {
                    $cacheRectsFeatures[] = new Feature(new LineString($rect), []);
                }
            }

            // Don't check RR in OSM if "etat=-1"
            if (! in_array($rr_etat, [null, '-1'])) {
                $isInside = false;
                foreach ($rectangles_osm as $rect) {
                    $isInside = GeometryTools::isPointInPolygon($point, $rect);
                    if ($isInside)
                        break;
                }
                if (! $isInside) {
                    add_error($ref, 'match_rr_cner', 'cner!=osm: segment:' . $coordsCount . ' position:' . $i . ' point:' . $coord[0] . ',' . $coord[1]);
                    $cnerMatchWays = false;
                }
            }
        }
    }

    if ($cacheRects) {
        /* cache cner rectangles in file */
        $featureCollection = new FeatureCollection($cacheRectsFeatures);
        file_put_contents($config['cacheFolder'] . '/' . $ref . '_rects_cner.geojson', json_encode($featureCollection->jsonSerialize()));
    }

    // Check OSM nodes avec in RR rectangles

    $wayMatchCNER = true;

    // Iterate OSM ways
    foreach ($xmlWays as $way) {

        $nc = count($way->nd);
        for ($i = 0; $i < $nc - 1; $i++) {
            $nodeId = (string) $way->nd[$i]['ref'];
            $node = $way->xpath('//node[@id="' . $nodeId . '"]')[0];
            $lon1 = (float) $node['lon'];
            $lat1 = (float) $node['lat'];
            $isInside = false;
            foreach ($rectangles_rr_cner as $rect) {
                $isInside = GeometryTools::isPointInPolygon([$lon1, $lat1], $rect);
                if ($isInside)
                    break;
            }
            if (! $isInside) {
                add_error($ref, 'match_rr_cner', 'osm!=cner: node: ' . $nodeId . ' point:' . $lon1 . ',' . $lat1);
                $wayMatchCNER = false;
            }
        }
    }

    return [$cnerMatchWays, $wayMatchCNER];
}

/**
 * Retourne le geojson simplifié du tracé RR.
 * Si le geojson original est plus récent que la version simplifiée, celle-ci est recalculée.
 * 
 * @param mixed $ref 
 * @return FeatureCollection 
 * @throws UnserializationException 
 */
function getRrGeojson($ref): FeatureCollection
{
    global $config, $stats, $common;

    $rr_file = $common->rr_filename($ref, false);
    $rr_fileSimplified = $common->rr_filename($ref);

    $toGenerate = false;
    if (! file_exists($rr_fileSimplified)) {
        $toGenerate = true;
    }
    // Check if it's fresher
    else if (filemtime($rr_file) > filemtime($rr_fileSimplified)) {
        $toGenerate = true;
    }

    if ($toGenerate) {
        echo "\t", 'generate simplified geometry...', "\n";

        $json = json_decode(file_get_contents($rr_file));

        $featureCollection = GeoJson::jsonUnserialize($json);
        $featureCollection = GeometryTools::simplifyFeatureCollection($featureCollection, $config['geometry']['simplifier_factor']);

        file_put_contents($rr_fileSimplified, json_encode($featureCollection->jsonSerialize()));
    } else {
        $json = json_decode(file_get_contents($common->rr_filename($ref)));
        /** @var \GeoJson\Feature\FeatureCollection $featureCollection */
        $featureCollection = GeoJson::jsonUnserialize($json);
    }
    return $featureCollection;
}

/**
 * @return void 
 */
function process_old_axes()
{
    global $axesActuals, $common, $config, $historyFile, $stats;

    // column "axe_old" is not enough to find obsolete refs, so download all roads ref.
    $specialRef = 'all_highways_refs';
    $osm_file = $common->osm_filename($specialRef);
    if (! $historyFile->needUpdate($specialRef, $stats['start_at']) &&  file_exists($osm_file)) {
        echo Ansi::BACKGROUND_BLACK, Ansi::YELLOW, 'Skip obsolete axes check.', Ansi::CLOSE, Ansi::EOL;
        return;
    }

    echo Ansi::BACKGROUND_BLACK, Ansi::YELLOW, 'Obsolete axes:', Ansi::CLOSE, Ansi::EOL;
    $result = $common->download_osm('all_highways_refs', '
            [out:xml] [timeout:30];
            area[admin_level=2]["wikidata"="Q1028"]->.country;
            (
                rel["type"="route"]["route"="road"]["ref"~"(^|;)(R)?(N|P|R)[0-9]+($|;)"](area.country);
                way["highway"]["ref"~"(^|;)(R)?(N|P|R)[0-9]+($|;)"](area.country);
            );
            out tags;
            ');
    $historyFile->update($specialRef, false);

    $xml = new SimpleXMLElement(file_get_contents($osm_file));
    if ($xml->getName() != 'osm') {
        die('not an OSM file' . "\n");
    }

    $axesObsoletes = [];
    foreach ($xml->way as $way) {
        $wayRefs = Common::osm_object_get_tag($way, 'ref');
        foreach (explode(';', $wayRefs) as $wayRef) {
            // remove 'R' prefix
            if (! preg_match('/^(?:R)?([NPR]\d+)$/', $wayRef, $m)) {
                //die('process_old_axes() -> Ref dont match: "' . $wayRef . '"');
                echo Ansi::TAB, Ansi::BACKGROUND_BLUE, Ansi::WHITE, 'skip ref ', $wayRef, Ansi::CLOSE, Ansi::EOL;
                continue;
            }
            $axesObsoletes[$m[1]] = true;
        }
    }
    foreach ($xml->relation as $rel) {
        $relRefs = Common::osm_object_get_tag($rel, 'ref');
        foreach (explode(';', $relRefs) as $relRef) {
            // remove 'R' prefix
            if (! preg_match('/^(?:R)?([NPR]\d+)$/', $relRef, $m)) {
                //die('process_old_axes() -> Ref dont match: "' . $wayRef . '"');
                echo Ansi::TAB, Ansi::BACKGROUND_BLUE, Ansi::WHITE, 'skip ref ', $relRef, Ansi::CLOSE, Ansi::EOL;
                continue;
            }
            $axesObsoletes[$m[1]] = true;
        }
    }
    if (empty($axesObsoletes)) {
        echo Ansi::BACKGROUND_BLACK, Ansi::GREEN, 'No obsolete axe found.', Ansi::CLOSE, Ansi::EOL;
        return;
    }

    foreach ($axesObsoletes as $old_ref => $v) {
        if (isset($axesActuals[$old_ref]))
            continue;

        echo Ansi::TAB, Ansi::BACKGROUND_RED, Ansi::WHITE, $old_ref, ' still exists', Ansi::CLOSE, Ansi::EOL;
        $stats['errors_still_exists_count']++;
        $stats['old_axes'][$old_ref] = [
            'start_at' => time(),
            'deleted' => false,
            'download' => null,
        ];
        $historyFile->update($old_ref, true);
    }

    ksort($stats['old_axes']);
}
