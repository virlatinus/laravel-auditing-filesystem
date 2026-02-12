<?php

namespace VirLatinus\Auditing\Drivers\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

final class Article extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    public function toAudit(): array
    {
        return [
            'old_values' => [
                'title' => 'Unveiling the Future: Emerging Technologies Shaping Our World',
                'version' => 1,
            ],
            'new_values' => [
                'title' => 'Unveiling the Future: Innovations Reshaping Our Society',
                'version' => 2,
            ]
        ];
    }
}
