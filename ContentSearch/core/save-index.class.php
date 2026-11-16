<?php
class csIndex {
    private $pluginName = 'ContentSearch';
    private $version = '1.1.0';
    private $config;
    private $bearer_token;
    // ?template=blank で本文だけを取得するためのトークン（plugin-code.php が照合する）
    private $index_token;
    private $index_token_id;

    public function __construct($config) {
        $this->config = $config;
        if (!evo()->getConfig('site_status')) {
            $this->bearer_token = bin2hex(random_bytes(64));
        }
    }

    private function config($key, $default=null) {
        return array_get($this->config, $key, $default);
    }

    public function saveAll($cs_recent_update=null, $options=[]) {
        ini_set('max_execution_time', 600);
        ini_set('default_socket_timeout',600);

        $where = [
            'published=1 and deleted=0 and searchable=1 and privateweb=0'
        ];

        if(!$cs_recent_update) {
            $this->createTable();
        } else {
            $where[] = 'AND editedon > ' . db()->escape($cs_recent_update);
        }

        if(evo()->documentIdentifier) {
            $where[] = 'AND id != ' . db()->escape(evo()->documentIdentifier);
        }
        $rs = db()->select(
            'id,pagetitle,description,content,publishedon,editedon,searchable',
            db()->getFullTableName('site_content'),
            implode(' ', $where)
        );

        if ($this->bearer_token) {
            evo()->saveBearerToken($this->bearer_token, time() + 60*60*3);
        }

        // プラグインを最新のコードにしてから、取得用のトークンを発行する
        if ($this->syncPlugin()) {
            evo()->clearCache('full');
        }
        $this->issueIndexToken();

        while($row = db()->getRow($rs)) {
            if(!$row['content']) {
                continue;
            }
            $url = evo()->makeUrl($row['id'],'','','full');
            $this->save(
                $row,
                $this->get_contents(
                    $url . (strpos($url, '?') === false ? '?' : '&') . 'template=blank',
                    $options
                )
            );
        }

        $this->revokeIndexToken();
        evo()->clearCache('full');
    }

    /**
     * 取得用のトークンを発行し、ハッシュと有効期限をシステム設定に保存する
     * （saveAll の max_execution_time に合わせて 10 分有効）
     *
     * インデックス作成は複数のリクエストで同時に始まることがある。1つの設定を共有すると、
     * 後から始まった側が先の側のトークンを上書きし、終わった側が相手のトークンを消してしまうので、
     * 作成ごとに別の設定（cs_index_token_<ID>）を作り、取り消すときは自分の分だけ消す。
     * ヘッダーには「ID.トークン」を送る（plugin-code.php が ID で設定を引いて照合する）
     */
    private function issueIndexToken() {
        $this->deleteExpiredIndexTokens();
        $this->index_token_id = bin2hex(random_bytes(8));
        $this->index_token = bin2hex(random_bytes(32));
        $this->saveSystemSetting(
            'cs_index_token_' . $this->index_token_id,
            hash('sha256', $this->index_token) . ':' . (time() + 600)
        );
    }

    private function revokeIndexToken() {
        if ($this->index_token_id) {
            db()->delete(
                db()->getFullTableName('system_settings'),
                sprintf("setting_name='cs_index_token_%s'", $this->index_token_id)
            );
        }
        $this->index_token_id = null;
        $this->index_token = null;
    }

    // 途中で止まったインデックス作成が残した、期限切れのトークンを消す
    private function deleteExpiredIndexTokens() {
        $rs = db()->select(
            'setting_name,setting_value',
            db()->getFullTableName('system_settings'),
            "setting_name LIKE 'cs\\_index\\_token\\_%'"
        );
        while ($row = db()->getRow($rs)) {
            $parts = explode(':', (string) $row['setting_value'], 2);
            if (isset($parts[1]) && time() <= (int) $parts[1]) {
                continue;
            }
            db()->delete(
                db()->getFullTableName('system_settings'),
                sprintf("setting_name='%s'", db()->escape($row['setting_name']))
            );
        }
    }

    private function treatText($text) {
        $text = preg_replace('@・・+@u', '', $text);
        $text = str_replace("\n", ' ', $text);
        return $text;
    }

    public function createPlugin() {
        $id = db()->insert(
            [
                'name' => db()->escape($this->pluginName),
                'description' => db()->escape($this->pluginDescription()),
                'plugincode' => db()->escape($this->pluginCode())
            ],
            db()->getFullTableName('site_plugins')
        );
        db()->insert(
            [
                'pluginid' => $id,
                'evtid' => $this->eventId('OnLoadWebDocument', 91)
            ],
            db()->getFullTableName('site_plugin_events')
        );
    }

