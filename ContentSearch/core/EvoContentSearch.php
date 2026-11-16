<?php

class EvoContentSearch
{
    private $keyword;
    private $config = [];
    private $saveIndex;
    private $limit;
    private $offset = 0;
    private $maxLimit = 100;
    private $results;
    private $rows;
    private $total;
    private $time;
    private $mode;
    private $current_base_url;
    private $form;
    private $orderby;
    private $paginate;
    private $query;

    public function __construct() {
        // 設定を先に読む（csIndex とキーワードの取得は設定を使う）
        $this->config = $this->loadConfig();

        include_once __DIR__ . '/save-index.class.php';
        include_once __DIR__ . '/SearchQuery.class.php';
        include_once __DIR__ . '/Paginator.class.php';
        include_once __DIR__ . '/Summary.class.php';
        $this->saveIndex = new csIndex($this->config);

        $this->keyword = $this->treatedKeyword(getv($this->keywordParam()));
        $this->current_base_url = serverv('query_string')
            ? strstr(serverv('request_uri'), '?', true)
            : serverv('request_uri')
        ;
    }

    /**
     * キーワードを受け取るクエリパラメータ名（設定の keyword。未設定なら keyword）
     */
    private function keywordParam() {
        $param = $this->config('keyword');
        if (!is_string($param) || trim($param) === '') {
            return 'keyword';
        }
        return trim($param);
    }

    private function loadConfig() {
        $defaultConfig = include $this->themePath() . '/_default/config.php';
        if(!event()->params) {
            return $defaultConfig;
        }

        $theme = isset(event()->params['theme']) ? event()->params['theme'] : '';
        $themeConfigPath = $this->themePath() . $theme . '/config.php';
        if(!$theme || !is_file($themeConfigPath)) {
            return $this->mergeRecursive($defaultConfig, event()->params);
        }

        $themeConfig = include $themeConfigPath;
        if (!$themeConfig) {
            return $this->mergeRecursive($defaultConfig, event()->params);
        }
        foreach (['css', 'tplForm', 'tplResults', 'tplPaginate'] as $k) {
            if (!isset($themeConfig[$k])) {
                $themeConfig[$k] = null;
            }
        }

        return $this->mergeRecursive(
            $this->mergeRecursive($defaultConfig, $themeConfig)
            , event()->params
        );
    }

    public function run() {
        // 索引の作成・更新は管理者のアクセスのときだけ行う。訪問者のリクエストでサイト自身へページを取りに行くと、
        // その訪問者の表示が遅くなるうえ、Web サーバーの構成によっては取得先を外部に向けられてしまう。
        // 普段の更新は、管理画面で記事を保存・公開したときにプラグインが行う（ここは取りこぼしの補完）
        if(!evo()->isLoggedIn('manager')) {
            return;
        }
        // プラグインはスニペットの設定を見られないので、索引に関わる設定を保存しておく。
        // basicAuth はスニペットのパラメータやテーマの設定ファイルと同じ平文で、システム設定 cs_index_options に入る
        $this->saveIndex->saveOptions([
            'additionalKeywordField' => (string) $this->config('additionalKeywordField', ''),
            'basicAuth' => (string) $this->config('basicAuth', '')
        ]);

        $tableExists = db()->tableExists(db()->config['table_prefix'] . 'search_content');
        // プラグインのコードは作成時に DB へ写されるので、ファイルを更新しても既存サイトには反映されない。
        // 管理者が検索ページを開いたときに最新にしておく（索引に更新が無くても、ここで反映される）
        if($tableExists && $this->saveIndex->syncPlugin()) {
            evo()->clearCache('full');
        }
        if(getv('reset') || !$tableExists) {
            // 再構築はテーブルを作り直して全ページを取り直す重い処理。
            // 他サイトに仕込んだリンクで管理者に実行させられないよう（CSRF）、管理者のセッションに結びついたトークンを求める
            if(evo()->isLoggedIn('manager') && (!$tableExists || $this->isValidResetToken(getv('token')))) {
                $this->firstRun();
            }
            return;
        }
        $recent_update = evo()->getConfig('recent_update', globalv('recent_update'));
        $cs_recent_update = evo()->getConfig('cs_recent_update',0);

        if($cs_recent_update >= $recent_update && !$this->recentUpdate($cs_recent_update)) {
            return;
        }

        $this->saveIndex->saveAll(
            $cs_recent_update,
            ['basicAuth' => $this->config('basicAuth')]
        );
        $this->saveIndex->saveSystemSetting(
            'cs_recent_update',
            serverv('request_time') + evo()->getConfig('server_offset_time', 0)
        );
        evo()->clearCache('full');
    }

