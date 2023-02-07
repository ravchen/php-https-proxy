<?php
/**
 * NGINX+PHP代理服务
 *
 * @TODO 图片、JS、CSS文件加上缓存
 *
 * @author keenx
 * @date 2023-2-7
 */


//判断是否https
$is_https = !empty($_SERVER['HTTPS']) || $_SERVER['SERVER_PORT'] == 443 ? true : false;
//当前访问域名
$host = $_SERVER['HTTP_HOST'];
//是否指定访问域名的解析IP，可不填
$ip = "175.178.30.137";


$url = ($is_https ? "https://" : "http://") . $host . $_SERVER['REQUEST_URI'];

//Set to false to report the client machine's IP address to proxied sites via the HTTP `x-forwarded-for` header.
//Setting to false may improve compatibility with some sites, but also exposes more information about end users to proxied sites.
$anonymize = true;


if (!function_exists("getallheaders")) {
  //Adapted from http://www.php.net/manual/en/function.getallheaders.php#99814
  function getallheaders() {
    $result = [];
    foreach($_SERVER as $key => $value) {
      if (substr($key, 0, 5) == "HTTP_") {
        $key = str_replace(" ", "-", ucwords(strtolower(str_replace("_", " ", substr($key, 5)))));
        $result[$key] = $value;
      }
    }
    return $result;
  }
}

ob_start("ob_gzhandler");
$response = makeRequest($url, $ip);

$rawResponseHeaders = $response["headers"];
$responseBody = $response["body"];
$responseInfo = $response["responseInfo"];

//A regex that indicates which server response headers should be stripped out of the proxified response.
$header_blacklist_pattern = "/^Content-Length|^Transfer-Encoding|^Content-Encoding.*gzip/i";

//cURL can make multiple requests internally (for example, if CURLOPT_FOLLOWLOCATION is enabled), and reports
//headers for every request it makes. Only proxy the last set of received response headers,
//corresponding to the final request made by cURL for any given call to makeRequest().
$responseHeaderBlocks = array_filter(explode("\r\n\r\n", $rawResponseHeaders));
$lastHeaderBlock = end($responseHeaderBlocks);
$headerLines = explode("\r\n", $lastHeaderBlock);
foreach ($headerLines as $header) {
  $header = trim($header);
  if (!preg_match($header_blacklist_pattern, $header)) {
    header($header, false);
  }
}
//Prevent robots from indexing proxified pages
header("X-Robots-Tag: noindex, nofollow", true);

$contentType = "";
if (isset($responseInfo["content_type"])) $contentType = $responseInfo["content_type"];

//This is presumably a web page, so attempt to proxify the DOM.
if (stripos($contentType, "text/html") !== false) {
  header("Content-Length: " . strlen($responseBody), true);
  echo $responseBody;
} else if (stripos($contentType, "text/css") !== false) { //This is CSS, so proxify url() references.
  header("Content-Length: " . strlen($responseBody), true);
  echo $responseBody;
} else { //This isn't a web page or CSS, so serve unmodified through the proxy with the correct headers (images, JavaScript, etc.)
  header("Content-Length: " . strlen($responseBody), true);
  echo $responseBody;
}


//Helper function used to removes/unset keys from an associative array using case insensitive matching
function removeKeys(&$assoc, $keys2remove) {
  $keys = array_keys($assoc);
  $map = [];
  $removedKeys = [];
  foreach ($keys as $key) {
    $map[strtolower($key)] = $key;
  }
  foreach ($keys2remove as $key) {
    $key = strtolower($key);
    if (isset($map[$key])) {
      unset($assoc[$map[$key]]);
      $removedKeys[] = $map[$key];
    }
  }
  return $removedKeys;
}