    /**
     * 登録済みのプラグインを plugin-code.php の内容に合わせる。無ければ作る
     * プラグインのコードは作成時に DB へ写されるので、ファイルを更新しても既存サイトには反映されないため
     * 管理者が無効にしたプラグインは、無効のまま中身だけ更新する
     *
     * @return bool 作成または更新したら true
     */
    public function syncPlugin() {
        $row = db()->getRow(
            db()->select(
                'id,plugincode',
                db()->getFullTableName('site_plugins'),
                sprintf("name='%s'", db()->escape($this->pluginName)),
                'id ASC',
                1
            )
        );
        if (!$row) {
            $this->createPlugin();
            return true;
        }
        if ($row['plugincode'] === $this->pluginCode()) {
            return false;
        }
        db()->update(
            [
                'description' => db()->escape($this->pluginDescription()),
                'plugincode' => db()->escape($this->pluginCode())
            ],
            db()->getFullTableName('site_plugins'),
            'id=' . (int) $row['id']
        );
        return true;
    }

    private function pluginCode() {
        return preg_replace(
            '@^<\?php\s*@',
            '',
            file_get_contents(__DIR__ . '/plugin-code.php')
        );
    }

    private function pluginDescription() {
        return '@version ' . $this->version . "\n" . 'Search plugin for site_content';
    }

    private function eventId($name, $default) {
        $id = db()->getValue(
            db()->select(
                'id',
                db()->getFullTableName('system_eventnames'),
                sprintf("name='%s'", db()->escape($name))
            )
        );
        return $id ? (int) $id : $default;
    }

