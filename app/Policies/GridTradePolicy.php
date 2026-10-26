<?php

namespace App\Policies;

use App\Models\GridTrade;
use App\Models\User;

class GridTradePolicy
{
    /**
     * Determine whether the user can update the trade.
     */
    public function update(User $user, GridTrade $gridTrade): bool
    {
        return $gridTrade->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the trade.
     */
    public function delete(User $user, GridTrade $gridTrade): bool
    {
        return $gridTrade->user_id === $user->id;
    }
}