//Makes an HTTP request via cURL, using request data that was passed directly to this script.
function makeRequest($url, $ip = '') {

  global $anonymize;

  //Tell cURL to make the request using the brower's user-agent if there is one, or a fallback user-agent otherwise.
  $user_agent = $_SERVER["HTTP_USER_AGENT"];
  if (empty($user_agent)) {
    $user_agent = "Mozilla/5.0 (compatible; niceProxy)";
  }
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);

  //Get ready to proxy the browser's request headers...
  $browserRequestHeaders = getallheaders();

  //...but let cURL set some headers on its own.
  $removedHeaders = removeKeys(
    $browserRequestHeaders,
    [
      "Accept-Encoding", //Throw away the browser's Accept-Encoding header if any and let cURL make the request using gzip if possible.
      "Content-Length",
      "Host",
      "Origin"
    ]
  );

  $removedHeaders = array_map("strtolower", $removedHeaders);

  curl_setopt($ch, CURLOPT_ENCODING, "");
  //Transform the associative array from getallheaders() into an
  //indexed array of header strings to be passed to cURL.
  $curlRequestHeaders = [];
  $is_json = false;
  foreach ($browserRequestHeaders as $name => $value) {
    $curlRequestHeaders[] = $name . ": " . $value;
    if (strtolower($name) == "content-type") {
        $is_json = strpos($value, "multipart/form-data") !== false;
    }
  }

  if (!$anonymize) {
    $curlRequestHeaders[] = "X-Forwarded-For: " . $_SERVER["REMOTE_ADDR"];
  }
  //Any `origin` header sent by the browser will refer to the proxy itself.
  //If an `origin` header is present in the request, rewrite it to point to the correct origin.
  if (in_array("origin", $removedHeaders)) {
    $urlParts = parse_url($url);
    $port = $urlParts["port"];
    $curlRequestHeaders[] = "Origin: " . $urlParts["scheme"] . "://" . $urlParts["host"] . (empty($port) ? "" : ":" . $port);
  };

  //如果是指定访问的IP
  if (!empty($ip)) {
    $urlArr = parse_url($url);
    $url = str_replace($urlArr['scheme'] . '://' . $urlArr['host'], $urlArr['scheme'] . '://' . $ip, $url);
    $curlRequestHeaders[] = "Host: " . $urlArr['host'];
  }
  //Proxy any received GET/POST/PUT data.
  switch ($_SERVER["REQUEST_METHOD"]) {
    case "POST":
      curl_setopt($ch, CURLOPT_POST, true);
      //For some reason, $HTTP_RAW_POST_DATA isn't working as documented at
      //http://php.net/manual/en/reserved.variables.httprawpostdata.php
      //but the php://input method works. This is likely to be flaky
      //across different server environments.
      //More info here: http://stackoverflow.com/questions/8899239/http-raw-post-data-not-being-populated-after-upgrade-to-php-5-3
      //If the miniProxyFormAction field appears in the POST data, remove it so the destination server doesn't receive it.
      $postData = [];
      if ($is_json) {
        $postData = file_get_contents("php://input");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $curlRequestHeaders[] = "Content-Length: " . strlen($postData);
      } else {
        parse_str(file_get_contents("php://input"), $postData);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
      }
    break;
    case "PUT":
      curl_setopt($ch, CURLOPT_PUT, true);
      curl_setopt($ch, CURLOPT_INFILE, fopen("php://input", "r"));
    break;
  }

  curl_setopt($ch, CURLOPT_HTTPHEADER, $curlRequestHeaders);

  //Other cURL options.
  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

  //Set the request URL.
  curl_setopt($ch, CURLOPT_URL, $url);

  //Make the request.
  $response = curl_exec($ch);
  $responseInfo = curl_getinfo($ch);
  $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);

  //Setting CURLOPT_HEADER to true above forces the response headers and body
  //to be output together--separate them.
  $responseHeaders = substr($response, 0, $headerSize);
  $responseBody = substr($response, $headerSize);

  return ["headers" => $responseHeaders, "body" => $responseBody, "responseInfo" => $responseInfo];
}
