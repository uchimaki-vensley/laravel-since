<?php

namespace App\Providers;

use App\Application\Releases\GitHubReleaseSource;
use App\Application\Releases\ReleaseRepository;
use App\Application\Releases\ReleaseSyncSettings;
use App\Application\Releases\SyncReleases;
use App\Application\Releases\SyncReleasesService;
use App\Infrastructure\Persistence\Eloquent\EloquentReleaseRepository;
use App\Infrastructure\ReleaseSource\GitHubReleaseSourceAdapter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ReleaseRepository::class, EloquentReleaseRepository::class);
        $this->app->bind(GitHubReleaseSource::class, GitHubReleaseSourceAdapter::class);
        $this->app->bind(SyncReleases::class, SyncReleasesService::class);
        $this->app->singleton(ReleaseSyncSettings::class, static function (): ReleaseSyncSettings {
            $package = config('since.package');
            $minimumVersion = config('since.minimum_version');
            if (! is_string($package) || ! is_string($minimumVersion)) {
                throw new RuntimeException('Release synchronization settings must be strings.');
            }

            return new ReleaseSyncSettings($package, $minimumVersion);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('github', static fn (): Limit => Limit::perMinute(30)->by('github'));
    }
}
