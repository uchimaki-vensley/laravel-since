<?php

namespace App\Providers;

use App\Application\Changes\ReleaseChangeRecorder;
use App\Application\Changes\ReleaseChangeRecorderService;
use App\Application\Changes\ReleaseChangeRepository;
use App\Application\Changes\ReleaseComparer;
use App\Application\Changes\ReleaseComparerService;
use App\Application\Releases\ApiSnapshotRepository;
use App\Application\Releases\ReleaseRepository;
use App\Application\Releases\ReleaseSourceFetcher;
use App\Application\Releases\ReleaseSourceFetcherService;
use App\Application\Releases\ReleaseSourceParser;
use App\Application\Releases\ReleaseSourceParserService;
use App\Application\Releases\ReleaseSourceStore;
use App\Application\Releases\ReleaseSynchronizer;
use App\Application\Releases\ReleaseSynchronizerService;
use App\Application\Releases\ReleaseTagSource;
use App\Application\Releases\SourceArchiveDownloader;
use App\Application\Releases\SourceParser;
use App\Application\Site\SiteBuilder;
use App\Application\Site\SiteBuilderService;
use App\Application\Site\SiteCatalog;
use App\Application\Site\SiteOutput;
use App\Application\Site\SiteRenderer;
use App\Domain\Releases\PackageName;
use App\Domain\Releases\Version;
use App\Infrastructure\Archives\SafeTarGzExtractor;
use App\Infrastructure\GitHub\GitHubHttp;
use App\Infrastructure\GitHub\GitHubReleaseTagSource;
use App\Infrastructure\GitHub\GitHubSourceArchiveDownloader;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentApiSnapshotRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentReleaseChangeRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentReleaseRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentSiteCatalog;
use App\Infrastructure\PhpSource\PhpSourceParser;
use App\Infrastructure\ReleaseSources\LocalReleaseSourceStore;
use App\Infrastructure\Site\BladeSiteRenderer;
use App\Infrastructure\Site\LocalSiteOutput;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ReleaseRepository::class, EloquentReleaseRepository::class);

        $this->app->bind(GitHubHttp::class, fn () => new GitHubHttp(
            Config::integer('since.github.connect_timeout'),
            Config::integer('since.github.timeout'),
            Config::integer('since.github.retries'),
            Config::integer('since.github.retry_sleep_milliseconds'),
        ));

        $this->app->bind(ReleaseTagSource::class, fn (Application $app) => new GitHubReleaseTagSource(
            $app->make(GitHubHttp::class),
            Config::string('since.github.api_url'),
            self::optionalString(Config::get('since.github.token')),
        ));

        $this->app->bind(SourceArchiveDownloader::class, fn (Application $app) => new GitHubSourceArchiveDownloader(
            $app->make(GitHubHttp::class),
            Config::string('since.github.archive_url'),
            Config::integer('since.sources.max_download_bytes'),
        ));

        $this->app->bind(ReleaseSourceStore::class, fn (Application $app) => new LocalReleaseSourceStore(
            $app->make(Filesystem::class),
            new SafeTarGzExtractor(
                Config::integer('since.sources.max_file_bytes'),
                Config::integer('since.sources.max_extracted_bytes'),
                Config::integer('since.sources.max_entries'),
            ),
            Config::string('since.sources.path'),
        ));

        $this->app->bind(ReleaseSynchronizer::class, fn (Application $app) => new ReleaseSynchronizerService(
            $app->make(ReleaseTagSource::class),
            $app->make(ReleaseRepository::class),
            new PackageName(Config::string('since.package')),
            Version::parse(Config::string('since.minimum_version')),
        ));

        $this->app->bind(ReleaseSourceFetcher::class, ReleaseSourceFetcherService::class);

        $this->app->bind(SourceParser::class, PhpSourceParser::class);
        $this->app->bind(ApiSnapshotRepository::class, EloquentApiSnapshotRepository::class);
        $this->app->bind(ReleaseSourceParser::class, ReleaseSourceParserService::class);

        $this->app->bind(ReleaseChangeRepository::class, EloquentReleaseChangeRepository::class);

        $this->app->bind(ReleaseChangeRecorder::class, fn (Application $app) => new ReleaseChangeRecorderService(
            $app->make(ReleaseChangeRepository::class),
            $app->make(ApiSnapshotRepository::class),
            new PackageName(Config::string('since.package')),
        ));

        $this->app->bind(ReleaseComparer::class, fn (Application $app) => new ReleaseComparerService(
            $app->make(ReleaseRepository::class),
            $app->make(ApiSnapshotRepository::class),
            new PackageName(Config::string('since.package')),
        ));

        $this->app->bind(SiteCatalog::class, EloquentSiteCatalog::class);
        $this->app->bind(SiteRenderer::class, BladeSiteRenderer::class);
        $this->app->bind(SiteOutput::class, fn (Application $app) => new LocalSiteOutput(
            $app->make(Filesystem::class),
            Config::string('since.site.assets_path'),
            Config::string('since.site.path'),
        ));
        $this->app->bind(SiteBuilder::class, fn (Application $app) => new SiteBuilderService(
            $app->make(SiteCatalog::class),
            $app->make(SiteRenderer::class),
            $app->make(SiteOutput::class),
            new PackageName(Config::string('since.package')),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('github', fn () => Limit::perMinute(Config::integer('since.github.jobs_per_minute')));
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
