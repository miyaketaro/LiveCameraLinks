<?php
return [
    // Copy this file to config.php and paste your YouTube Data API v3 key.
    'youtube_api_key' => 'PASTE_YOUR_API_KEY_HERE',

    // Number of results returned per search (1-50).
    'max_results_per_query' => 25,

    // Default search queries for the first test run.
    'queries' => [
        '大阪 ライブカメラ',
        '京都 ライブカメラ',
        '道路 ライブカメラ',
        '駅 ライブカメラ',
        '雪道 ライブカメラ',
    ],

    // Japan as the viewer region. This does NOT guarantee the camera is physically in Japan.
    'region_code' => 'JP',
    'relevance_language' => 'ja',
];
