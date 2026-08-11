<?php

namespace XQL\Cloud;

use XQL\Core\Utils\Env;

class AzureBlob implements CloudDriver
{
    private string $account;

    private string $accountKey;

    private string $container;

    private string $baseUrl;

    public function __construct()
    {
        $this->container = (string) Env::get("XQL_AZURE_BLOB_CONTAINER");
        if($this->container === "") {
            throw new \RuntimeException("XQL_AZURE_BLOB_CONTAINER is required when using the azure storage driver.");
        }

        $connection = $this->parseConnectionString((string) Env::get("XQL_AZURE_BLOB_CONNECTION_STRING"));
        $this->account = (string) (Env::get("XQL_AZURE_BLOB_ACCOUNT_NAME") ?: ($connection['AccountName'] ?? ""));
        $this->accountKey = (string) (Env::get("XQL_AZURE_BLOB_ACCOUNT_KEY") ?: ($connection['AccountKey'] ?? ""));

        if($this->account === "" || $this->accountKey === "") {
            throw new \RuntimeException("XQL_AZURE_BLOB_CONNECTION_STRING or XQL_AZURE_BLOB_ACCOUNT_NAME/XQL_AZURE_BLOB_ACCOUNT_KEY is required when using the azure storage driver.");
        }

        $blobEndpoint = Env::get("XQL_AZURE_BLOB_ENDPOINT") ?: ($connection['BlobEndpoint'] ?? null);
        if($blobEndpoint) {
            $this->baseUrl = rtrim($blobEndpoint, "/");
        } else {
            $protocol = Env::get("XQL_AZURE_BLOB_PROTOCOL") ?: ($connection['DefaultEndpointsProtocol'] ?? "https");
            $endpointSuffix = Env::get("XQL_AZURE_BLOB_ENDPOINT_SUFFIX") ?: ($connection['EndpointSuffix'] ?? "core.windows.net");
            $this->baseUrl = $protocol . "://" . $this->account . ".blob." . $endpointSuffix;
        }
    }

    public function put(string $key, string $content): void
    {
        $this->request("PUT", $key, $content, [
            'x-ms-blob-type' => 'BlockBlob',
        ]);
    }

    public function get(string $key): string
    {
        return $this->request("GET", $key);
    }

    private function request(string $method, string $key, string $body = "", array $extraHeaders = []): string
    {
        $key = $this->normalizeKey($key);
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $headers = array_merge([
            'x-ms-date' => $date,
            'x-ms-version' => '2023-11-03',
        ], $extraHeaders);

        $contentLength = $method === "PUT" ? (string) strlen($body) : "";
        $headers['Authorization'] = $this->authorizationHeader($method, $key, $headers, $contentLength);

        if($method === "PUT") {
            $headers['Content-Length'] = $contentLength;
        }

        $headerLines = [];
        foreach($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
            ],
        ]);

        $url = $this->baseUrl . '/' . rawurlencode($this->container) . '/' . $this->encodeBlobPath($key);
        $result = file_get_contents($url, false, $context);
        $status = $this->httpStatus($http_response_header ?? []);

        if($result === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException("Azure Blob request failed for XQL object {$key}. HTTP status: " . ($status ?: "unknown") . ".");
        }

        return (string) $result;
    }

    private function authorizationHeader(string $method, string $key, array $headers, string $contentLength): string
    {
        $canonicalizedHeaders = $this->canonicalizedHeaders($headers);
        $canonicalizedResource = '/' . $this->account . '/' . $this->container . '/' . $key;

        $stringToSign = $method . "
"
            . "
"
            . "
"
            . $contentLength . "
"
            . "
"
            . "
"
            . "
"
            . "
"
            . "
"
            . "
"
            . "
"
            . "
"
            . $canonicalizedHeaders
            . $canonicalizedResource;

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey), true));
        return 'SharedKey ' . $this->account . ':' . $signature;
    }

    private function canonicalizedHeaders(array $headers): string
    {
        $canonical = [];
        foreach($headers as $name => $value) {
            $name = strtolower($name);
            if(str_starts_with($name, 'x-ms-')) {
                $canonical[$name] = trim(preg_replace('/\s+/', ' ', (string) $value));
            }
        }
        ksort($canonical);

        $lines = [];
        foreach($canonical as $name => $value) {
            $lines[] = $name . ':' . $value;
        }

        return implode("
", $lines) . "
";
    }

    private function parseConnectionString(string $connectionString): array
    {
        $values = [];
        foreach(explode(';', $connectionString) as $part) {
            if(!str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $values[$key] = $value;
        }
        return $values;
    }

    private function normalizeKey(string $key): string
    {
        return ltrim(str_replace(["..", "\\"], ["", "/"], $key), "/");
    }

    private function encodeBlobPath(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private function httpStatus(array $headers): int
    {
        foreach($headers as $header) {
            if(preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                return (int) $matches[1];
            }
        }
        return 0;
    }
}
