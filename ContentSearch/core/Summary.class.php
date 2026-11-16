<?php
/**
 * 検索結果に出す要約とハイライトを作る
 *
 * 索引の本文とキーワードはどちらも HTML エスケープ済み。
 * いったんデコードして文に分け、切り出したあとでエスケープし直す
 * （エスケープ済みのまま切ると「&am」のように文字参照の途中で切れることがある）
 */
class csSummary
{
    private $length;
    private $highlight;
    private $japanese;

    /**
     * @param int $length 要約の長さ（文字数）
     * @param string $highlight ハイライトのテンプレート（[+keyword+] が一致した語になる）
     * @param bool $japanese 日本語の句読点でも文を区切るか
     */
    public function __construct($length, $highlight, $japanese) {
        $this->length = (int) $length;
        $this->highlight = evo()->parseText($highlight, ['keyword' => '$0']);
        $this->japanese = $japanese;
    }

    /**
     * 要約を作り、キーワードをハイライトして返す（HTML エスケープ済み）
     */
    public function make($text, $keyword) {
        return $this->highlight($this->summary($text, $keyword), $keyword);
    }

    private function highlight($str, $keyword) {
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
            $this->highlight,
            $str
        );
    }

    private function summary($text, $keyword) {
        $length = $this->length;
        // 本文のタグは索引を作るときに取り除いてあるので、ここでは HTML として扱わない
        // （タイトルの「&lt;code&gt;」のような文字そのものを strip_tags で消さないため。出力時にエスケープする）
        $plain = str_replace('&nbsp;', ' ', htmlspecialchars_decode($text, ENT_QUOTES));
        $keyword = htmlspecialchars_decode($keyword, ENT_QUOTES);
        if(mb_strlen($plain) < ($length-30)) {
            return hsc($plain);
        }
        if(strpos($keyword, ' ')!==false) {
            return hsc(mb_substr($plain, 0, $length));
        }
        if(!preg_match('/[亜-熙ぁ-んァ-ヶ]/u', mb_substr($plain, 0, $length))) {
            return hsc(mb_substr($plain, 0, $length));
        }
        $pettern = $this->japanese
            ? '/[\s\n\.\!\?\,。、]+/u'
            : '/[\n\.\!\?]+/u'
        ;
        $_ = preg_split($pettern, $plain);
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
        $summary = $this->trim($summary);
        return hsc(mb_substr(
            ($summary ? implode(' ', $summary) : $plain),
            0,
            $length
        )). '...';
    }

    private function trim($summary) {
        // 空の要素を削除
        $summary = array_filter($summary, function($value) {
            return !empty($value);
        });

        // 重複する要素を削除
        $summary = array_unique($summary);

        // 一文字目が「[」の要素（索引を作るときに [ ] で囲んだ見出し）を削除
        $summary = array_filter($summary, function($value) {
            return $value[0] !== '[';
        });
        return $summary;
    }
}
