<?php

/**
 * AiScan tests. Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>.
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

namespace FacturaScripts\Plugins\AiScan\Lib\Provider;

use FacturaScripts\Test\Plugins\ProviderTransportTest;

// Replace only the transport boundary; the actual providers build and parse their payloads.
function curl_init(string $url): object
{
    return (object) ['url' => $url, 'options' => []];
}

function curl_setopt_array(object $handle, array $options): bool
{
    $handle->options = $options;
    ProviderTransportTest::$request = $handle;
    return true;
}

function curl_exec(object $handle)
{
    if (null === ProviderTransportTest::$response) {
        throw new \RuntimeException('Unexpected provider request in tests: ' . $handle->url);
    }
    return ProviderTransportTest::$response;
}

function curl_error(object $handle): string
{
    return ProviderTransportTest::$error;
}

function curl_getinfo(object $handle, int $option): int
{
    return ProviderTransportTest::$status;
}

function curl_close(object $handle): void
{
}

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AiScan\Lib\ExtractionService;
use FacturaScripts\Plugins\AiScan\Lib\Provider\GeminiProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\GrokProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\MistralProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\MockProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\OpenAICompatibleProvider;
use FacturaScripts\Plugins\AiScan\Lib\Provider\OpenAIProvider;
use PHPUnit\Framework\TestCase;

final class ProviderTransportTest extends TestCase
{
    public static ?object $request = null;
    public static ?string $response = null;
    public static string $error = '';
    public static int $status = 200;

    protected function tearDown(): void
    {
        self::$response = null;
        self::$request = null;
        self::$error = '';
        self::$status = 200;
        Tools::settingsClear();
    }

    public function testProvidersSerializeDocumentsAndPreserveTransportSecurity(): void
    {
        self::$response = json_encode([
            'choices' => [['message' => ['content' => '{"ok":true}']]],
            'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
        ]);
        Tools::settingsSet('AiScan', 'custom_base_url', 'https://example.invalid/v1/');
        foreach ($this->providers() as $class) {
            foreach (['text/plain', 'image/png', 'application/pdf'] as $mime) {
                foreach (['', 'Extract invoice JSON'] as $system) {
                    $provider = new $class();
                    $answer = $provider->analyzeDocument('fixture-content', $mime, 'Read this invoice', $system);
                    self::assertSame('{"ok":true}', $answer);
                    $options = self::$request->options;
                    self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
                    self::assertTrue($options[CURLOPT_POST]);
                    self::assertTrue($options[CURLOPT_RETURNTRANSFER]);
                    self::assertGreaterThan(0, $options[CURLOPT_TIMEOUT]);
                    $payload = json_decode($options[CURLOPT_POSTFIELDS], true, 512, JSON_THROW_ON_ERROR);
                    self::assertStringContainsString('fixture-content', $options[CURLOPT_POSTFIELDS]);
                    self::assertStringContainsString('Read this invoice', $options[CURLOPT_POSTFIELDS]);
                    if ($provider instanceof GeminiProvider) {
                        self::assertSame($system !== '', isset($payload['systemInstruction']));
                        self::assertSame('application/json', $payload['generationConfig']['responseMimeType']);
                    } else {
                        self::assertSame($system === '' ? 'user' : 'system', $payload['messages'][0]['role']);
                        self::assertStringEndsWith('/chat/completions', self::$request->url);
                    }
                }
            }
        }
    }

    public function testExtractionNormalizesSingleAndMultipleInvoicesAndRejectsInvalidJson(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'aiscan-extraction-');
        file_put_contents($file, 'Invoice document');
        Tools::settingsSet('AiScan', 'openai_api_key', 'test-key-not-real');
        $service = new ExtractionService();
        $data = ['invoice' => ['number' => 'TEST-1', 'issue_date' => '2026-06-01', 'total' => 100],
            'supplier' => ['name' => 'Test supplier'],
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]]];
        try {
            foreach (['text/plain', 'image/png', 'application/pdf'] as $mime) {
                self::$response = json_encode(['choices' => [['message' => ['content' => json_encode($data)]]]]);
                $result = $service->extractFromFile($file, $mime, 'openai', 'lines', 'Previous invoice context');
                self::assertSame('TEST-1', $result['invoice']['number']);
                self::assertSame('openai', $result['_provider']);
                self::assertArrayHasKey('_validation_errors', $result);
                self::assertStringContainsString(
                    'Previous invoice context',
                    self::$request->options[CURLOPT_POSTFIELDS]
                );
            }
            $responses = ["```json\n" . json_encode($data) . "\n```", "\x01" . json_encode($data)];
            foreach ($responses as $json) {
                self::$response = json_encode(['choices' => [['message' => ['content' => $json]]]]);
                $result = $service->extractFromFile($file, 'text/plain', 'openai', 'total');
                self::assertSame('TEST-1', $result['invoice']['number']);
            }
            self::$response = json_encode(['choices' => [['message' => [
                'content' => json_encode(['invoices' => [$data, $data]]),
            ]]]]);
            $result = $service->extractFromFile($file, 'text/plain', 'openai');
            self::assertTrue($result['_multi_invoice']);
            self::assertCount(2, $result['invoices']);
            foreach ($result['invoices'] as $invoice) {
                self::assertSame('openai', $invoice['_provider']);
                self::assertSame('TEST-1', $invoice['invoice']['number']);
            }
            self::$response = json_encode(['choices' => [['message' => ['content' => 'not JSON']]]]);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid JSON response');
            $service->extractFromFile($file, 'text/plain', 'openai');
        } finally {
            unlink($file);
            Tools::log('AiScan')->clear();
        }
    }

    public function testMockProviderResolvesPromptFilenamesAndFallsBackToRecordedData(): void
    {
        $provider = new MockProvider();
        $provider->setForcedFixture('absent');
        $prompts = ['File: F-2024-011.pdf', 'filename="F-2024-011.pdf"', 'Read F-2024-011.pdf',
            'No filename is supplied'];
        foreach ($prompts as $prompt) {
            $json = $provider->analyzeDocument('', 'application/pdf', $prompt);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::assertNotEmpty($data['invoice']['number']);
        }
    }

    public function testProvidersSurfaceTransportAndHttpErrorsAndHandleEmptyResponses(): void
    {
        foreach ($this->providers() as $class) {
            $provider = new $class();
            self::$response = '{}';
            self::assertSame('', $provider->analyzeDocument('text', 'text/plain', 'Read'));
            foreach (['timeout', 'http'] as $failure) {
                self::$error = $failure === 'timeout' ? 'Connection timed out' : '';
                self::$status = $failure === 'http' ? 429 : 200;
                try {
                    $provider->analyzeDocument('text', 'text/plain', 'Read');
                    self::fail('Provider errors must not be treated as successful extraction');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString(
                        $failure === 'http' ? '429' : 'Connection timed out',
                        $exception->getMessage()
                    );
                }
            }
            self::$error = '';
            self::$status = 200;
        }
        self::$response = 'not-json';
        self::assertSame('', (new GeminiProvider())->analyzeDocument('text', 'text/plain', 'Read'));
        self::assertSame('', GeminiProvider::extractResponseText(['candidates' => [['content' => ['parts' => null]]]]));
    }

    private function providers(): array
    {
        return [OpenAIProvider::class, GeminiProvider::class, MistralProvider::class,
            GrokProvider::class, OpenAICompatibleProvider::class];
    }
}
