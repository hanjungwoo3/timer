<?php
// 음악 파일 프록시 - CORS 문제 해결
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$url = isset($_GET['url']) ? $_GET['url'] : '';

if (empty($url)) {
    http_response_code(400);
    echo 'URL parameter is required';
    exit;
}

// URL 검증 (JW.org 도메인만 허용)
if (!preg_match('/^https:\/\/.*\.jw-cdn\.org\//', $url)) {
    http_response_code(403);
    echo 'Only JW.org CDN URLs are allowed';
    exit;
}

// cURL로 음악 파일 가져오기
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

// 헤더 정보 가져오기
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

if (curl_error($ch)) {
    http_response_code(500);
    echo 'Error fetching audio: ' . curl_error($ch);
    curl_close($ch);
    exit;
}

curl_close($ch);

if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo 'Failed to fetch audio file';
    exit;
}

// 응답 헤더 설정
$body = substr($response, $headerSize);
header('Content-Type: ' . ($contentType ? $contentType : 'audio/mpeg'));
header('Content-Length: ' . strlen($body));
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=3600');

// 음악 파일 출력
echo $body;
?>
