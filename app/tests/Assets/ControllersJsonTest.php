<?php

declare(strict_types=1);

namespace Pablo\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Tripwire for assets/controllers.json.
 *
 * StimulusBundle's ControllersMapGenerator::loadUxControllers() iterates
 * $json['controllers'] as packageName => controllers. If that key is an empty
 * array (or the package entry goes missing) the loop body never runs, the
 * `live` Stimulus controller is never registered, and *every* Live Component
 * on the dashboard silently stops working in the browser — polling, actions,
 * the lot. Nothing fails server-side, so no HTTP-level test can catch it:
 * `curl` on the component endpoints keeps returning perfectly good HTML.
 *
 * That exact bug shipped once. This test exists so it cannot ship twice.
 */
final class ControllersJsonTest extends TestCase
{
    /** @return array<string, mixed> */
    private function json(): array
    {
        $path = \dirname(__DIR__, 2).'/assets/controllers.json';
        $this->assertFileExists($path, 'StimulusBundle errors at container build without this file');

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testControllersIsAnObjectNotAnEmptyArray(): void
    {
        $controllers = $this->json()['controllers'] ?? null;

        $this->assertIsArray($controllers);
        $this->assertNotSame(
            [],
            $controllers,
            'controllers.json declares no UX controllers: every Live Component will be inert in the browser',
        );
        $this->assertNotSame(
            array_keys($controllers),
            range(0, \count($controllers) - 1),
            'controllers must be a JSON object keyed by package name, not a list',
        );
    }

    public function testLiveComponentControllerIsRegisteredAndEnabled(): void
    {
        $controllers = $this->json()['controllers'];
        \assert(\is_array($controllers));

        $this->assertArrayHasKey('@symfony/ux-live-component', $controllers);
        $live = $controllers['@symfony/ux-live-component']['live'] ?? null;

        $this->assertIsArray($live, 'the "live" controller must be declared');
        $this->assertTrue($live['enabled'] ?? false, 'the "live" controller must be enabled');
    }

    /** live.min.css styles the busy/loading states data-loading relies on. */
    public function testLiveControllerAutoimportsItsStylesheet(): void
    {
        $live = $this->json()['controllers']['@symfony/ux-live-component']['live'];

        $this->assertTrue(
            $live['autoimport']['@symfony/ux-live-component/dist/live.min.css'] ?? false,
        );
    }

    /** The declared controller must actually exist in the installed package. */
    public function testDeclaredControllerExistsInTheInstalledPackage(): void
    {
        $meta = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 2).'/vendor/symfony/ux-live-component/assets/package.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        $this->assertArrayHasKey('live', $meta['symfony']['controllers'] ?? []);
    }
}
