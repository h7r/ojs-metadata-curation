<?php

/**
 * P0 — SPARQL injection sanitization tests.
 *
 * Verifies that SparqlLookupManager::sanitizePrefix strips injection
 * vectors and that generated queries never contain unsanitised user input.
 */

namespace APP\plugins\generic\nvMetadataCuration\tests;

use APP\plugins\generic\nvMetadataCuration\classes\managers\SparqlLookupManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SparqlSanitizationTest extends TestCase
{
    private SparqlLookupManager $manager;
    private ReflectionMethod $sanitize;
    private ReflectionMethod $buildQuery;

    protected function setUp(): void
    {
        $this->manager = new SparqlLookupManager();
        $this->sanitize = new ReflectionMethod(SparqlLookupManager::class, 'sanitizePrefix');
        $this->sanitize->setAccessible(true);
        $this->buildQuery = new ReflectionMethod(SparqlLookupManager::class, 'buildQuery');
        $this->buildQuery->setAccessible(true);
    }

    // ── sanitizePrefix: character stripping ─────────────────────────

    public function testStripsAngleBrackets(): void
    {
        $result = $this->sanitize->invoke($this->manager, 'eco<script>nomie');
        $this->assertStringNotContainsString('<', $result);
        $this->assertStringNotContainsString('>', $result);
    }

    public function testStripsDoubleQuotes(): void
    {
        $result = $this->sanitize->invoke($this->manager, 'eco"nomie');
        $this->assertStringNotContainsString('"', $result);
    }

    public function testStripsBackslash(): void
    {
        $result = $this->sanitize->invoke($this->manager, 'eco\\nomie');
        $this->assertStringNotContainsString('\\', $result);
    }

    public function testStripsCurlyBraces(): void
    {
        $result = $this->sanitize->invoke($this->manager, 'eco{nomie}');
        $this->assertStringNotContainsString('{', $result);
        $this->assertStringNotContainsString('}', $result);
    }

    public function testStripsPipe(): void
    {
        $result = $this->sanitize->invoke($this->manager, 'eco|nomie');
        $this->assertStringNotContainsString('|', $result);
    }

    public function testStripsSquareBrackets(): void
    {
        $result = $this->sanitize->invoke($this->manager, 'eco[nomie]');
        $this->assertStringNotContainsString('[', $result);
        $this->assertStringNotContainsString(']', $result);
    }

    public function testStripsCaret(): void
    {
        $result = $this->sanitize->invoke($this->manager, 'eco^nomie');
        $this->assertStringNotContainsString('^', $result);
    }

    public function testStripsBacktick(): void
    {
        $result = $this->sanitize->invoke($this->manager, 'eco`nomie');
        $this->assertStringNotContainsString('`', $result);
    }

    // ── sanitizePrefix: normalization ───────────────────────────────

    public function testLowercases(): void
    {
        $result = $this->sanitize->invoke($this->manager, 'ECONOMIE');
        $this->assertSame('economie', $result);
    }

    public function testTrims(): void
    {
        $result = $this->sanitize->invoke($this->manager, '  economie  ');
        $this->assertSame('economie', $result);
    }

    public function testTruncatesAt50Chars(): void
    {
        $long = str_repeat('a', 100);
        $result = $this->sanitize->invoke($this->manager, $long);
        $this->assertSame(50, mb_strlen($result, 'UTF-8'));
    }

    // ── sanitizePrefix: preserves safe characters ───────────────────

    public function testPreservesAccentedCharacters(): void
    {
        $result = $this->sanitize->invoke($this->manager, 'économie');
        $this->assertSame('économie', $result);
    }

    public function testPreservesHyphensAndSpaces(): void
    {
        $result = $this->sanitize->invoke($this->manager, 'sciences de la terre');
        $this->assertSame('sciences de la terre', $result);
    }

    // ── sanitizePrefix: minimum length gate ─────────────────────────

    public function testRejectsShortPrefix(): void
    {
        $result = $this->manager->suggest('ab', 'fr', 'unesco');
        $this->assertEmpty($result['results']);
        $this->assertFalse($result['fallback']);
    }

    public function testRejectsEmptyAfterSanitization(): void
    {
        // All dangerous chars stripped → empty string → below minimum
        $result = $this->manager->suggest('<>"{}', 'fr', 'unesco');
        $this->assertEmpty($result['results']);
    }

    // ── SPARQL injection vectors via buildQuery ─────────────────────

    /**
     * Classic SPARQL injection: break out of string literal with double quote.
     * The sanitizer must strip " so the FILTER cannot be escaped.
     * The payload ends up as inert text inside the SPARQL string literal.
     */
    public function testInjectionDoubleQuoteBreakout(): void
    {
        $malicious = 'eco") . ?x <http://evil> ?y } #';
        $sanitized = $this->sanitize->invoke($this->manager, $malicious);

        // Critical: angle brackets and double quotes stripped → no URI breakout
        $this->assertStringNotContainsString('"', $sanitized);
        $this->assertStringNotContainsString('<', $sanitized);
        $this->assertStringNotContainsString('>', $sanitized);

        // The sanitized prefix stays trapped inside the SPARQL string literal
        $query = $this->buildQuery->invoke($this->manager, $sanitized, 'fr', 'unesco');
        // No SPARQL URI pattern (<...>) injected
        $this->assertStringNotContainsString('<http://evil>', $query);
    }

    /**
     * Backslash injection: try to escape the closing quote.
     */
    public function testInjectionBackslashEscape(): void
    {
        $malicious = 'eco\\")} . ?x <http://evil> ?y #';
        $sanitized = $this->sanitize->invoke($this->manager, $malicious);

        // Backslash and structural chars are stripped
        $this->assertStringNotContainsString('\\', $sanitized);
        $this->assertStringNotContainsString('"', $sanitized);
        $this->assertStringNotContainsString('<', $sanitized);

        $query = $this->buildQuery->invoke($this->manager, $sanitized, 'es', 'unesco');
        $this->assertStringNotContainsString('<http://evil>', $query);
    }

    /**
     * Rameau bif:contains injection: try to break out of the "\"...*\"" pattern.
     * The sanitizer strips " and \ so the bif:contains string cannot be escaped.
     */
    public function testInjectionRameauBifContains(): void
    {
        $malicious = 'eco*" . ?s <http://evil> ?o } #';
        $sanitized = $this->sanitize->invoke($this->manager, $malicious);

        $this->assertStringNotContainsString('"', $sanitized);
        $this->assertStringNotContainsString('<', $sanitized);

        $query = $this->buildQuery->invoke($this->manager, $sanitized, 'fr', 'rameau');
        // No SPARQL URI breakout — angle brackets are gone
        $this->assertStringNotContainsString('<http://evil>', $query);
    }

    /**
     * Eurovoc injection: angle brackets stripped prevents URI injection.
     */
    public function testInjectionEurovoc(): void
    {
        $malicious = 'eco<http://evil.com>';
        $sanitized = $this->sanitize->invoke($this->manager, $malicious);

        // Angle brackets stripped: no URI breakout possible
        $this->assertStringNotContainsString('<', $sanitized);
        $this->assertStringNotContainsString('>', $sanitized);

        $query = $this->buildQuery->invoke($this->manager, $sanitized, 'fr', 'eurovoc');
        $this->assertStringNotContainsString('<http://evil.com>', $query);
    }

    /**
     * Single quote injection: try to break SPARQL string literal
     * (single-quoted strings in SPARQL).
     */
    public function testSingleQuoteEscaping(): void
    {
        // Single quotes are NOT stripped by sanitizePrefix but are escaped in buildQuery
        $input = "l'économie";
        $sanitized = $this->sanitize->invoke($this->manager, $input);
        $query = $this->buildQuery->invoke($this->manager, $sanitized, 'fr', 'unesco');

        // The query should contain the escaped version
        $this->assertStringContainsString("\\'", $query);
    }

    // ── Unknown thesaurus fallback ──────────────────────────────────

    public function testUnknownThesaurusReturnsFallback(): void
    {
        $result = $this->manager->suggest('economie', 'fr', 'wikidata');
        $this->assertTrue($result['fallback']);
        $this->assertSame('unknown_thesaurus', $result['fallback_reason']);
        $this->assertEmpty($result['results']);
    }

    // ── $lang defense-in-depth: verify whitelisted values produce valid SPARQL ──

    /**
     * @dataProvider validLangProvider
     */
    public function testBuildQueryWithValidLang(string $lang): void
    {
        $query = $this->buildQuery->invoke($this->manager, 'economie', $lang, 'unesco');
        // Should produce a valid-looking SPARQL with the lang embedded
        $this->assertStringContainsString("lang(?labelPrimary) = \"{$lang}\"", $query);
    }

    public static function validLangProvider(): array
    {
        return [
            'spanish' => ['es'],
            'french'  => ['fr'],
            'english' => ['en'],
        ];
    }

    /**
     * Defense-in-depth: if $lang were ever injected (bypassing handler whitelist),
     * it would be interpolated raw. This test documents the risk.
     * The manager trusts its caller — this test proves the injection IS possible
     * at the manager level, reinforcing that the handler MUST whitelist.
     */
    public function testLangInjectionReachesQueryIfNotWhitelisted(): void
    {
        $malicious = 'fr") . ?x <http://evil> ?y } #';
        $query = $this->buildQuery->invoke($this->manager, 'economie', $malicious, 'unesco');

        // This WILL contain the injected payload — proving defense-in-depth gap
        $this->assertStringContainsString('http://evil', $query,
            'Defense-in-depth gap: $lang is interpolated raw in SparqlLookupManager. '
            . 'The handler whitelist is the only barrier.'
        );
    }
}
