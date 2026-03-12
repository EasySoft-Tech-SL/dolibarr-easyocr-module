<?php

namespace EasySoft\EasyOCR\Resources;

use EasySoft\EasyOCR\Exceptions\EasyOCRException;

/**
 * Wallet / prepaid balance resource.
 *
 * Provides access to wallet balance, transaction history and available
 * prepaid packages.
 */
class WalletResource extends BaseResource
{
    /**
     * Get current wallet balance and usage counters.
     *
     * @return array{data: array{balance_pages: int, total_purchased_pages: int, total_consumed_pages: int, billing_mode: string, is_active: bool}}
     *
     * @throws EasyOCRException
     */
    public function balance(): array
    {
        return $this->request('GET', 'wallet/balance');
    }

    /**
     * Get paginated transaction history.
     *
     * @param int $perPage Items per page (default: 20)
     *
     * @return array{data: array, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     *
     * @throws EasyOCRException
     */
    public function transactions(int $perPage = 20): array
    {
        $query = [];
        if ($perPage !== 20) {
            $query['per_page'] = $perPage;
        }

        return $this->request('GET', 'wallet/transactions', $query ? ['query' => $query] : []);
    }

    /**
     * List available prepaid packages.
     *
     * @return array{data: array}
     *
     * @throws EasyOCRException
     */
    public function packages(): array
    {
        return $this->request('GET', 'wallet/packages');
    }
}