    private function firstRun() {
        // プラグインの作成・更新は saveAll() の中で行う
        $this->saveIndex->createTable();
        $this->saveIndex->saveAll(null, ['basicAuth' => $this->config('basicAuth')]);
        $this->saveIndex->saveSystemSetting(
            'cs_recent_update',
            serverv('request_time')  + evo()->getConfig('server_offset_time', 0)
        );
        evo()->clearCache('full');
    }

    private function recentUpdate($cs_recent_update) {
        return db()->getRecordCount(
            db()->select(
                'id',
                db()->getFullTableName('site_content'), [
                    'published=1 and deleted=0 and searchable=1 and privateweb=0',
                    'AND editedon > ' . db()->escape($cs_recent_update)
                ]
            )
        );
    }
    public function config($key, $default=null) {
        if (array_get($this->config, $key)===null) {
            return $default;
        }
        $str = array_get($this->config, $key);
        if (stripos($str, '@CHUNK') === 0) {
            return evo()->getChunk(substr($str, 7));
        }
        return $str;
    }
    public function csBasePath() {
        return dirname(__DIR__) . '/';
    }
    public function themePath() {
        return $this->csBasePath() . 'themes/';
    }

    public function setProps() {
        $this->orderby = getv('orderby','rel');
        $this->limit = $this->sanitizeLimit(getv('limit'));
        $this->offset = $this->sanitizeOffset(getv('offset'));
        $this->form = evo()->parseText(
            $this->config('tplForm.wrap'), [
                'keyword' => $this->keyword,
                'keyword-param' => hsc($this->keywordParam()),
                'orderby.rel.checked'  => $this->orderby==='rel' ? 'checked' : '',
                'orderby.date.checked' => $this->orderby==='date' ? 'checked' : '',
                'limit' => $this->limit,
                'offset' => $this->offset,
                'admin-widget' => $this->adminWidgetMessage()
            ]
        );

        if($this->config('css')) {
            evo()->regClientCSS(
                sprintf('<style>%s</style>', $this->config('css'))
            );
        }

        if(!$this->keyword) {
            return;
        }

        $this->query = new csSearchQuery($this->orderby, $this->limit, $this->offset);
        $mode = getv('mode') ?: $this->mode($this->keyword);
        $total = $this->query->total($this->keyword, $mode);
        if(!$total && $mode === 'fulltext') {
            $total = $this->query->total($this->keyword, 'like');
            if(!$total) {
                return;
            }
            $mode = 'like';
        }
        $this->mode = $mode;

        $this->total = $total;

        $bt = microtime(true);
        $this->rows = $this->keyword
            ? $this->find($this->keyword)
            : null
        ;

        if(!$this->rows) {
            return;
        }

        $this->time = round(microtime(true)-$bt, 5);
        $results = [];
        foreach ($this->rows as $row) {
            $results[] = evo()->parseText(
                $this->config('tplResults.result'),
                $row
            );
        }
        $this->results = implode("\n", $results);
        $this->paginate = $this->total
            ? $this->paginator()->build($this->total, $this->limit, $this->offset)
            : null
        ;
        $placeholders = [
            'results'  => $this->results,
            'time'     => $this->time,
            'total'    => $this->total,
            'keyword'  => $this->keyword,
            'form'     => $this->form,
            'orderby'  => $this->orderby,
            'paginate' => $this->paginate
        ];
        $placeholderKey = $this->config('placeholder-key');
        evo()->setPlaceholder($placeholderKey, $placeholders);
        // 以前の既定値は綴りを誤った ContentSeach だった。既存サイトのテンプレートが動くよう、旧名にも入れる
        if ($placeholderKey === 'ContentSearch') {
            evo()->setPlaceholder('ContentSeach', $placeholders);
        }
    }

    private function adminWidgetMessage() {
        if (!evo()->isLoggedIn('manager')) {
            return '';
        }
        return evo()->parseText(
            $this->config('tplForm.admin-widget'), [
                'url' => hsc($this->current_base_url),
                'token' => $this->resetToken()
            ]
        );
    }

