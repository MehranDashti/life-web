<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use Tests\TestCase;
use App\Enums\UserStatus;

class UserStatusTest extends TestCase
{
    public function test_it_exposes_every_case(): void
    {
        $this->assertSame(['active', 'inactive'], UserStatus::values());
    }

    public function test_backing_values_are_stable(): void
    {
        $this->assertSame('active', UserStatus::Active->value);
        $this->assertSame('inactive', UserStatus::Inactive->value);
    }

    /**
     * values() feeds validation, so it must stay in step with the cases.
     */
    public function test_every_case_round_trips_through_its_backing_value(): void
    {
        foreach (UserStatus::values() as $value) {
            $this->assertInstanceOf(UserStatus::class, UserStatus::tryFrom($value));
        }

        $this->assertCount(count(UserStatus::cases()), UserStatus::values());
    }
}
