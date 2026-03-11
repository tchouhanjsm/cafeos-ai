<?php

namespace App\Services;

use App\Models\KitchenStation;
use App\Models\OrderItem;

class KitchenRoutingService
{

    public function resolveStation($groupId)
    {

        if (!$groupId) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Get all active stations in group
        |--------------------------------------------------------------------------
        */

        $stations = KitchenStation::where('group_id', $groupId)
            ->where('is_active', 1)
            ->get();

        if ($stations->count() === 0) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Load Balancing (Least Loaded Station)
        |--------------------------------------------------------------------------
        */

        $leastLoadedStation = null;
        $leastLoad = PHP_INT_MAX;

        foreach ($stations as $station) {

            $load = OrderItem::where('station_id', $station->id)
                ->whereIn('status', ['pending','cooking'])
                ->count();

            if ($load < $leastLoad) {
                $leastLoad = $load;
                $leastLoadedStation = $station;
            }

        }

        return $leastLoadedStation ? $leastLoadedStation->id : null;

    }

}