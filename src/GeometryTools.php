<?php

declare(strict_types=1);

namespace Cyrille\RrInspect;

use GeoJson\GeoJson;
use GeoJson\Feature\Feature;
use GeoJson\Feature\FeatureCollection;
use GeoJson\Geometry\LineString;

class GeometryTools
{
    public static  function simplifyFeatureCollection(FeatureCollection $featureCollection, $epsilon): FeatureCollection
    {
        $features = [];
        foreach ($featureCollection as $feature) {
            $trace = [];
            $coords = $feature->getGeometry()->getCoordinates();
            $coordsCount = count($coords);
            for ($i = 0; $i < $coordsCount; $i++) {
                $coord = $coords[$i];
                $point = [$coord[0], $coord[1]];
                $trace[] = $point;
            }
            $simplified = self::simplifyLine($trace, $epsilon);
            $features[] = new Feature(new LineString($simplified), $feature->getProperties());
        }
        return new FeatureCollection($features);
    }

    public static function simplifyLine(array &$line, $epsilon): array
    {
        return self::douglasPeucker($line, $epsilon);
    }

    public static function getPerpendicularDistance($pt, $lineStart, $lineEnd)
    {
        if ($lineStart === $lineEnd) {
            return sqrt(pow($pt[0] - $lineStart[0], 2) + pow($pt[1] - $lineStart[1], 2));
        }

        $x0 = $pt[0];
        $y0 = $pt[1];
        $x1 = $lineStart[0];
        $y1 = $lineStart[1];
        $x2 = $lineEnd[0];
        $y2 = $lineEnd[1];

        $numerator = abs(($y2 - $y1) * $x0 - ($x2 - $x1) * $y0 + $x2 * $y1 - $y2 * $x1);
        $denominator = sqrt(pow($y2 - $y1, 2) + pow($x2 - $x1, 2));

        return $numerator / $denominator;
    }

    public static function douglasPeucker($points, $epsilon)
    {
        if (count($points) < 3) return $points;

        $maxDistance = 0;
        $index = 0;

        $start = $points[0];
        $end = $points[count($points) - 1];

        for ($i = 1; $i < count($points) - 1; $i++) {
            $distance = self::getPerpendicularDistance($points[$i], $start, $end);
            if ($distance > $maxDistance) {
                $index = $i;
                $maxDistance = $distance;
            }
        }

        if ($maxDistance > $epsilon) {
            $recResults1 = self::douglasPeucker(array_slice($points, 0, $index + 1), $epsilon);
            $recResults2 = self::douglasPeucker(array_slice($points, $index), $epsilon);
            // merge results without duplicate
            return array_merge(array_slice($recResults1, 0, -1), $recResults2);
        } else {
            return [$start, $end];
        }
    }

    public static function isPointInPolygon($point, $polygon)
    {
        /*$x = $point['lon'];
        $y = $point['lat'];*/
        $x = $point[0];
        $y = $point[1];

        $inside = false;
        $n = count($polygon);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];
            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];

            $intersect = (($yi > $y) != ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi + 1e-10) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    public static function offsetPoint($lat, $lon, $distance, $bearing)
    {
        $R = 6378137; // Rayon de la Terre en mètres
        $dRad = $distance / $R;
        $bearingRad = deg2rad($bearing);
        $latRad = deg2rad($lat);
        $lonRad = deg2rad($lon);

        $newLatRad = asin(sin($latRad) * cos($dRad) + cos($latRad) * sin($dRad) * cos($bearingRad));
        $newLonRad = $lonRad + atan2(
            sin($bearingRad) * sin($dRad) * cos($latRad),
            cos($dRad) - sin($latRad) * sin($newLatRad)
        );

        return [
            /*
        'lat' => rad2deg($newLatRad),
        'lon' => rad2deg($newLonRad)
        */
            rad2deg($newLonRad),
            rad2deg($newLatRad)
        ];
    }

    public static function computeRectangle($lat1, $lon1, $lat2, $lon2, $widthOffsetMeters, $lengthOffsetMeters)
    {
        // 1. Calcul du bearing entre A et B
        $dLon = deg2rad($lon2 - $lon1);
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $y = sin($dLon) * cos($lat2Rad);
        $x = cos($lat1Rad) * sin($lat2Rad) - sin($lat1Rad) * cos($lat2Rad) * cos($dLon);
        $bearing = rad2deg(atan2($y, $x));
        // 2. Étendre A et B dans la direction du bearing
        $extendedA = self::offsetPoint($lat1, $lon1, $lengthOffsetMeters, $bearing - 180); // recule
        $extendedB = self::offsetPoint($lat2, $lon2, $lengthOffsetMeters, $bearing);       // avance
        // 3. Cap perpendiculaire
        $perp1 = $bearing + 90;
        $perp2 = $bearing - 90;
        $halfWidth = $widthOffsetMeters;
        // 4. Créer les 4 coins du polygone
        $A1 = self::offsetPoint($extendedA[1], $extendedA[0], $halfWidth, $perp1);
        $A2 = self::offsetPoint($extendedA[1], $extendedA[0], $halfWidth, $perp2);
        $B1 = self::offsetPoint($extendedB[1], $extendedB[0], $halfWidth, $perp1);
        $B2 = self::offsetPoint($extendedB[1], $extendedB[0], $halfWidth, $perp2);
        return [$A1, $B1, $B2, $A2, $A1]; // Polygone fermé
    }
}