    /**
     * インデックス再構築（?reset=）用のトークン。セッション ID から作るので保存は不要
     */
    private function resetToken() {
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
            return '';
        }
        return hash_hmac('sha256', 'ContentSearch-reset', session_id());
    }

    private function isValidResetToken($token) {
        $expected = $this->resetToken();
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * 表示用の値を返す（bootstrap.php が使う）。読めるのは下の一覧にあるものだけ
     * （以前はどの private プロパティでも読み書きできたが、外から変えてよい値は無い）
     */
    public function get($key, $default=null) {
        if (!in_array($key, ['keyword', 'form', 'total', 'time', 'results', 'paginate'], true)) {
            return $default;
        }
        if($this->$key === null) {
            return $default;
        }
        return $this->$key;
    }

    private function find($keyword) {
        $rs = $this->query->find($keyword, $this->mode);
        $summary = new csSummary(
            $this->config('summaryLength'),
            $this->config('tplResults.highlight'),
            strpos(evo()->getConfig('manager_language'), 'japanese') === 0
        );
        $result = [];
        while($row = db()->getRow($rs)) {
            $row['summary'] = $summary->make($row['plain_text'], $keyword);
            $row['id'] = $row['doc_id'];
            $result[] = $row;
        }
        return $result;
    }

    private function paginator() {
        return new csPaginator(
            function ($name) {
                return $this->config('tplPaginate.' . $name);
            },
            (bool) $this->config('paginateAlwaysShow'),
            $this->current_base_url,
            $_GET
        );
    }

    private function mode($keyword) {
        if($this->config('mode')!=='auto') {
            return $this->config('mode');
        }
        $min_token_size = evo()->getConfig('innodb_ft_min_token_size');
        if(!$min_token_size) {
            $min_token_size = $this->saveIndex->tokenMinSize();
            $this->saveIndex->saveSystemSetting('innodb_ft_min_token_size',$min_token_size);
            evo()->clearCache('full');
        }
        // 全文検索の索引には最小トークン長より短い語が載らないので、
        // 語のどれか1つでも短ければ（「本文」「検索」など2文字の語）最初から LIKE で探す
        foreach (csSearchQuery::terms($keyword) as $term) {
            if(mb_strlen($term) < $min_token_size) {
                return 'like';
            }
        }
        return 'fulltext';
    }

    /**
     * リクエストの limit を 1 以上の整数にする（SQL の LIMIT にそのまま入るため）
     * 上限は maxLimit（設定の limit がそれより大きければ設定値）
     */
    private function sanitizeLimit($value) {
        $default = (int) $this->config('limit', 10);
        if ($default < 1) {
            $default = 10;
        }
        if (!is_string($value) || !$this->isInt($value) || (int) $value < 1) {
            return $default;
        }
        return min((int) $value, max($this->maxLimit, $default));
    }

    private function isInt($str) {
        return preg_match('@^[0-9]+$@',$str);
    }

    /**
     * リクエストの offset を 0 以上の整数にする（SQL の OFFSET にそのまま入るため）
     */
    private function sanitizeOffset($value) {
        if (!is_string($value) || !$this->isInt($value)) {
            return 0;
        }
        return (int) $value;
    }

    /**
     * 入力されたキーワードを整える。戻り値は HTML エスケープ済み
     * （そのままフォームや結果に出せる。索引の本文もエスケープ済みなので、検索にもこの値を使う）
     */
    public function treatedKeyword($keyword=null) {
        if (!$keyword) {
            return '';
        }
        // 空白は半角スペース1つにまとめ、前後の空白は取り除く
        // （末尾に空白が残ると、単語に分けたときに空の語ができる）
        return hsc(
            trim(
                preg_replace(
                    '@\s+@u',
                    ' ',
                    strip_tags(
                        mb_convert_kana($keyword, 'Kas')
                    )
                )
            )
        );
    }

    // 配列を再帰的に重ねる（PHP の array_merge と違い、入れ子の配列はキーごとに上書きする）
    private function mergeRecursive($a,$b) {
        foreach($b as $k=>$v) {
            if(isset($a[$k]) && is_array($v)) {
                $a[$k] = $this->mergeRecursive($a[$k], $v);
                continue;
            }
            $a[$k] = $v;
        }
        return $a;
    }
}
