<?php

/**
 * @file tests/CsrfOriginTest.php
 *
 * Unit tests for CsrfGuard::originMatchesBase — the pure Origin/Referer
 * comparator that backs the defense-in-depth side of the CSRF guard
 * protecting SuggestHandler::save and SuggestHandler::saveContributorIds.
 *
 * These tests do not require the OJS runtime: originMatchesBase takes two
 * strings and returns bool. A cross-origin POST that forges a token header
 * must still be rejected here.
 */

namespace APP\plugins\generic\nvMetadataCuration\tests;

use APP\plugins\generic\nvMetadataCuration\classes\security\CsrfGuard;
use PHPUnit\Framework\TestCase;

class CsrfOriginTest extends TestCase
{
    // ── Happy path ──────────────────────────────────────────────────

    public function testOriginHeaderMatchesBase(): void
    {
        $this->assertTrue(CsrfGuard::originMatchesBase(
            'https://journal.example.com',
            'https://journal.example.com'
        ));
    }

    public function testRefererUrlMatchesBase(): void
    {
        // Referer includes a path; base is scheme+host only. Still a match.
        $this->assertTrue(CsrfGuard::originMatchesBase(
            'https://journal.example.com/index.php/foo/submission/42',
            'https://journal.example.com'
        ));
    }

    public function testMatchIsCaseInsensitiveOnSchemeAndHost(): void
    {
        $this->assertTrue(CsrfGuard::originMatchesBase(
            'HTTPS://Journal.Example.COM/submission/1',
            'https://journal.example.com'
        ));
    }

    public function testPortMatchIsAccepted(): void
    {
        $this->assertTrue(CsrfGuard::originMatchesBase(
            'http://localhost:8080',
            'http://localhost:8080'
        ));
    }

    // ── Cross-origin attack surfaces ────────────────────────────────

    public function testRejectsDifferentHost(): void
    {
        // Attacker domain, correct scheme. Classic CSRF origin.
        $this->assertFalse(CsrfGuard::originMatchesBase(
            'https://evil.example.com',
            'https://journal.example.com'
        ));
    }

    public function testRejectsSubdomainAttack(): void
    {
        // evil.journal.example.com is NOT journal.example.com.
        $this->assertFalse(CsrfGuard::originMatchesBase(
            'https://evil.journal.example.com',
            'https://journal.example.com'
        ));
    }

    public function testRejectsParentDomainAttack(): void
    {
        // Reversed: an attacker controlling example.com should not be
        // treated as the same origin as journal.example.com.
        $this->assertFalse(CsrfGuard::originMatchesBase(
            'https://example.com',
            'https://journal.example.com'
        ));
    }

    public function testRejectsSchemeDowngradeAttack(): void
    {
        // HTTP origin against HTTPS base — must fail.
        $this->assertFalse(CsrfGuard::originMatchesBase(
            'http://journal.example.com',
            'https://journal.example.com'
        ));
    }

    public function testRejectsDifferentPort(): void
    {
        // Same host, different port = different origin.
        $this->assertFalse(CsrfGuard::originMatchesBase(
            'http://localhost:9090',
            'http://localhost:8080'
        ));
    }

    public function testRejectsPortMismatchOnOneSide(): void
    {
        // Base has no explicit port, origin adds :8080 — not the same origin.
        $this->assertFalse(CsrfGuard::originMatchesBase(
            'http://localhost:8080',
            'http://localhost'
        ));
    }

    // ── Malformed / empty inputs ────────────────────────────────────

    public function testRejectsEmptyOrigin(): void
    {
        $this->assertFalse(CsrfGuard::originMatchesBase('', 'https://journal.example.com'));
    }

    public function testRejectsEmptyBase(): void
    {
        $this->assertFalse(CsrfGuard::originMatchesBase('https://journal.example.com', ''));
    }

    public function testRejectsWhitespaceOnlyOrigin(): void
    {
        $this->assertFalse(CsrfGuard::originMatchesBase('   ', 'https://journal.example.com'));
    }

    public function testRejectsOriginWithoutScheme(): void
    {
        $this->assertFalse(CsrfGuard::originMatchesBase(
            'journal.example.com',
            'https://journal.example.com'
        ));
    }

    public function testRejectsBaseWithoutScheme(): void
    {
        $this->assertFalse(CsrfGuard::originMatchesBase(
            'https://journal.example.com',
            'journal.example.com'
        ));
    }

    public function testRejectsMalformedOrigin(): void
    {
        $this->assertFalse(CsrfGuard::originMatchesBase(
            'not a url',
            'https://journal.example.com'
        ));
    }

    public function testRejectsNullByteInjection(): void
    {
        // Null-byte tricks shouldn't slip a bogus host through parse_url.
        $this->assertFalse(CsrfGuard::originMatchesBase(
            "https://journal.example.com\0.evil.com",
            'https://journal.example.com'
        ));
    }

    // ── Literal "null" Origin (forged cross-origin fetch) ───────────

    public function testRejectsLiteralNullOrigin(): void
    {
        // Browsers send "null" as Origin for sandboxed iframes, file://,
        // and some redirect scenarios — must not count as a match.
        $this->assertFalse(CsrfGuard::originMatchesBase(
            'null',
            'https://journal.example.com'
        ));
    }
}
