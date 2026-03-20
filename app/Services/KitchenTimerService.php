<?php

namespace App\Services;

class KitchenTimerService
{

    public function calculate($item)
    {

        if(!$item->cooking_started_at){
            return null;
        }

        $elapsed = now()->diffInSeconds($item->cooking_started_at);

        $prep = ($item->menuItem->prep_time ?? 5) * 60;

        return [
            'elapsed_seconds' => $elapsed,
            'expected_seconds' => $prep,
            'is_delayed' => $elapsed > $prep
        ];

    }

}