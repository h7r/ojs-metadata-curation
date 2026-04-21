<?php

/**
 * P0 — Auth boundary tests for SuggestHandler::authorizeSubmissionAccess.
 *
 * Since the method depends on OJS framework objects (Request, User, DAORegistry),
 * we test the authorization logic by extracting it into a testable form.
 * This mirrors the exact logic in SuggestHandler::authorizeSubmissionAccess()
 * without requiring the full OJS runtime.
 */

namespace APP\plugins\generic\nvMetadataCuration\tests;

use PHPUnit\Framework\TestCase;

/**
 * Extracted authorization logic that mirrors SuggestHandler::authorizeSubmissionAccess.
 * This is the System Under Test — a pure-function extraction of the auth checks.
 */
class AuthorizationLogic
{
    /**
     * @param int $submissionId
     * @param ?object $user          null = unauthenticated
     * @param ?object $submission    null = not found
     * @param ?int $submissionContextId  context the submission belongs to
     * @param ?int $requestContextId     context of the current request
     * @param bool $isParticipant
     * @param bool $isManager
     * @return array{allowed: bool, error: string, httpCode: int}
     */
    public static function check(
        int $submissionId,
        ?object $user,
        ?object $submission,
        ?int $submissionContextId,
        ?int $requestContextId,
        bool $isParticipant,
        bool $isManager
    ): array {
        if ($submissionId <= 0) {
            return ['allowed' => false, 'error' => 'Missing or invalid submissionId', 'httpCode' => 400];
        }

        if ($user === null) {
            return ['allowed' => false, 'error' => 'Authentication required', 'httpCode' => 401];
        }

        if ($submission === null) {
            return ['allowed' => false, 'error' => 'Submission not found', 'httpCode' => 404];
        }

        if ($requestContextId === null || $submissionContextId !== $requestContextId) {
            return ['allowed' => false, 'error' => 'Submission not in current context', 'httpCode' => 403];
        }

        if (!$isParticipant && !$isManager) {
            return ['allowed' => false, 'error' => 'Not authorized to modify this submission', 'httpCode' => 403];
        }

        return ['allowed' => true, 'error' => '', 'httpCode' => 200];
    }
}

class AuthBoundaryTest extends TestCase
{
    private object $user;
    private object $submission;

    protected function setUp(): void
    {
        $this->user = new \stdClass();
        $this->user->id = 42;

        $this->submission = new \stdClass();
        $this->submission->id = 100;
        $this->submission->contextId = 1;
    }

    // ── submissionId validation ─────────────────────────────────────

    public function testRejectsZeroSubmissionId(): void
    {
        $result = AuthorizationLogic::check(0, $this->user, $this->submission, 1, 1, true, false);
        $this->assertFalse($result['allowed']);
        $this->assertSame(400, $result['httpCode']);
    }

    public function testRejectsNegativeSubmissionId(): void
    {
        $result = AuthorizationLogic::check(-1, $this->user, $this->submission, 1, 1, true, false);
        $this->assertFalse($result['allowed']);
        $this->assertSame(400, $result['httpCode']);
    }

    // ── Authentication ──────────────────────────────────────────────

    public function testRejectsUnauthenticatedUser(): void
    {
        $result = AuthorizationLogic::check(100, null, $this->submission, 1, 1, false, false);
        $this->assertFalse($result['allowed']);
        $this->assertSame(401, $result['httpCode']);
        $this->assertStringContainsString('Authentication', $result['error']);
    }

    // ── Submission existence ────────────────────────────────────────

    public function testRejectsNonexistentSubmission(): void
    {
        $result = AuthorizationLogic::check(999, $this->user, null, null, 1, false, false);
        $this->assertFalse($result['allowed']);
        $this->assertSame(404, $result['httpCode']);
    }

    // ── Context isolation (IDOR prevention) ─────────────────────────

    public function testRejectsSubmissionFromDifferentContext(): void
    {
        // Submission belongs to context 1, request targets context 2
        $result = AuthorizationLogic::check(100, $this->user, $this->submission, 1, 2, true, true);
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['httpCode']);
        $this->assertStringContainsString('context', $result['error']);
    }

    public function testRejectsWhenRequestContextIsNull(): void
    {
        $result = AuthorizationLogic::check(100, $this->user, $this->submission, 1, null, true, true);
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['httpCode']);
    }

    // ── Role-based access ───────────────────────────────────────────

    public function testParticipantIsAllowed(): void
    {
        $result = AuthorizationLogic::check(100, $this->user, $this->submission, 1, 1, true, false);
        $this->assertTrue($result['allowed']);
    }

    public function testManagerIsAllowed(): void
    {
        $result = AuthorizationLogic::check(100, $this->user, $this->submission, 1, 1, false, true);
        $this->assertTrue($result['allowed']);
    }

    public function testNonParticipantNonManagerIsRejected(): void
    {
        $result = AuthorizationLogic::check(100, $this->user, $this->submission, 1, 1, false, false);
        $this->assertFalse($result['allowed']);
        $this->assertSame(403, $result['httpCode']);
        $this->assertStringContainsString('Not authorized', $result['error']);
    }

    // ── Check ordering: auth before authz ───────────────────────────

    /**
     * Critical: authentication must be checked BEFORE authorization.
     * An unauthenticated user should get 401, not 403.
     */
    public function testAuthBeforeAuthz(): void
    {
        // Unauthenticated, non-participant, non-manager
        $result = AuthorizationLogic::check(100, null, $this->submission, 1, 1, false, false);
        $this->assertSame(401, $result['httpCode'], 'Must return 401 (not 403) for unauthenticated users');
    }

    /**
     * Critical: submission existence checked before context mismatch.
     * A missing submission should get 404, not 403.
     */
    public function testExistenceBeforeContextCheck(): void
    {
        $result = AuthorizationLogic::check(100, $this->user, null, null, 1, false, false);
        $this->assertSame(404, $result['httpCode'], 'Must return 404 (not 403) for non-existent submission');
    }

    // ── Boundary: combined valid state ──────────────────────────────

    public function testFullyAuthorizedParticipant(): void
    {
        $result = AuthorizationLogic::check(100, $this->user, $this->submission, 1, 1, true, false);
        $this->assertTrue($result['allowed']);
        $this->assertSame(200, $result['httpCode']);
    }

    public function testFullyAuthorizedManager(): void
    {
        $result = AuthorizationLogic::check(100, $this->user, $this->submission, 1, 1, false, true);
        $this->assertTrue($result['allowed']);
        $this->assertSame(200, $result['httpCode']);
    }

    public function testParticipantAndManagerBothTrue(): void
    {
        $result = AuthorizationLogic::check(100, $this->user, $this->submission, 1, 1, true, true);
        $this->assertTrue($result['allowed']);
    }
}
