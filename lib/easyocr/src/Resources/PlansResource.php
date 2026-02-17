<?php

namespace EasySoft\EasyOCR\Resources;

use EasySoft\EasyOCR\Exceptions\EasyOCRException;

class PlansResource extends BaseResource
{
    /**
     * List all available plans (public endpoint, no authentication required).
     *
     * @throws EasyOCRException
     */
    public function list(): array
    {
        return $this->request('GET', 'plans');
    }
}
