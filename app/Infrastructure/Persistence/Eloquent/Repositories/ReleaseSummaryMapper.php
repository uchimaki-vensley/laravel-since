<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Releases\ReleaseSummary;
use App\Domain\Releases\PackageName;
use App\Domain\Releases\ReleaseTag;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use UnexpectedValueException;

final class ReleaseSummaryMapper
{
    public static function toSummary(Release $release): ReleaseSummary
    {
        $tag = ReleaseTag::tryParse($release->tag);

        if ($tag === null || (string) $tag->version !== $release->version) {
            throw new UnexpectedValueException("Release {$release->id} has an invalid tag: {$release->tag}");
        }

        return new ReleaseSummary($release->id, new PackageName($release->package), $tag);
    }
}
