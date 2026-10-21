<?php

namespace App\Services\Billing;

use App\Models\Admin;
use App\Models\Dealer;
use App\Services\DistributorNetwork;

/**
 * Which buyers' billing a signed-in distributor / dealer may READ. Decided on the server from the login,
 * never from a request field; anything outside is a 404 (does not reveal that it exists).
 *   DEALER      : own account only
 *   DISTRIBUTOR : own account + the dealers linked to them
 */
class PortalScope
{
    private function __construct(public readonly int $ownId, public readonly array $dealerIds, public readonly string $role)
    {
    }

    public static function forUser(?Admin $user): self
    {
        if ($user && $user->role === 'DISTRIBUTOR') {
            $net = DistributorNetwork::forUser($user);
            if ($net) {
                return new self($net->distributor()->id, $net->dealerIds(), 'DISTRIBUTOR');
            }
        }
        if ($user && $user->role === 'DEALER' && $user->dealer_id) {
            $dealer = Dealer::find($user->dealer_id);
            if ($dealer) {
                return new self($dealer->id, [], 'DEALER');
            }
        }

        abort(403, 'This login is not linked to a dealer account.');
    }

    /** @return array<int,int> every buyer id this login may read */
    public function viewable(): array
    {
        return array_values(array_unique(array_merge([$this->ownId], $this->dealerIds)));
    }

    public function mayView(int $buyerDealerId): bool
    {
        return in_array($buyerDealerId, $this->viewable(), true);
    }
}
