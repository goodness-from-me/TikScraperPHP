<?php
namespace TikScraper\Items;

use TikScraper\Cache;
use TikScraper\Constants\TypeLegacy;
use TikScraper\Helpers\Curl;
use TikScraper\Models\Feed;
use TikScraper\Sender;

class Trending extends Base {
    function __construct(Sender $sender, Cache $cache, bool $legacy = false) {
        parent::__construct('', 'trending', $sender, $cache, $legacy);
    }

    public function feed($cursor = 0): self {
        $this->cursor = $cursor;
        if ($this->legacy) {
            // Cache works for legacy mode only
            $cached = $this->handleFeedCache();
            if (!$cached) {
                $this->feedLegacy($this->cursor);
            }
        } else {
            if (!$this->cursor) {
                $this->cursor = $this->__getTtwid();
            }
            $this->feedStandard($this->cursor);
        }
        return $this;
    }

    private function feedStandard(string $cursor = "") {
        $query = [
            "count" => 30,
            "id" => 1,
            "sourceType" => 12,
            "itemID" => 1,
            "insertedItemID" => ""
        ];

        $req = $this->sender->sendApi('/api/recommend/item_list', 'm', $query, '', false, $cursor);
        $response = new Feed;
        $response->fromReq($req, null, $cursor);
        $this->feed = $response;
    }

    private function feedLegacy(int $cursor = 0) {
        $query = [
            "type" => TypeLegacy::TRENDING,
            "id" => 1,
            "count" => 30,
            "minCursor" => 0,
            "maxCursor" => $cursor
        ];

        $req = $this->sender->sendApi('/node/video/feed', 'm', $query, '', false, '', false);
        $response = new Feed;
        $response->fromReq($req, $cursor);
        $this->feed = $response;
    }

    private function __getTtwid(): string {
        $res = $this->sender->sendHead('https://www.tiktok.com');
        $cookies = Curl::extractCookies($res['data']);
        return $cookies['ttwid'] ?? '';
    }
}
