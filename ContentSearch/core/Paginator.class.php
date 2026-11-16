<?php
/**
 * 検索結果のページ送りを作る
 */
class csPaginator
{
    private $template;
    private $alwaysShow;
    private $baseUrl;
    private $request;

    /**
     * @param callable $template テンプレート名（'wrap'、'prev' など。設定の tplPaginate のキー）を受け取り、テンプレートを返す
     * @param bool $alwaysShow 1ページしかなくても表示するか
     * @param string $baseUrl ページのパス（クエリ文字列を除いたもの）
     * @param array $request 引き継ぐクエリ（$_GET）
     */
    public function __construct($template, $alwaysShow, $baseUrl, array $request) {
        $this->template = $template;
        $this->alwaysShow = $alwaysShow;
        $this->baseUrl = $baseUrl;
        unset($request['offset']);
        $this->request = $request;
    }

    public function build($total, $limit, $offset) {
        if(!$this->alwaysShow && $total <= $limit) {
            return null;
        }
        return evo()->parseText(
            $this->template('wrap'),
            [
                'prev' => $this->prev($limit, $offset),
                'pages' => $this->pages($total, $limit, $offset),
                'next' => $this->next($total, $limit, $offset)
            ]
        );
    }

    private function template($name) {
        return call_user_func($this->template, $name);
    }

    private function prev($limit, $offset) {
        if(!$offset) {
            return $this->template('no-prev');
        }
        $prevNum = $offset - $limit;
        return evo()->parseText(
            $this->template('prev'), [
                'prev-link' => $this->url($prevNum <= 0 ? [] : ['offset'=>$prevNum])
            ]
        );
    }

    private function pages($total, $limit, $offset) {
        if(!$this->isInt($total) || !$this->isInt($limit)) {
            return null;
        }
        $c=0;
        $pages = [];
        $num = !$offset ? 1 : round($offset/$limit)+1;
        if($num <= 5) {
            $num = 1;
        } elseif(5<$num) {
            $num = $num-4;
        }
        $current_num = ($offset/$limit)+1;
        while($c<10) {
            if($num==$current_num) {
                $pages[] = evo()->parseText(
                    $this->template('current-item'), [
                        'num' => $num
                    ]
                );
            } else {
                $pageOffset = $num==1 ? 0 : $limit * ($num-1);
                $pages[] = evo()->parseText(
                    $this->template('linked-item'), [
                        'num' => $num,
                        'url' => $this->url($pageOffset ? ['offset'=>$pageOffset] : [])
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
        return implode("\n", $pages);
    }

    private function next($total, $limit, $offset) {
        // 今のページで最後の結果まで表示しきるなら「次へ」は出さない
        if($total <= $limit + $offset) {
            return $this->template('no-next');
        }
        return evo()->parseText(
            $this->template('next'), [
                'next-link' => $this->url(['offset'=>($offset + $limit)])
            ]
        );
    }

    // テンプレートの href に入れる URL。パスはリクエストの値そのままなので HTML エスケープする
    private function url($query = []) {
        $params = array_replace_recursive($this->request, $query);
        if(!$params) {
            return hsc($this->baseUrl);
        }
        return hsc($this->baseUrl . '?' . http_build_query($params));
    }

    private function isInt($str) {
        return preg_match('@^[0-9]+$@',$str);
    }
}
