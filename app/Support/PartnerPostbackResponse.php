<?php

namespace App\Support;

final readonly class PartnerPostbackResponse
{
    public function __construct(
        public PartnerPostbackResult $result,
        public ?int $httpStatus,
    ) {}
}
