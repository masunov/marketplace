<?php
declare(strict_types=1);

namespace App\Domain\Entity\Concerns;

/** @see \Illuminate\Database\Eloquent\Concerns\GuardsAttributes::isGuardableColumn() */
trait CreatesWithoutFill
{
    /** @param  array<string, mixed>  $attributes */
    protected static function createWithoutFill(array $attributes): static
    {
        $model = new static();

        foreach ($attributes as $key => $value) {
            $model->setAttribute($key, $value);
        }

        $model->save();

        return $model;
    }
}
