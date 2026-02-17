<?php

namespace EasySoft\EasyOCR\Resources;

use EasySoft\EasyOCR\Exceptions\EasyOCRException;

class AccountResource extends BaseResource
{
    /**
     * Get current account information, plan, quota and features.
     *
     * @throws EasyOCRException
     */
    public function me(): array
    {
        return $this->request('GET', 'me');
    }
}
