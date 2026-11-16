// ContentSearch プラグイン
//
// OnDocFormSave / OnDocPublished:
//   管理画面で記事を保存・公開したとき、その記事を索引し直す。
//   予約公開（type=scheduled）は訪問者のリクエスト中に起きるので、そこではサイトへの取得をしない
//   （予約公開の記事は保存した時点で索引に入っており、公開されると検索結果に出る）。
//
// OnLoadWebDocument:
//   索引作成がページ本文だけを取得するとき、テンプレートを使わずに [*content*] を返す。
//   ?template=blank を付けただけで誰でも本文だけを取れると、テンプレート側のアクセス制御を飛ばせてしまうので、
//   索引作成時に発行したトークン（X-ContentSearch-Token ヘッダー）が一致するときだけ有効にする。

$csEvent = event()->name;

if ($csEvent === 'OnDocFormSave' || $csEvent === 'OnDocPublished') {
    if (!evo()->isLoggedIn('mgr')) {
        return;
    }
    $csParams = event()->params;
    if ($csEvent === 'OnDocPublished' && (isset($csParams['type']) ? $csParams['type'] : '') !== 'manual') {
        return;
    }
    $csId = 0;
    if (isset($csParams['id'])) {
        $csId = (int) $csParams['id'];
    } elseif (isset($csParams['docid']) && !is_array($csParams['docid'])) {
        $csId = (int) $csParams['docid'];
    }
    // 下の行のパスは、プラグインを作成・更新するときに ContentSearch/core の場所に置き換わる
    $csCore = '[+cs_core_path+]';
    if (!$csId || !is_file($csCore . '/save-index.class.php')) {
        return;
    }
    include_once $csCore . '/save-index.class.php';
    $csIndex = new csIndex(csIndex::savedOptions());
    $csIndex->saveDocument($csId);
    return;
}

if ($csEvent !== 'OnLoadWebDocument' || getv('template') !== 'blank') {
    return;
}

// ヘッダーは「ID.トークン」。ID は索引作成ごとに別で、設定 cs_index_token_<ID> に対応する
$csHeader = isset($_SERVER['HTTP_X_CONTENTSEARCH_TOKEN']) ? (string) $_SERVER['HTTP_X_CONTENTSEARCH_TOKEN'] : '';
if (!preg_match('/^([0-9a-f]{16})\.([0-9a-f]{64})$/', $csHeader, $csMatch)) {
    return;
}
list(, $csTokenId, $csToken) = $csMatch;

// システム設定のキャッシュを通さず、DB の最新値を読む（値は「トークンの SHA-256:有効期限の UNIX 時刻」）
$csStored = (string) db()->getValue(
    db()->select(
        'setting_value',
        db()->getFullTableName('system_settings'),
        sprintf("setting_name='cs_index_token_%s'", $csTokenId)
    )
);
if (strpos($csStored, ':') === false) {
    return;
}
list($csHash, $csExpires) = explode(':', $csStored, 2);
if ((int) $csExpires < time() || !hash_equals($csHash, hash('sha256', $csToken))) {
    return;
}

$modx->documentContent = '[*content*]';
// テンプレートなしのページをキャッシュに残さない（残すと、トークンなしの ?template=blank にキャッシュから返ってしまう）
$modx->documentObject['cacheable'] = 0;