    private function get_contents($url, $options=[])
    {
        $ch = curl_init();
        curl_setopt_array(
            $ch, [
                CURLOPT_URL => $url,
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FAILONERROR => true,
                CURLOPT_MAXREDIRS => 3,
            ]
        );

        if (!empty($options['basicAuth'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $options['basicAuth']);
        }

        $headers = [];
        if ($this->index_token) {
            $headers[] = 'X-ContentSearch-Token: ' . $this->index_token_id . '.' . $this->index_token;
        }
        if ($this->bearer_token) {
            $headers[] = 'Authorization: Bearer '. $this->bearer_token;
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if (defined('CURLOPT_AUTOREFERER')) {
            curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        }
        if (serverv('HTTP_USER_AGENT')) {
            curl_setopt($ch, CURLOPT_USERAGENT, serverv('HTTP_USER_AGENT'));
        }
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function save($doc, $text) {
        $content = [];
        if ($this->config('additionalKeywordField')) {
            $content[] = $this->addKeywords($this->config('additionalKeywordField'), $doc['id']);
        }
        $content[] = $doc['pagetitle'];
        $content[] = $doc['description'];
        $content[] = $this->trimText($text);
        $plainText = $this->treatText(implode(' ', $content));
        db()->save(
            [
                'doc_id' => $doc['id'],
                'pagetitle' => db()->escape($doc['pagetitle']),
                'plain_text' => db()->escape($plainText),
                'publishedon' => array_get($doc, 'publishedon') ?: array_get($doc, 'editedon', time()),
                'editedon' => array_get($doc, 'editedon', time()),
                'tokens' => db()->escape(
                    $this->split_token($plainText)
                ),
            ],
            db()->getFullTableName('search_content'),
            'doc_id=' . $doc['id']
        );
    }

    private function addKeywords($fieldName, $docid) {
        $keywords = $this->getField($fieldName, $docid);
        if (!$keywords) {
            return '';
        }
        $keywords = preg_split('/[\n|\s|,]+/', $keywords);
        foreach ($keywords as &$keyword) {
            $keyword = sprintf('[%s]', $keyword);
        }
        return implode(' ', $keywords);
    }

    private function getField($key, $docid) {
        $doc = evo()->getDocumentObject('id', $docid);
        if (!isset($doc[$key])) {
            return '';
        }
        if (!is_array($doc[$key])) {
            return $doc[$key];
        }
        if (isset($doc[$key]['value'])) {
            return $doc[$key]['value'];
        }
        if (isset($doc[$key][2])) {
            return $doc[$key][2];
        }
        return '';
    }

    /**
     * テキストをトークン（検索可能な単語）に分割
     *
     * 改善点：
     * - デッドコードを削除
     * - N-gram結合を廃止（インデックスサイズの削減）
     * - ストップワードの充実
     * - 複合語の分解処理を改善
     *
     * @param string $str 分割対象のテキスト
     * @return string スペース区切りのトークン文字列
     */
    private function split_token($str) {
        // 文字種別でトークン分割
        preg_match_all(
            '/[一-龠]+|[ぁ-ん]+|[ァ-ヴー]+|[a-zA-Z0-9]+|[ａ-ｚＡ-Ｚ０-９]+/u',
            $str,
            $matches
        );

        $tokens = [];
        $tokenMinSize = $this->tokenMinSize();
        $stopWords = $this->getStopWords();

        foreach($matches[0] as $word) {
            // 最小トークンサイズチェック
            if(mb_strlen($word) < $tokenMinSize) {
                continue;
            }

            // ストップワード除外
            if(in_array($word, $stopWords, true)) {
                continue;
            }

            // 助動詞で終わる単語を除外
            if(preg_match('/(?:です|ます|でした|ました|である|であり)$/u', $word)) {
                continue;
            }

            // 基本トークンとして追加
            $tokens[] = $word;

            // 複合語（漢字+ひらがな）の分解処理
            // 例：「検索します」→「検索」と「します」に分解
            if(preg_match('/^([一-龠]{2,})([ぁ-ん]{2,})$/u', $word, $parts)) {
                $kanjiPart = $parts[1];    // 漢字部分
                $hiragaPart = $parts[2];   // ひらがな部分

                // 漢字部分を追加（名詞として重要）
                if(mb_strlen($kanjiPart) >= $tokenMinSize
                   && !in_array($kanjiPart, $stopWords, true)) {
                    $tokens[] = $kanjiPart;
                }

                // ひらがな部分も条件付きで追加
                if(mb_strlen($hiragaPart) >= $tokenMinSize
                   && !in_array($hiragaPart, $stopWords, true)
                   && !preg_match('/^[っゃゅょゎ]/u', $hiragaPart)) {
                    $tokens[] = $hiragaPart;
                }
            }
        }

        // 重複を削除して返す
        return implode(' ', array_unique($tokens));
    }

    /**
     * ストップワード（検索に不要な一般的な単語）のリスト
     *
     * @return array ストップワードの配列
     */
    private function getStopWords() {
        return [
            // 助詞
            'の', 'に', 'は', 'が', 'を', 'へ', 'と', 'や', 'で', 'も',
            'から', 'まで', 'より', 'ので', 'のに', 'ばかり',

            // 助動詞
            'です', 'ます', 'でした', 'ました', 'である', 'であり',
            'だった', 'でしょ', 'だろ',

            // 接続詞
            'そして', 'また', 'さらに', 'しかし', 'けれど', 'ただし',

            // 一般的な動詞の活用形
            'する', 'した', 'して', 'なる', 'なった', 'なり',
            'ある', 'あり', 'ない', 'いる', 'おり',

            // その他
            'こと', 'もの', 'ため', 'など', 'たち', 'ら',
            'これ', 'それ', 'あれ', 'この', 'その', 'あの',
            'ここ', 'そこ', 'あそこ', 'どこ',
        ];
    }
    public function tokenMinSize() {
        $rs = db()->query("SHOW VARIABLES like 'innodb_ft_min_token_size';");
        $row = db()->getRow($rs);
        if(empty($row['Value'])) {
            return 2; // Default minimum token size
        }
        return (int) $row['Value'];
    }

    private function trimText($text) {
        return preg_replace(
            '@\s\s+@u',
            ' ',
            trim(
                $this->remove_tags(
                    preg_replace(
                        '@<h[1-6].*?>(.+?)</h[1-6]>@is',
                        '[$0]',
                        $this->zenhan($text)
                    )
                )
            )
        );
    }
    private function remove_tags($value, $params = '')
    {
        if (stripos($params, 'style') === false && stripos($value, '</style>') !== false) {
            $value = preg_replace('#<style.*?>.*?</style>#is', '', $value);
        }
        if (stripos($params, 'script') === false && stripos($value, '</script>') !== false) {
            $value = preg_replace('@<script.*?>.*?</script>@is', '', $value);
        }
        if (strpos($params, '[[') === false && strpos($value, ']]') !== false) {
            $value = preg_replace('@\[\[.+?\]\]@s', '', $value);
        }
        if (strpos($params, '[!') === false && strpos($value, '!]') !== false) {
            $value = preg_replace('@\[!.+?!\]@s', '', $value);
        }
        if (strpos($params, '[+') === false && strpos($value, '+]') !== false) {
            $value = preg_replace('@\[\+.+?\+\]@s', '', $value);
        }
        return hsc(
            strip_tags(
                preg_replace('/&[a-z]+;/s', '', $value),
                $params
            )
        );
    }
    private function zenhan($string) {
        return str_replace(
            '&amp;',
            '&',
            mb_convert_kana(
                htmlspecialchars_decode($string),
                'Kas'
            )
        );
    }

    public function saveSystemSetting($key, $value) {
        db()->save(
            [
                'setting_name' => db()->escape($key),
                'setting_value' => db()->escape($value)
            ],
            db()->getFullTableName('system_settings'),
            sprintf("setting_name='%s'", db()->escape($key))
        );
    }
    public function createTable() {
        db()->query('DROP TABLE IF EXISTS '. db()->config['table_prefix']. 'search_content;');
        return db()->query(
            str_replace(
                '[+prefix+]',
                db()->config['table_prefix'],
                file_get_contents(__DIR__ . '/create-table.sql')
            )
        );
    }
}
