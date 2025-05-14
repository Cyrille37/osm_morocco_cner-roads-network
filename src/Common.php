<?php

namespace Cyrille\RrInspect;

/**
 * echo EOL, TAB, Ansi::BOLD, '*** Hello world ***', Ansi::CLOSE, EOL, EOL ;
 * @package Cyrille37\OSM\Yapafo\Tools
 */
class Common
{
    public function __construct(public array &$config, public array &$stats) {}

    function osm_filename($ref)
    {
        //global $config, $stats;
        return $this->config['cacheFolder'] . '/' . $ref . '_osm.osm';
    }

    /**
     * Use simplified version if exists or forced.
     * 
     * @param mixed $ref 
     * @param bool $forceSimplified 
     * @return string 
     */
    function rr_filename($ref, $simplified = true)
    {
        //global $config, $stats;
        $rr_file = $this->config['splitsFolder'] . '/' . $ref . '_rr.geojson';
        $rr_fileSimplified = $this->config['cacheFolder'] . '/' . $ref . '_rr_simplified.geojson';
        if ($simplified)
            return $rr_fileSimplified;
        return $rr_file;
    }

    public static function get_overpass_query(string $ref): string
    {
        // must use "out meta" to permits opening in Josm.
        $query = '
            [out:xml] [timeout:30];
            // Maroc "wikidata"="Q1028"
            area[admin_level=2]["wikidata"="Q1028"]->.country;
            (
                rel[ref~"(^|;)(R)?' . $ref . '($|;)"](area.country);
                >>;
                way[ref~"(^|;)(R)?' . $ref . '($|;)"](area.country);
                >;
            );
            out meta;
        ';
        return $query;
    }

    function &download_osm($ref)
    {
        $output_file = $this->osm_filename($ref);

        $result = [
            'file' => null,
            'bytes' => -1,
            'instance' => null,
        ];

        // Select an Overpass instance,
        // and compute if it's time to sleep.
        $instances = $this->config['overpass']['instances'];
        $instances_count = count($instances);
        $url = $instances[$this->stats['download_count'] % $instances_count];
        if ($this->stats['download_count'] > 0 && ($this->stats['download_count'] % ($instances_count + 1) == 0)) {
            echo "\t", 'sleeping ', $this->config['overpass']['sleep'], ' seconds...', "\n";
            sleep($this->config['overpass']['sleep']);
        }
        $result['instance'] = $url;

        $this->stats['download_count']++;

        // must use "out meta" to permits opening in Josm.
        $query = self::get_overpass_query($ref);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'user_agent' => 'Morocco roads network check https://www.openstreetmap.org/user/Cyrille37',
                'header' =>
                'Content-type: application/x-www-form-urlencoded' . "\r\n"
                    . 'Accept: application/json' . "\r\n",
                'content' => $query,
            ],
        ]);

        $retry_max = 3;
        $retry_sleep = 10;
        for ($retry = 1; $retry <= $retry_max; $retry++) {
            try {
                echo "\t", 'download (', $retry, '/', $retry_max, ') from '.$url.' ...', "\n";
                $json = @file_get_contents($url, false, $context);
                if ($json === false) {
                    throw new DownloadException($url, error_get_last(), $http_response_header??null);
                }
                break;
            } catch (DownloadException $ex) {
                echo Ansi::TAB, Ansi::BACKGROUND_RED, Ansi::WHITE, 'Download failed: (', $ex->getCode(),') ', $ex->getMessage(), Ansi::CLOSE, "\n";
                if ($retry == $retry_max) {
                    die('Too many retries, exiting' . "\n");
                }
                echo Ansi::TAB,  'Retrying after sleeping ', $retry_sleep,' seconds...', "\n";
                sleep($retry_sleep);
            }
        }

        // Retourne le nombres d'octets plutôt que le nombre de caractères dans une chaîne. 
        $bytes = strlen($json);
        $this->stats['download_bytes'] += $bytes;
        $result['bytes'] = $bytes;

        file_put_contents($output_file, $json);
        $result['file'] = $output_file;

        return $result;
    }

    public static function osm_object_has_tag(\SimpleXMLElement $osmObj, string $key, ?string $value = null)
    {
        foreach ($osmObj->tag as $tag) {
            if ($tag['k'] == $key) {
                if (! $value)
                    return true;
                if ($tag['v'] == $value)
                    return true;
            }
        }
        return false;
    }

    public static function osm_object_get_tag(\SimpleXMLElement $osmObj, string $key)
    {
        // a loop should be lighter than compiliing and running a xpath query.
        // $res = $way->xpath("tag[@k='ref']");
        foreach ($osmObj->tag as $tag) {
            if ($tag['k'] == $key) {
                return (string) $tag['v'];
            }
        }
        return null;
    }
}
