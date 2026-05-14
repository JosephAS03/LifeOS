<?php

declare(strict_types=1);

namespace LifeOS\Support;

final class Hmac
{
    public function sign(string $secret, string $timestamp, string $nonce, string $method, string $requestTarget, string $rawBody): string
    {
        $message = $this->canonicalMessage($timestamp, $nonce, $method, $requestTarget, $rawBody);

        return hash_hmac('sha256', $message, $secret);
    }

    public function verify(string $provided, string $secret, string $timestamp, string $nonce, string $method, string $requestTarget, string $rawBody, int $maxDriftSeconds = 300): bool
    {
        if ($provided === '' || $secret === '' || $timestamp === '' || $nonce === '') {
            return false;
        }

        $unixTime = strtotime($timestamp);
        if ($unixTime === false) {
            return false;
        }

        if (abs(time() - $unixTime) > $maxDriftSeconds) {
            return false;
        }

        $expected = $this->sign($secret, $timestamp, $nonce, $method, $requestTarget, $rawBody);

        return hash_equals($expected, strtolower($provided));
    }

    public function requestHash(string $method, string $requestTarget, string $rawBody): string
    {
        return hash('sha256', strtoupper($method) . '|' . $requestTarget . '|' . $rawBody);
    }

    private function canonicalMessage(string $timestamp, string $nonce, string $method, string $requestTarget, string $rawBody): string
    {
        return $timestamp . '.' . $nonce . '.' . strtoupper($method) . '.' . $requestTarget . '.' . $rawBody;
    }
}
