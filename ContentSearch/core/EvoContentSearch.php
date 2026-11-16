<?php

class EvoContentSearch
{
    private $keyword;
    private $config = [];
    private $saveIndex;
    private $request;
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
    private $likeEscapeChar = '!';

    public function __construct() {
        // 設定を先に読む（csIndex とキーワードの取得は設定を使う）
        $this->config = $this->loadConfig();

        include_once __DIR__ . '/save-index.class.php';
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
            return $this->array_merge($defaultConfig, event()->params);
        }

        $themeConfig = include $themeConfigPath;
        if (!$themeConfig) {
            return $this->array_merge($defaultConfig, event()->params);
        }
        foreach (['css', 'tplForm', 'tplResults', 'tplPaginate'] as $k) {
            if (!isset($themeConfig[$k])) {
                $themeConfig[$k] = null;
            }
        }

        return $this->array_merge(
            $this->array_merge($defaultConfig, $themeConfig)
            , event()->params
        );
    }

    public function run() {
        $tableExists = db()->tableExists(db()->config['table_prefix'] . 'search_content');
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
        if(!$this->saveIndex->hasPlugin()) {
            $this->saveIndex->createPlugin();
        }
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

        $mode = getv('mode') ?: $this->mode($this->keyword);
        $total = $this->total($this->keyword, $mode);
        if(!$total && $mode === 'fulltext') {
            $total = $this->total($this->keyword, 'like');
            if(!$total) {
                return;
            }
            $mode = 'like';
        }
        $this->mode = $mode;

        $this->total = $total;
        $this->request = $_GET;

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
            ? $this->buildPaginate($this->total, $this->limit, $this->offset)
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

    public function set($key, $value) {
        $this->$key = $value;
    }

    public function get($key, $default=null) {
        if($this->$key === null) {
            return $default;
        }
        return $this->$key;
    }

    public function find($keyword) {
        if($this->mode==='fulltext') {
            $rs = $this->fulltextQuery($keyword);
        } else {
            $rs = $this->likeQuery($keyword);
        }
        $result = [];
        while($row = db()->getRow($rs)) {
            $row['summary'] = $this->addHighlight(
                $this->summary(
                    $row['plain_text'],
                    $keyword
                ),
                $keyword
            );
            $row['id'] = $row['doc_id'];
            $result[] = $row;
        }
        return $result;
    }

    private function addHighlight($str, $keyword) {
        // 空の語があると正規表現が空文字に一致し、全文字の間にハイライトが入るので除く
        $keywords = preg_split('/\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY);
        if (!$keywords) {
            return $str;
        }
        foreach ($keywords as $i=>$v) {
            $keywords[$i] = sprintf('(%s)', preg_quote($v, '/'));
        }
        return preg_replace(
            '/('.implode('|', $keywords).')/ui',
            evo()->parseText(
                $this->config('tplResults.highlight'),
                [
                    'keyword' => '$0'
                ]
            ),
            $str
        );
    }

    public function total($keyword, $mode) {
        if($mode==='fulltext') {
            return $this->fetchTotalFulltext($keyword);
        }
        return $this->fetchTotalLike($keyword);
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
        foreach ($this->terms($keyword) as $term) {
            if(mb_strlen($term) < $min_token_size) {
                return 'like';
            }
        }
        return 'fulltext';
    }

    private function fulltextQuery($keyword) {
        $baseScoreExpr = sprintf(
            "MATCH (`tokens`) AGAINST (%s)",
            $this->matchAgainst($keyword)
        );
        $field = [
            sprintf(
                "stext.*,content.*, %s as base_score",
                $baseScoreExpr
            )
        ];
        if($this->orderby==='rel') {
            $field[] = $this->generateRelevanceScore($keyword);
        }

        return db()->select(
            implode(',', $field),
            [
                db()->getFullTableName('search_content') . ' stext',
                'LEFT JOIN ' . db()->getFullTableName('site_content') . ' content ON content.id=`stext`.doc_id'
            ],
            sprintf(
                "MATCH (`tokens`) AGAINST (%s)",
                // "MATCH (tokens) AGAINST ('%s' IN BOOLEAN MODE)",
                $this->matchAgainst($keyword)
            ),
            $this->orderby==='rel' ? 'relevance_score DESC' : 'stext.publishedon DESC',
            $this->limit($this->limit, $this->offset)
        );
    }
    private function matchAgainst($keyword) {
        $_ = preg_split('/\s+/u', trim($keyword));
        if(count($_)==1) {
            // NATURAL LANGUAGE MODE では記号は演算子にならないので、そのまま使える
            return sprintf("'%s*'", db()->escape($keyword));
        }
        // BOOLEAN MODE では + - ~ ( ) < > @ * " が演算子になり、
        // 「テスト -」のような入力が構文エラーになるので、空白に置き換えてから語に分け直す
        $terms = preg_split(
            '/\s+/u',
            trim(preg_replace('/[+\-~()<>@*"]+/u', ' ', $keyword)),
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if(count($terms) < 2) {
            return sprintf("'%s*'", db()->escape($terms ? $terms[0] : trim($keyword)));
        }
        $keywords = [];
        foreach($terms as $v) {
            $keywords[] = '+' . db()->escape($v) . '*';
        }
        return sprintf(
            "'%s' IN BOOLEAN MODE",
            implode(' ', $keywords)
        );
    }

    private function likeQuery($keyword) {
        $keyword = trim($keyword);
        $escapedKeyword = db()->escape($keyword);
        $occurrenceCountExpr = sprintf(
            "(LENGTH(stext.plain_text) - LENGTH(REPLACE(stext.plain_text, '%s', ''))) / LENGTH('%s')",
            $escapedKeyword,
            $escapedKeyword
        );
        $field = [
            sprintf(
                "stext.*,content.*, %s AS cnt, %s AS base_score",
                $occurrenceCountExpr,
                $occurrenceCountExpr
            )
        ];
        if($this->orderby==='rel') {
            $field[] = $this->generateRelevanceScore($keyword);
        }
        return db()->select(
            implode(',', $field), [
                db()->getFullTableName('search_content') . ' `stext`',
                'LEFT JOIN ' . db()->getFullTableName('site_content') . ' content ON content.id=`stext`.doc_id'
            ],
            $this->likeWhere($keyword),
            $this->orderby==='rel' ? 'relevance_score DESC' : 'stext.publishedon DESC',
            $this->limit($this->limit, $this->offset)
        );
    }

    /**
     * 関連度スコアの SQL 式を作る
     *
     * 検索語ごとに、どこに・どれだけ含まれるかを足し合わせる（足し算なので、
     * 1語が見つからなくても全体が 0 にはならない）。FULLTEXT と LIKE のどちらで探しても同じ式を使う。
     *
     *   語ごと: タイトルに含む +10 / 説明に含む +4 / 本文の冒頭300文字に含む +2 / 出現回数 × 0.5（10回まで）
     *   キーワード全体がタイトルと一致 +10、タイトルの先頭と一致 +5
     *   新しさ: 公開直後は 1.5 倍、90 日でほぼ半分のボーナスになる
     *
     * 2〜3文字の語を減点したり、長い記事を減点したりはしない（日本語では2文字の語が普通にあるため）
     *
     * @param string $keyword 検索キーワード
     * @return string SQL式
     */
    private function generateRelevanceScore($keyword) {
        $escapeChar = db()->escape($this->likeEscapeChar);
        $like = function ($column, $term, $prefixOnly = false) use ($escapeChar) {
            return sprintf(
                "%s LIKE '%s%s%%' ESCAPE '%s'",
                $column,
                $prefixOnly ? '' : '%',
                db()->escape($this->escapeLikeWildcard($term)),
                $escapeChar
            );
        };

        $parts = [];
        foreach ($this->terms($keyword) as $term) {
            $escaped = db()->escape($term);
            $parts[] = sprintf(
                "(CASE WHEN %s THEN 10 ELSE 0 END"
                . " + CASE WHEN %s THEN 4 ELSE 0 END"
                . " + CASE WHEN %s THEN 2 ELSE 0 END"
                . " + LEAST(10, (CHAR_LENGTH(COALESCE(stext.plain_text, '')) - CHAR_LENGTH(REPLACE(COALESCE(stext.plain_text, ''), '%s', ''))) / CHAR_LENGTH('%s')) * 0.5)",
                $like('stext.pagetitle', $term),
                $like('content.description', $term),
                $like('SUBSTRING(stext.plain_text, 1, 300)', $term),
                $escaped,
                $escaped
            );
        }
        if (!$parts) {
            return '0 as relevance_score';
        }

        $whole = trim($keyword);
        $titleBonus = sprintf(
            "CASE WHEN stext.pagetitle = '%s' THEN 10 WHEN %s THEN 5 ELSE 0 END",
            db()->escape($whole),
            $like('stext.pagetitle', $whole, true)
        );

        return sprintf(
            "((%s + %s) * (1.0 + 0.5 * EXP(-1.0 * GREATEST(0, UNIX_TIMESTAMP() - stext.publishedon) / 7776000))) as relevance_score",
            implode(' + ', $parts),
            $titleBonus
        );
    }

    /**
     * キーワードを検索語に分ける（空白区切り。重複は除き、多すぎる場合は先頭の 10 語まで）
     */
    private function terms($keyword) {
        $terms = array_values(array_unique(
            preg_split('/\s+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY)
        ));
        return array_slice($terms, 0, 10);
    }

    public function buildPaginate($total,$limit,$offset) {
        if(!$this->config('paginateAlwaysShow') && $total <= $limit) {
            return null;
        }
        return evo()->parseText(
            $this->config('tplPaginate.wrap'),
            [
                'prev' => $this->buildPaginatePrev($limit,$offset),
                'pages' => $this->buildPaginatePages($total,$limit),
                'next' => $this->buildPaginateNext($total,$limit,$offset)
            ]
        );
    }

    private function buildPaginatePrev($limit,$offset) {
        if(!$offset) {
            return $this->config('tplPaginate.no-prev');
        }
        $prevNum = $offset - $limit;
        unset($this->request['offset']);
        return evo()->parseText(
            $this->config('tplPaginate.prev'), [
                'prev-link' => $this->build_url(
                    $this->http_merge_query(
                        $prevNum <= 0
                            ? []
                            : ['offset'=>$prevNum]
                    )
                )
            ]
        );
    }

    // テンプレートの href に入れる URL。パスはリクエストの値そのままなので HTML エスケープする
    private function build_url($query_string) {
        if(!$query_string) {
            return hsc($this->current_base_url);
        }
        return hsc($this->current_base_url . '?' . $query_string);
    }

    public function buildPaginatePages($total,$limit) {
        static $content=null;
        if($content) {
            return $content;
        }
        if(!$this->isInt($total) || !$this->isInt($limit)) {
            return null;
        }
        $c=0;
        $pages = [];
        unset($this->request['offset']);
        $num = !$this->offset ? 1 : round($this->offset/$limit)+1;
        if($num <= 5) {
            $num = 1;
        } elseif(5<$num) {
            $num = $num-4;
        }
        $current_num = ($this->offset/$limit)+1;
        // $this->config('tplPaginate.current-item'),
        while($c<10) {
            if($num==$current_num) {
                $pages[] = evo()->parsetext(
                    $this->config('tplPaginate.current-item'), [
                        'num' => $num
                    ]
                );
            } else {
                $offset = $num==1 ? 0 : $limit * ($num-1);
                $pages[] = evo()->parsetext(
                    $this->config('tplPaginate.linked-item'), [
                        'num' => $num,
                        'url' => $this->build_url(
                            $this->http_merge_query(
                                $offset
                                ? ['offset'=>$offset]
                                : []
                            )
                        )
                    ]
                );
            }
            // このページで最後の結果まで表示しきるなら、次のページは作らない
            if($total <= $limit*$num) {
                break;
            }
            $num++;
            $c++;
        }
        $content = implode("\n", $pages);
        return $content;
    }

    private function isInt($str) {
        return preg_match('@^[0-9]+$@',$str);
    }

    public function buildPaginateNext($total,$limit,$offset) {
        // 今のページで最後の結果まで表示しきるなら「次へ」は出さない
        if($total <= $limit + $offset) {
            return $this->config('tplPaginate.no-next');
        }
        return evo()->parseText(
            $this->config('tplPaginate.next'), [
                'next-link' => $this->build_url(
                    $this->http_merge_query(['offset'=>($offset + $limit)])
                )
            ]
        );
    }

    private function http_merge_query($array=[]) {
        if(!$this->request && !$array) {
            return null;
        }
        return http_build_query($this->array_merge($this->request, $array));
    }

    private function limit($limit, $offset=0) {
        $limit = (int) $limit;
        $offset = (int) $offset;
        if(!$offset) {
            return (string) $limit;
        }
        return $limit . ' OFFSET ' . $offset;
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

    /**
     * リクエストの offset を 0 以上の整数にする（SQL の OFFSET にそのまま入るため）
     */
    private function sanitizeOffset($value) {
        if (!is_string($value) || !$this->isInt($value)) {
            return 0;
        }
        return (int) $value;
    }

    private function fetchTotalFulltext($keyword) {
        return db()->getValue(
            db()->select(
                'count(*) as total',
                db()->getFullTableName('search_content'),
                sprintf(
                    "MATCH (`tokens`) AGAINST (%s)",
                    // "MATCH (tokens) AGAINST ('%s' IN BOOLEAN MODE)",
                    $this->matchAgainst($keyword)
                )
            )
        );
    }
    private function fetchTotalLike($keyword) {
        return db()->getValue(
            db()->select(
                'count(*) as total',
                db()->getFullTableName('search_content'),
                $this->likeWhere($keyword)
            )
        );
    }

    private function likeWhere($keyword) {
        $keyword = $this->escapeLikeWildcard($keyword);
        $escapeChar = db()->escape($this->likeEscapeChar);

        $_ = explode(' ', $keyword);
        if(count($_)==1) {
            return sprintf(
                "plain_text LIKE '%%%s%%' ESCAPE '%s'",
                db()->escape($keyword),
                $escapeChar
            );
        }
        $where = [];
        foreach($_ as $v) {
            $where[] = sprintf(
                "plain_text LIKE '%%%s%%' ESCAPE '%s'",
                db()->escape($v),
                $escapeChar
            );
        }
        return implode(' AND ', $where);
    }

    private function escapeLikeWildcard($keyword) {
        $escapeChar = $this->likeEscapeChar;

        return strtr(
            $keyword,
            [
                $escapeChar => $escapeChar . $escapeChar,
                '%' => $escapeChar . '%',
                '_' => $escapeChar . '_'
            ]
        );
    }

    private function summary($text, $keyword) {
        if(mb_strlen($text) < ($this->config('summaryLength')-30)) {
            return $text;
        }
        if(strpos($keyword, ' ')!==false) {
            return mb_substr($text, 0, $this->config('summaryLength'));
        }
        if(!preg_match('/[亜-熙ぁ-んァ-ヶ]/u', mb_substr($text, 0, $this->config('summaryLength')))) {
            return mb_substr($text, 0, $this->config('summaryLength'));
        }
        $pettern = strpos(evo()->getConfig('manager_language'), 'japanese')===0
            ? '/[\s\n\.\!\?\,。、]+/u'
            : '/[\n\.\!\?]+/u'
        ;
        $_ = preg_split(
            $pettern,
            str_replace(
                '&nbsp;',
                ' ',
                strip_tags(
                    htmlspecialchars_decode($text)
                )
            )
        );
        $summary = [];
        foreach($_ as $i=>$v) {
            if(mb_stripos($v, $keyword)===false) {
                continue;
            }
            if(!$i) {
                $summary[0] = $v;
                continue;
            }
            $prev=$i-1;
            $next=$i+1;
            if(!isset($summary[$prev])) {
                $summary[$prev] = $_[$prev];
            }
            if(!isset($summary[$i])) {
                $summary[$i] = $v;
            }
            if(isset($_[$next])) {
                $summary[$next] = $_[$next];
            }
        }
        $summary = $this->trimSummary($summary);
        return mb_substr(
            ($summary ? implode(' ', $summary) : $text),
            0,
            $this->config('summaryLength')
        ). '...';
    }

    private function trimSummary($summary) {
        // 空の要素を削除
        $summary = array_filter($summary, function($value) {
            return !empty($value);
        });

        // 重複する要素を削除
        $summary = array_unique($summary);

        // 一文字目が「[」の要素を削除
        $summary = array_filter($summary, function($value) {
            return $value[0] !== '[';
        });
        return $summary;
    }

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

    private function array_merge($a,$b) {
        foreach($b as $k=>$v) {
            if(isset($a[$k]) && is_array($v)) {
                $a[$k] = $this->array_merge($a[$k], $v);
                continue;
            }
            $a[$k] = $v;
        }
        return $a;
    }
}
