<?php
/**
 * 検索の SQL を組み立てて実行する
 *
 * 索引（search_content）の本文 plain_text とキーワードは、どちらも HTML エスケープ済み。
 * タイトル（stext.pagetitle）とディスクリプション（content.description）はエスケープせずに保存されている。
 */
class csSearchQuery
{
    private $orderby;
    private $limit;
    private $offset;
    private $likeEscapeChar = '!';

    /**
     * @param string $orderby 'rel'（関連度順）か、それ以外（日付順）
     * @param int $limit 1 以上の整数
     * @param int $offset 0 以上の整数
     */
    public function __construct($orderby, $limit, $offset) {
        $this->orderby = $orderby;
        $this->limit = (int) $limit;
        $this->offset = (int) $offset;
    }

    /**
     * 検索結果の行を取得する
     * @param string $mode 'fulltext' か 'like'
     * @return mixed db()->select() の結果
     */
    public function find($keyword, $mode) {
        if($mode==='fulltext') {
            return $this->fulltextQuery($keyword);
        }
        return $this->likeQuery($keyword);
    }

    public function total($keyword, $mode) {
        if($mode==='fulltext') {
            return $this->fetchTotalFulltext($keyword);
        }
        return $this->fetchTotalLike($keyword);
    }

    /**
     * キーワードを検索語に分ける（空白区切り。重複は除き、多すぎる場合は先頭の 10 語まで）
     */
    public static function terms($keyword) {
        $terms = array_values(array_unique(
            preg_split('/\s+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY)
        ));
        return array_slice($terms, 0, 10);
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
            $this->searchFrom(),
            $this->fulltextWhere($keyword),
            $this->orderby==='rel' ? 'relevance_score DESC' : 'stext.publishedon DESC',
            $this->limitClause()
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
            implode(',', $field),
            $this->searchFrom(),
            $this->likeWhere($keyword),
            $this->orderby==='rel' ? 'relevance_score DESC' : 'stext.publishedon DESC',
            $this->limitClause()
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

        // 出現回数を数える REPLACE は大文字小文字を区別するが、LIKE や FULLTEXT は（照合順序によって）区別しない。
        // 「mysql」で「MySQL」がヒットしたのに回数が 0 にならないよう、本文と語の両方を小文字にそろえて数える
        $body = "LOWER(COALESCE(stext.plain_text, ''))";
        // キーワードは HTML エスケープ済み（索引の本文 plain_text もエスケープ済みなので、そのまま比べられる）。
        // タイトル（stext.pagetitle）とディスクリプション（content.description）はエスケープせずに保存されているので、
        // デコードした語と比べる（「Q&A」が「Q&amp;A」になって一致しない、ということが起きないように）
        $parts = [];
        foreach (self::terms($keyword) as $term) {
            $escaped = db()->escape($term);
            $raw = htmlspecialchars_decode($term, ENT_QUOTES);
            $parts[] = sprintf(
                "(CASE WHEN %s THEN 10 ELSE 0 END"
                . " + CASE WHEN %s THEN 4 ELSE 0 END"
                . " + CASE WHEN %s THEN 2 ELSE 0 END"
                . " + LEAST(10, (CHAR_LENGTH({$body}) - CHAR_LENGTH(REPLACE({$body}, LOWER('%s'), ''))) / CHAR_LENGTH('%s')) * 0.5)",
                $like('stext.pagetitle', $raw),
                $like('content.description', $raw),
                $like('SUBSTRING(stext.plain_text, 1, 300)', $term),
                $escaped,
                $escaped
            );
        }
        if (!$parts) {
            return '0 as relevance_score';
        }

        $whole = htmlspecialchars_decode(trim($keyword), ENT_QUOTES);
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

    private function limitClause() {
        if(!$this->offset) {
            return (string) $this->limit;
        }
        return $this->limit . ' OFFSET ' . $this->offset;
    }

    /**
     * 検索対象のテーブル。インデックスには、作成後に非公開・削除・会員限定にしたページや
     * 検索対象外にしたページも残っているので、今の状態で公開中のページだけに絞る
     */
    private function searchFrom() {
        return [
            db()->getFullTableName('search_content') . ' `stext`',
            'INNER JOIN ' . db()->getFullTableName('site_content') . ' content ON content.id=`stext`.doc_id'
                . ' AND content.published=1 AND content.deleted=0'
                . ' AND content.searchable=1 AND content.privateweb=0'
        ];
    }

    private function fetchTotalFulltext($keyword) {
        return db()->getValue(
            db()->select(
                'count(*) as total',
                $this->searchFrom(),
                $this->fulltextWhere($keyword)
            )
        );
    }

    /**
     * FULLTEXT の検索条件。InnoDB の標準パーサーは日本語を正しく区切れず、キーワードを含まないページも返すので
     * （「ホームページ」で「プライバシーポリシー」など）、部分一致の条件を重ねて、キーワードを含むページだけに絞る
     */
    private function fulltextWhere($keyword) {
        return sprintf(
            "MATCH (`tokens`) AGAINST (%s) AND (%s)",
            $this->matchAgainst($keyword),
            $this->likeWhere($keyword)
        );
    }

    private function fetchTotalLike($keyword) {
        return db()->getValue(
            db()->select(
                'count(*) as total',
                $this->searchFrom(),
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
}
