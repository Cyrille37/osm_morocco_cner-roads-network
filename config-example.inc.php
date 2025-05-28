<?php

$config = [
    'debug' => true,

    //'cacheFolder' => __DIR__ . '/cache',
    'cacheFolder' => 'cache',

    // Don't cache osm download. Default to false.
    'download_force' => false ,

    // osm download cache file is deleted on error. Default to true.
    'cacheDeleteOnError' => true,

    // cache computed rects. Defaut to false.
    'cacheRects' => false,

    'history' => [
        'file' => 'cache/history.json',
        'check_ok_ttl' => 60 * 60 * 48,
    ],

    //'splitsFolder' => __DIR__ . '/output',
    'splitsFolder' => 'split',

    'axes_csv' => [
        'file' => 'split/axes.csv',
        'columns' => [
            'axe' => 0,
            'axe_old' => 1,
            'category' => 2,
            'etatPaved' => 3,
            'etatUnpaved' => 4,
            'etatUnknow' => 5
        ]
    ],

    'analyze_results' => [
        'file' => './analyze.json',
        'save_freq' => 0 ,
    ],

    'overpass' => [
        'sleep' => 20,
        'instances' => [
            'https://overpass.private.coffee/api/interpreter',    
            'https://overpass-api.de/api/interpreter',
        ],
    ],

    'geometry' => [
        'simplifier_factor' => 0.000025,
        'bouding-box' => [
            // Faire large à cause des rond-points et fin de tracés en V.
            'padding-x' => 40,
            'padding-y' => 60,
        ],
    ],

    'errors' => [
        'keys' => [
            // Les erreurs utilisées par osm_check
            'mismatch_ref',
            'relation_error', //'missing_relation','too_many_relations',
            'missing_continuity',
            'missing_surface',
            'ways_not_in_relation',
            'match_rr_cner',
            'way_tags_error'
        ],
        'ignore_types' => [
            'missing_surface' => true,
            'match_rr_cner' => [
                // grands tronçons non visibles sur le terrain.
                'P3527','P4035','P4223','P4254', 
            ],
            'missing_continuity' => [
                // Autres sections en "proposed".
                'P4223'
            ],
        ],
    ],

    /*
    'process_only' => [
        'P2115','P3034','P3305','P4268','P4307','P4528',
    ],
    */
];
