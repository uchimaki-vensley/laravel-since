<?php

namespace App\Infrastructure\GitHub;

use App\Application\Releases\ReleaseTagSource;
use App\Domain\Releases\PackageName;
use UnexpectedValueException;

final readonly class GitHubReleaseTagSource implements ReleaseTagSource
{
    private const int PER_PAGE = 100;

    /** laravel/framework は 2026-10 時点で 14 ページ。応答が想定外でも止まるように上限を置く。 */
    private const int MAX_PAGES = 100;

    public function __construct(
        private GitHubHttp $http,
        private string $apiUrl,
        private ?string $token,
    ) {}

    public function tagNames(PackageName $package): array
    {
        $names = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $tags = $this->fetchPage($package, $page);

            foreach ($tags as $tag) {
                $names[] = $tag;
            }

            if (count($tags) < self::PER_PAGE) {
                return $names;
            }
        }

        throw new UnexpectedValueException("{$package} has more than ".self::MAX_PAGES.' pages of tags.');
    }

    /**
     * @return list<string>
     */
    private function fetchPage(PackageName $package, int $page): array
    {
        $request = $this->http->request()
            ->baseUrl($this->apiUrl)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);

        if ($this->token !== null && $this->token !== '') {
            $request = $request->withToken($this->token);
        }

        $tags = $request
            ->get("/repos/{$package}/tags", ['per_page' => self::PER_PAGE, 'page' => $page])
            ->throw()
            ->json();

        if (! is_array($tags) || ! array_is_list($tags)) {
            throw new UnexpectedValueException('GitHub returned an unexpected tag list.');
        }

        return array_map(static function (mixed $tag): string {
            if (! is_array($tag) || ! is_string($tag['name'] ?? null)) {
                throw new UnexpectedValueException('GitHub returned an unexpected tag.');
            }

            return $tag['name'];
        }, $tags);
    }
}
