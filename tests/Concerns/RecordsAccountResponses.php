<?php

namespace Splicewire\Beam\Accounts\Tests\Concerns;

/** Optional verification observer: never changes a response or an assertion. */
trait RecordsAccountResponses
{
    protected int $accountResponseSequence = 0;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        $file = getenv('ACCOUNTS_SNAPSHOT_FILE');
        $decoded = json_decode($response->getContent(), true);
        if (is_string($file) && $file !== '' && is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
            file_put_contents($file, json_encode([
                'test' => $this->name(),
                'sequence' => ++$this->accountResponseSequence,
                'method' => $method,
                'uri' => $uri,
                'status' => $response->getStatusCode(),
                'body' => $decoded,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
        }

        return $response;
    }
}
