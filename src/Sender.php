<?php
namespace TikScraper;

use TikScraper\Constants\UserAgents;
use TikScraper\Helpers\Curl;
use TikScraper\Helpers\Misc;
use TikScraper\Helpers\Request;
use TikScraper\Helpers\Signer;
use TikScraper\Models\Response;

class Sender {
    private const REFERER = 'https://www.tiktok.com';

    private const DEFAULT_API_HEADERS = [
        "authority: m.tiktok.com",
        "method: GET",
        "scheme: https",
        "accept: application/json, text/plain, */*",
        "accept-encoding: gzip, deflate, br",
        "accept-language: en-US,en;q=0.9",
        "sec-fetch-dest: empty",
        "sec-fetch-mode: cors",
        "sec-fetch-site: same-site",
        "sec-gpc: 1"
    ];

    private Signer $signer;
    private $proxy = [];
    private $use_test_endpoints = false;
    private $useragent = UserAgents::DEFAULT;
    private $cookie_file = '';

    function __construct(array $config) {
        // Signing
        $signer = [];
        if (isset($config['signer'])) $signer = $config['signer'];

        $this->signer = new Signer($signer);

        if (isset($config['proxy'])) $this->proxy = $config['proxy'];
        if (isset($config['use_test_endpoints']) && $config['use_test_endpoints']) $this->use_test_endpoints = true;
        if (isset($config['user_agent'])) $this->useragent = $config['user_agent'];
        $this->cookie_file = sys_get_temp_dir() . '/tiktok.txt';
    }

    // -- Extra -- //
    public function sendHead(string $url, array $req_headers = [], string $useragent = '') {
        if (!$useragent) {
            $useragent = $this->useragent;
        }
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $useragent,
            CURLOPT_ENCODING => 'utf-8',
            CURLOPT_AUTOREFERER => true,
            CURLOPT_HTTPHEADER => $req_headers
        ]);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$headers) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) return $len;
            $headers[strtolower(trim($header[0]))][] = trim($header[1]);
            return $len;
        });

        Curl::handleProxy($ch, $this->proxy);

        $data = curl_exec($ch);
        curl_close($ch);
        return [
            'data' => $data,
            'headers' => $headers
        ];
    }

    public function getInfo(string $url, string $useragent): array {
        $res = $this->sendHead($url, [
            "x-secsdk-csrf-version: 1.2.5",
            "x-secsdk-csrf-request: 1"
        ], $useragent);
        $headers = $res['headers'];
        $cookies = Curl::extractCookies($res['data']);

        $csrf_session_id = $cookies['csrf_session_id'] ?? '';
        $csrf_token = isset($headers['x-ware-csrf-token'][0]) ? explode(',', $headers['x-ware-csrf-token'][0])[1] : '';

        return [
            'csrf_session_id' => $csrf_session_id,
            'csrf_token' => $csrf_token
        ];
    }

    public function sendApi(
        string $endpoint,
        string $subdomain = 'm',
        array $query = [],
        string $static_url = '',
        bool $send_tt_params = false,
        string $ttwid = '',
        bool $sign = true
    ): Response {
        if ($this->use_test_endpoints && $subdomain === 'm') {
            $subdomain = 't';
        }
        $headers = [];
        $cookies = '';
        $ch = curl_init();
        $url = 'https://' . $subdomain . '.tiktok.com' . $endpoint;
        $useragent = $this->useragent;
        $device_id = Misc::makeId();

        $headers[] = "Path: {$endpoint}";
        $url .= Request::buildQuery($query) . '&device_id=' . $device_id;
        $headers = array_merge($headers, self::DEFAULT_API_HEADERS);
        if ($sign) {
            // URL to send to signer
            $signer_res = $this->signer->run($url);
            if ($signer_res && $signer_res->status === 'ok') {
                $url = $signer_res->data->signed_url;
                $useragent = $signer_res->data->navigator->user_agent;
                if ($send_tt_params) {
                    $headers[] = 'x-tt-params: ' . $signer_res->data->{'x-tt-params'};
                }
                if ($ttwid) {
                    $cookies .= 'ttwid=' . $ttwid . ';';
                }
            } else {
                return new Response(false, 500, (object) [
                    'statusCode' => 20
                ]);
            }
        } else {
            $verify_fp = Misc::verify_fp();
            $url .= '&verifyFp=' . $verify_fp;
        }

        $extra = $this->getInfo($url, $useragent);
        $headers[] = 'x-secsdk-csrf-token:' . $extra['csrf_token'];
        $cookies .= Request::getCookies($device_id, $extra['csrf_session_id']);

        curl_setopt_array($ch, [
            CURLOPT_URL => $static_url ? $static_url : $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $useragent,
            CURLOPT_ENCODING => 'utf-8',
            CURLOPT_AUTOREFERER => true,
            CURLOPT_REFERER => self::REFERER,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
        ]);
        if ($cookies) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookies);
        }

        Curl::handleProxy($ch, $this->proxy);
        $data = curl_exec($ch);
        $error = curl_errno($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!$error) {
            // Request sent
            return new Response($code >= 200 && $code < 400, $code, json_decode($data));
        }
        return new Response(false, 503, (object) [
            'statusCode' => 10
        ]);
    }

    public function sendHTML(
        string $endpoint,
        string $subdomain = 'www',
        array $query = []
    ): Response {
        $ch = curl_init();
        $url = 'https://' . $subdomain . '.tiktok.com' . $endpoint;
        $useragent = $this->useragent;
        // Add query
        if (!empty($query)) $url .= '?' . http_build_query($query);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $useragent,
            CURLOPT_ENCODING => 'utf-8',
            CURLOPT_AUTOREFERER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_COOKIEJAR => $this->cookie_file,
            CURLOPT_COOKIEFILE => $this->cookie_file,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
        ]);
        Curl::handleProxy($ch, $this->proxy);
        $data = curl_exec($ch);
        $error = curl_errno($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!$error) {
            // Request sent
            return new Response($code >= 200 && $code < 400, $code, $data);
        }
        return new Response(false, 503, '');
    }
}
