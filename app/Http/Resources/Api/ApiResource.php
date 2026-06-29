<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class ApiResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->transformKeys(parent::toArray($request));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function transformKeys(array $data): array
    {
        $transformed = [];

        foreach ($data as $key => $value) {
            $camelKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));

            if (is_array($value)) {
                $transformed[$camelKey] = array_is_list($value)
                    ? array_map(fn ($item) => is_array($item) ? $this->transformKeys($item) : $item, $value)
                    : $this->transformKeys($value);
            } else {
                $transformed[$camelKey] = $value;
            }
        }

        return $transformed;
    }
}
