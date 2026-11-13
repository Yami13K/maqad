<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Domain\Catalog\Models\CourseSession;
use App\Domain\Student\Models\Student;
use App\Domain\Wallet\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** E3: balance plus every ledger row, scoped to the caller. */
final class WalletStatementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_statement_lists_the_callers_ledger_oldest_first(): void
    {
        $this->freezeClock();
        $student = Student::factory()->withBalance('100.000')->create();
        Student::factory()->withBalance('500.000')->create(); // someone else's money stays invisible
        $bookingId = $this->book($student, CourseSession::factory()->create(['price' => '42.125'])->id)->json('booking_id');

        $rows = WalletTransaction::query()->whereRelation('wallet', 'student_id', $student->id)->orderBy('id')->get();

        $this->getJson('/wallets/me', ['X-Student-Id' => (string) $student->id])->assertOk()->assertExactJson([
            'wallet_balance' => '57.875',
            'transactions' => [
                ['id' => $rows[0]->id, 'type' => 'topup', 'amount' => '100.000', 'booking_id' => null, 'created_at' => $rows[0]->created_at->toIso8601ZuluString()],
                ['id' => $rows[1]->id, 'type' => 'charge', 'amount' => '-42.125', 'booking_id' => $bookingId, 'created_at' => $rows[1]->created_at->toIso8601ZuluString()],
            ],
        ]);
    }

    #[Test]
    public function the_statement_requires_a_known_student(): void
    {
        $this->getJson('/wallets/me')->assertStatus(401)->assertExactJson([
            'error' => ['code' => 'unknown_student', 'message' => 'Send a valid X-Student-Id header.'],
        ]);
    }
}
