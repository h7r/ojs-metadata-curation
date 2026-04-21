<?php

/**
 * P0 — HTTP status code verification tests (B2 fix).
 *
 * Verifies that isHttpSuccess() correctly identifies 2xx vs non-2xx
 * status lines in both SparqlLookupManager and OrcidRorManager.
 */

namespace APP\plugins\generic\nvMetadataCuration\tests;

use APP\plugins\generic\nvMetadataCuration\classes\managers\OrcidRorManager;
use APP\plugins\generic\nvMetadataCuration\classes\managers\SparqlLookupManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class HttpStatusCheckTest extends TestCase
{
    private ReflectionMethod $sparqlCheck;
    private ReflectionMethod $orcidCheck;

    protected function setUp(): void
    {
        $this->sparqlCheck = new ReflectionMethod(SparqlLookupManager::class, 'isHttpSuccess');
        $this->sparqlCheck->setAccessible(true);
        $this->orcidCheck = new ReflectionMethod(OrcidRorManager::class, 'isHttpSuccess');
        $this->orcidCheck->setAccessible(true);
    }

    // ── SparqlLookupManager::isHttpSuccess ──────────────────────────

    public function testSparql200Ok(): void
    {
        $this->assertTrue($this->sparqlCheck->invoke(null, ['HTTP/1.1 200 OK']));
    }

    public function testSparql201Created(): void
    {
        $this->assertTrue($this->sparqlCheck->invoke(null, ['HTTP/1.1 201 Created']));
    }

    public function testSparql204NoContent(): void
    {
        $this->assertTrue($this->sparqlCheck->invoke(null, ['HTTP/1.1 204 No Content']));
    }

    public function testSparql400BadRequest(): void
    {
        $this->assertFalse($this->sparqlCheck->invoke(null, ['HTTP/1.1 400 Bad Request']));
    }

    public function testSparql403Forbidden(): void
    {
        $this->assertFalse($this->sparqlCheck->invoke(null, ['HTTP/1.1 403 Forbidden']));
    }

    public function testSparql404NotFound(): void
    {
        $this->assertFalse($this->sparqlCheck->invoke(null, ['HTTP/1.1 404 Not Found']));
    }

    public function testSparql429TooManyRequests(): void
    {
        $this->assertFalse($this->sparqlCheck->invoke(null, ['HTTP/1.1 429 Too Many Requests']));
    }

    public function testSparql500InternalError(): void
    {
        $this->assertFalse($this->sparqlCheck->invoke(null, ['HTTP/1.1 500 Internal Server Error']));
    }

    public function testSparql503ServiceUnavailable(): void
    {
        $this->assertFalse($this->sparqlCheck->invoke(null, ['HTTP/1.1 503 Service Unavailable']));
    }

    public function testSparqlEmptyHeaders(): void
    {
        $this->assertFalse($this->sparqlCheck->invoke(null, []));
    }

    public function testSparqlEmptyFirstHeader(): void
    {
        $this->assertFalse($this->sparqlCheck->invoke(null, ['']));
    }

    public function testSparqlHttp2Status(): void
    {
        $this->assertTrue($this->sparqlCheck->invoke(null, ['HTTP/2 200 OK']));
    }

    public function testSparqlHttp2Error(): void
    {
        $this->assertFalse($this->sparqlCheck->invoke(null, ['HTTP/2 502 Bad Gateway']));
    }

    // ── OrcidRorManager::isHttpSuccess ──────────────────────────────

    public function testOrcid200Ok(): void
    {
        $this->assertTrue($this->orcidCheck->invoke(null, ['HTTP/1.1 200 OK']));
    }

    public function testOrcid429RateLimit(): void
    {
        $this->assertFalse($this->orcidCheck->invoke(null, ['HTTP/1.1 429 Too Many Requests']));
    }

    public function testOrcid500Error(): void
    {
        $this->assertFalse($this->orcidCheck->invoke(null, ['HTTP/1.1 500 Internal Server Error']));
    }

    public function testOrcid503Unavailable(): void
    {
        $this->assertFalse($this->orcidCheck->invoke(null, ['HTTP/1.1 503 Service Unavailable']));
    }

    public function testOrcidEmptyHeaders(): void
    {
        $this->assertFalse($this->orcidCheck->invoke(null, []));
    }

    // ── Redirect chain tests (P1 fix) ──────────────────────────────

    public function testSparqlRedirect301Then200(): void
    {
        $headers = [
            'HTTP/1.1 301 Moved Permanently',
            'Location: https://example.com/new',
            'HTTP/1.1 200 OK',
            'Content-Type: application/json',
        ];
        $this->assertTrue($this->sparqlCheck->invoke(null, $headers));
    }

    public function testSparqlRedirect302Then200(): void
    {
        $headers = [
            'HTTP/1.1 302 Found',
            'Location: https://example.com/other',
            'HTTP/1.1 200 OK',
        ];
        $this->assertTrue($this->sparqlCheck->invoke(null, $headers));
    }

    public function testSparqlDoubleRedirectThen200(): void
    {
        $headers = [
            'HTTP/1.1 301 Moved Permanently',
            'Location: https://example.com/a',
            'HTTP/1.1 302 Found',
            'Location: https://example.com/b',
            'HTTP/1.1 200 OK',
            'Content-Type: text/html',
        ];
        $this->assertTrue($this->sparqlCheck->invoke(null, $headers));
    }

    public function testSparqlRedirectThen404(): void
    {
        $headers = [
            'HTTP/1.1 301 Moved Permanently',
            'Location: https://example.com/gone',
            'HTTP/1.1 404 Not Found',
        ];
        $this->assertFalse($this->sparqlCheck->invoke(null, $headers));
    }

    public function testOrcidRedirect301Then200(): void
    {
        $headers = [
            'HTTP/1.1 301 Moved Permanently',
            'Location: https://pub.orcid.org/v3.0/0000-0001-2345-6789',
            'HTTP/1.1 200 OK',
            'Content-Type: application/json',
        ];
        $this->assertTrue($this->orcidCheck->invoke(null, $headers));
    }

    public function testOrcidRedirectThen500(): void
    {
        $headers = [
            'HTTP/1.1 302 Found',
            'Location: https://pub.orcid.org/error',
            'HTTP/1.1 500 Internal Server Error',
        ];
        $this->assertFalse($this->orcidCheck->invoke(null, $headers));
    }
}
