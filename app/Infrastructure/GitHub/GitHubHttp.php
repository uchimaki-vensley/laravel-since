<?php

namespace App\Infrastructure\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * GitHub への HTTP リクエストの共通設定（タイムアウトとリトライ）。
 */
final readonly class GitHubHttp
{
    public function __construct(
        private int $connectTimeout,
        private int $timeout,
        private int $retries,
        private int $retrySleepMilliseconds,
    ) {}

    public function request(): PendingRequest
    {
        return Http::connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->withUserAgent('laravel-since')
            ->withoutRedirecting()
            ->retry(
                $this->retries,
                fn (int $attempt) => $this->retrySleepMilliseconds * $attempt,
                fn (Throwable $exception) => self::isTransient($exception),
            );
    }

    /**
     * 接続の失敗、429、5xx だけを再試行する。認証エラーやレート制限の枯渇（403）は待っても直らないので失敗させる。
     */
    private static function isTransient(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->status() === 429 || $exception->response->serverError()));
    }
}
