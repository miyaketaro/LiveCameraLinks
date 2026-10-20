<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$snapshotPath = __DIR__ . '/output/block-kanto-tourism-latest.json';
$masterPath = $root . '/data/master/municipalities.json';

function readJson(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "File not found: {$path}\n");
        exit(1);
    }
    $value = json_decode((string)file_get_contents($path), true);
    if (!is_array($value)) {
        fwrite(STDERR, "Invalid JSON: {$path}\n");
        exit(1);
    }
    return $value;
}

function prefectureList(array $master): array
{
    $items = [];
    foreach ((array)($master['municipalities'] ?? []) as $municipality) {
        $name = (string)($municipality['prefecture_name'] ?? '');
        if ($name !== '') {
            $items[$name] = true;
        }
    }
    return array_keys($items);
}

function detectPrefecture(string $title, string $description, string $channel, array $prefectures): string
{
    foreach ([$title, $description, $channel] as $text) {
        foreach ($prefectures as $prefecture) {
            if (mb_strpos($text, $prefecture, 0, 'UTF-8') !== false) {
                return $prefecture;
            }
        }
    }
    return '';
}

function inferMunicipality(array $row, array $municipalities): ?array {
    if ($municipalities === []) return null;
    $text=(string)($row['title']??'').' '.(string)($row['description']??'').' '.(string)($row['channel_title']??'');
    $prefNames=array_values(array_unique(array_filter(array_map(static fn($m)=>(string)($m['prefecture_name']??''),$municipalities))));
    $explicitPref=detectPrefecture((string)($row['title']??''),(string)($row['description']??''),(string)($row['channel_title']??''),$prefNames);
    $exact=[];$shortMap=[];
    foreach($municipalities as $m){
        if(!is_array($m))continue;
        $code=(string)($m['municipality_code']??'');$pref=(string)($m['prefecture_name']??'');
        if($code===''||$pref==='')continue;
        $tokens=[(string)($m['official_name']??''),(string)($m['full_name']??'')];
        foreach((array)($m['aliases']??[]) as $alias)if(is_scalar($alias))$tokens[]=(string)$alias;
        foreach(array_unique(array_filter($tokens,static fn(string $t):bool=>$t!=='')) as $token){
            if(mb_strpos($text,$token,0,'UTF-8')!==false)$exact[$code]=$m;
        }
        if(in_array((string)($m['type']??''),['city','town','village'],true)){
            $short = preg_replace('/(\x{5E02}|\x{753A}|\x{6751})$/u', '', (string)($m['official_name'] ?? ''));
            if(is_string($short)&&mb_strlen($short,'UTF-8')>=2&&mb_strpos($text,$short,0,'UTF-8')!==false){
                $shortMap[$short][$code]=$m;
            }
        }
    }
    if ($explicitPref !== '') {
        $prefExact = array_filter(
            $exact,
            static fn(array $m): bool =>
                (string)($m['prefecture_name'] ?? '') === $explicitPref
        );
        if (count($prefExact) === 1) {
            return [
                'municipality' => array_values($prefExact)[0],
                'match_type' => 'exact',
            ];
        }
        if (count($prefExact) > 1) {
            return null;
        }
        // prefExact=0: continue to explicit-prefecture short_context evaluation.
    } elseif (count($exact) === 1) {
        return [
            'municipality' => array_values($exact)[0],
            'match_type' => 'exact',
        ];
    } elseif (count($exact) > 1) {
        return null;
    }
    $contextPattern='(?:\x{99c5}|\x{5e02}\x{5f79}\x{6240}|\x{753a}\x{5f79}\x{5834}|\x{6751}\x{5f79}\x{5834}|\x{6c34}\x{65cf}\x{9928}|\x{30bf}\x{30ef}\x{30fc}|\x{5c55}\x{671b}\x{53f0}|\x{516c}\x{5712}|\x{795e}\x{793e}|\x{5bfa}|\x{6e2f}|\x{7a7a}\x{6e2f})';
    $shortCandidates = [];
    foreach ($shortMap as $short => $matches) {
        if (!preg_match('/'.preg_quote($short, '/').$contextPattern.'/u', $text)) {
            continue;
        }
        foreach ($matches as $code => $municipality) {
            if ($explicitPref !== '' && (string)($municipality['prefecture_name'] ?? '') !== $explicitPref) {
                continue;
            }
            $shortCandidates[$code] = [
                'municipality' => $municipality,
                'length' => mb_strlen($short, 'UTF-8'),
            ];
        }
    }
    if ($shortCandidates === []) {
        return null;
    }
    $maxLength = max(array_column($shortCandidates, 'length'));
    $longest = array_filter(
        $shortCandidates,
        static fn(array $candidate): bool => $candidate['length'] === $maxLength
    );
    if (count($longest) !== 1) {
        return null;
    }
    return [
        'municipality' => array_values($longest)[0]['municipality'],
        'match_type' => 'short_context',
    ];
}

