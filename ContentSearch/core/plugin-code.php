// ContentSearch: OnLoadWebDocument
// インデックス作成（csIndex::saveAll）がページ本文だけを取得するとき、テンプレートを使わずに [*content*] を返す。
// ?template=blank を付けただけで誰でも本文だけを取れると、テンプレート側のアクセス制御を飛ばせてしまうので、
// インデックス作成時に発行したトークン（X-ContentSearch-Token ヘッダー）が一致するときだけ有効にする。

if (getv('template') !== 'blank') {
    return;
}
if (event()->name !== 'OnLoadWebDocument') {
    return;
}

// ヘッダーは「ID.トークン」。ID はインデックス作成ごとに別で、設定 cs_index_token_<ID> に対応する
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
