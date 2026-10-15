<?php

namespace Illuminate\Fixture\Concerns;

use Illuminate\Fixture\Status;

trait HasStatus
{
    public function status(): Status
    {
        return Status::Active;
    }

    protected function setStatus(Status $status): void {}
}