$snapshot = readJson($snapshotPath);
$master = readJson($masterPath);
$municipalities = (array)($master['municipalities'] ?? []);
$prefectures = prefectureList($master);
$counts = ['一致' => 0, '地域外' => 0, '未判定' => 0];
$improved = 0;
$defs = blockDefinitions();

foreach ($snapshot as $row) {
    if (!is_array($row)) {
        continue;
    }
    $old = (string)($row['location_status'] ?? '未判定');
    $detected = detectPrefecture((string)($row['title'] ?? ''), (string)($row['description'] ?? ''), (string)($row['channel_title'] ?? ''), $prefectures);
    $municipalityCode = '';
    $municipalityName = '';
    $municipalityMatchType = '';
    if ($detected === '') {
        $municipalityMatch = inferMunicipality($row, $municipalities);
        if ($municipalityMatch !== null) {
            $municipality = $municipalityMatch['municipality'];
            $detected = (string)$municipality['prefecture_name'];
            $municipalityCode = (string)$municipality['municipality_code'];
            $municipalityName = (string)$municipality['official_name'];
            $municipalityMatchType = (string)$municipalityMatch['match_type'];
        }
    }
    $searched = (array)($row['searched_prefectures'] ?? []);
    $searchBlocks = array_values(array_filter(array_map('strval', (array)($row['search_blocks'] ?? []))));
    $searchBlock = (string)($row['search_block'] ?? '');
    if ($searchBlock !== '') {
        $searchBlocks[] = $searchBlock;
    }
    $searchBlocks = array_values(array_unique(array_filter($searchBlocks)));
    $detectedBlock = blockForPrefecture($detected, $defs);
    if ($detected === '' || $searchBlocks === []) {
        $new = "\u{672A}\u{5224}\u{5B9A}";
    } elseif (in_array($detectedBlock, $searchBlocks, true)) {
        $new = "\u{4E00}\u{81F4}";
    } else {
        $new = "\u{5730}\u{57DF}\u{5916}";
    }
    $counts[$new]++;
    if ($old === '未判定' && $new === '一致') {
        $improved++;
    }
    echo json_encode([
        'title' => (string)($row['title'] ?? ''),
        'searched_prefectures' => $searched,
        'old_location_status' => $old,
        'new_location_status' => $new,
        'detected_prefecture' => $detected,
        'detected_municipality_code' => $municipalityCode,
        'detected_municipality_name' => $municipalityName,
        'municipality_match_type' => $municipalityMatchType,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}


$synthetic = ['title' => '江戸川区ライブカメラ', 'description' => '', 'channel_title' => '', 'searched_prefectures' => ['東京都']];
$syntheticMatch = inferMunicipality($synthetic, $municipalities);
echo json_encode(['synthetic_case' => '江戸川区明示', 'location_status' => $syntheticMatch !== null ? '一致' : '未判定', 'detected_municipality_code' => $syntheticMatch !== null ? (string)$syntheticMatch['municipality']['municipality_code'] : '', 'detected_municipality_name' => $syntheticMatch !== null ? (string)$syntheticMatch['municipality']['official_name'] : '', 'municipality_match_type' => $syntheticMatch !== null ? (string)$syntheticMatch['match_type'] : ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

function blockDefinitions(): array { return ['hokkaido'=>['name'=>'北海道','prefs'=>['北海道']],'tohoku'=>['name'=>'東北','prefs'=>['青森県','岩手県','宮城県','秋田県','山形県','福島県']],'kanto'=>['name'=>'関東','prefs'=>['茨城県','栃木県','群馬県','埼玉県','千葉県','東京都','神奈川県']],'chubu'=>['name'=>'中部','prefs'=>['新潟県','富山県','石川県','福井県','山梨県','長野県','岐阜県','静岡県','愛知県']],'kinki'=>['name'=>'近畿','prefs'=>['三重県','滋賀県','京都府','大阪府','兵庫県','奈良県','和歌山県']],'chugoku'=>['name'=>'中国','prefs'=>['鳥取県','島根県','岡山県','広島県','山口県']],'shikoku'=>['name'=>'四国','prefs'=>['徳島県','香川県','愛媛県','高知県']],'kyushu'=>['name'=>'九州','prefs'=>['福岡県','佐賀県','長崎県','熊本県','大分県','宮崎県','鹿児島県']],'okinawa'=>['name'=>'沖縄','prefs'=>['沖縄県']]]; }
function blockForPrefecture(string $pref,array $defs): string { foreach($defs as $def) if(in_array($pref,$def['prefs'],true)) return $def['name']; return ''; }
$defs=blockDefinitions();
$regressionCases = [
    ['name'=>'奥多摩駅','title'=>'奥多摩駅ライブカメラ','search_block'=>'関東','searched_prefectures'=>['東京都'],'expected_prefecture'=>'東京都','expected_name'=>'奥多摩町','expected_status'=>'一致'],
    ['name'=>'鳥羽水族館','title'=>'鳥羽水族館ライブカメラ','search_block'=>'関東','searched_prefectures'=>['東京都'],'expected_prefecture'=>'三重県','expected_name'=>'鳥羽市','expected_status'=>'地域外'],
    ['name'=>'京都タワー','title'=>'京都タワーライブカメラ','search_block'=>'関東','searched_prefectures'=>['東京都'],'expected_prefecture'=>'京都府','expected_name'=>'京都市','expected_status'=>'地域外'],
    ['name'=>'平塚沖','title'=>'神奈川県平塚市 平塚沖ライブカメラ','search_block'=>'関東','searched_prefectures'=>['東京都'],'expected_prefecture'=>'神奈川県','expected_name'=>'平塚市','expected_status'=>'一致'],
    ['name'=>'横浜汽車道（曖昧）','title'=>'横浜汽車道ライブカメラ','search_block'=>'関東','searched_prefectures'=>['神奈川県'],'expected_prefecture'=>'','expected_name'=>'','expected_status'=>'未判定'],
    ['name'=>'神奈川県横浜市','title'=>'神奈川県横浜市 横浜汽車道ライブカメラ','search_block'=>'関東','searched_prefectures'=>['神奈川県'],'expected_prefecture'=>'神奈川県','expected_name'=>'横浜市','expected_status'=>'一致'],
    ['name'=>'江戸川ライブカメラ','title'=>'江戸川ライブカメラ365','search_block'=>'関東','searched_prefectures'=>['東京都'],'expected_prefecture'=>'','expected_name'=>'','expected_status'=>'未判定'],
    ['name'=>'江戸川区ライブカメラ','title'=>'江戸川区ライブカメラ','search_block'=>'関東','searched_prefectures'=>['東京都'],'expected_prefecture'=>'東京都','expected_name'=>'江戸川区','expected_status'=>'一致'],
    ['name'=>'東京ディズニーシー','title'=>'東京ディズニーシーライブ映像','search_block'=>'関東','searched_prefectures'=>['東京都'],'expected_prefecture'=>'','expected_name'=>'','expected_status'=>'未判定'],
];
foreach ($regressionCases as $case) {
    $row = [
        'title' => $case['title'],
        'description' => '',
        'channel_title' => '',
        'searched_prefectures' => $case['searched_prefectures'],
    ];
    $detected = detectPrefecture($case['title'], '', '', $prefectures);
    $municipalityCode = '';
    $municipalityName = '';
    $municipalityMatchType = '';
    $municipalityMatch = inferMunicipality($row, $municipalities);
    if ($municipalityMatch !== null) {
        $municipality = $municipalityMatch['municipality'];
        $municipalityPrefecture = (string)($municipality['prefecture_name'] ?? '');
        if ($detected === '' || $detected === $municipalityPrefecture) {
            if ($detected === '') {
                $detected = $municipalityPrefecture;
            }
            $municipalityCode = (string)($municipality['municipality_code'] ?? '');
            $municipalityName = (string)($municipality['official_name'] ?? '');
            $municipalityMatchType = (string)($municipalityMatch['match_type'] ?? '');
        }
    }
    $detectedBlock = blockForPrefecture($detected, $defs);
    if ($detected === '') {
        $status = "\u{672A}\u{5224}\u{5B9A}";
    } elseif ($detectedBlock === $case['search_block']) {
        $status = "\u{4E00}\u{81F4}";
    } else {
        $status = "\u{5730}\u{57DF}\u{5916}";
    }
    $pass = $detected === $case['expected_prefecture']
        && $municipalityName === $case['expected_name']
        && $status === $case['expected_status'];
    echo json_encode([
        'regression_case' => $case['name'],
        'detected_prefecture' => $detected,
        'detected_municipality_code' => $municipalityCode,
        'detected_municipality_name' => $municipalityName,
        'municipality_match_type' => $municipalityMatchType,
        'location_status' => $status,
        'result' => $pass ? 'PASS' : 'FAIL',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
echo json_encode([
    'summary' => $counts,
    'improved_count' => $improved,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
