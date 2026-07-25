<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProblemDetailsTest extends WebTestCase
{
    public function testUnknownApiRouteReturnsAProblemDocument(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/route-inexistante');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        /** @var array<string, mixed> $problem */
        $problem = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('/problems/resource-not-found', $problem['type']);
        self::assertSame(404, $problem['status']);
        self::assertSame('/api/route-inexistante', $problem['instance']);
        self::assertIsString($problem['title']);
        self::assertIsString($problem['correlation_id']);
        self::assertNotSame('', $problem['correlation_id']);
    }

    public function testInternalErrorNeverLeaksTheExceptionMessage(): void
    {
        $client = static::createClient();
        $client->catchExceptions(true);
        $client->request('GET', '/api/_test/boom');

        self::assertResponseStatusCodeSame(500);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        $body = (string) $client->getResponse()->getContent();

        // Sur la chaine brute : rien du message d'exception ne doit apparaitre,
        // ou que ce soit dans le document.
        self::assertStringNotContainsString('secret-interne', $body);

        /** @var array<string, mixed> $problem */
        $problem = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('/problems/internal-error', $problem['type']);
        self::assertSame('Une erreur interne est survenue.', $problem['detail']);
    }

    /** Le meme identifiant doit relier la reponse d'erreur et les lignes de log. */
    public function testTheCorrelationIdIsAlsoExposedAsAResponseHeader(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/ping');

        $header = $client->getResponse()->headers->get('X-Correlation-Id');

        self::assertIsString($header);
        self::assertNotSame('', $header);
    }

    /** Hors de /api, Symfony garde son comportement natif : pas de Problem Details. */
    public function testNonApiRoutesAreLeftAlone(): void
    {
        $client = static::createClient();
        $client->request('GET', '/une-page-quelconque');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
    }
}
