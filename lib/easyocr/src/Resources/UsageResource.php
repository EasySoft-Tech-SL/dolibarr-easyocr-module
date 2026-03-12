<?php

namespace EasySoft\EasyOCR\Resources;

use EasySoft\EasyOCR\Exceptions\EasyOCRException;

class UsageResource extends BaseResource
{
    /**
     * Get current month usage and quotas.
     *
     * @throws EasyOCRException
     */
    public function current(): array
    {
        return $this->request('GET', 'usage');
    }

    /**
     * Get usage history (last 12 months).
     *
     * @throws EasyOCRException
     */
    public function history(): array
    {
        return $this->request('GET', 'usage/history');
    }
}
