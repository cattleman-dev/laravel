<?php

namespace Cattleman\Laravel\Tests\Unit;

use Cattleman\Laravel\Support\EnvFile;
use PHPUnit\Framework\TestCase;

class EnvFileTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($this->file, "# App\nAPP_NAME=\"My App\"\nBROADCAST_CONNECTION=log # for now\n\nVITE_APP_NAME=\"\${APP_NAME}\"\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReadsValuesWithQuotesAndInlineComments(): void
    {
        $env = new EnvFile($this->file);

        $this->assertSame('My App', $env->get('APP_NAME'));
        $this->assertSame('log', $env->get('BROADCAST_CONNECTION'));
        $this->assertSame('${APP_NAME}', $env->get('VITE_APP_NAME'));
        $this->assertNull($env->get('MISSING'));
    }

    public function testUpdatesInPlaceAndAppendsNewKeysAsABlock(): void
    {
        $env = new EnvFile($this->file);

        $result = $env->write(['BROADCAST_CONNECTION' => 'cattleman', 'CATTLEMAN_HOST' => 'x.workers.dev', 'CATTLEMAN_APP_SECRET' => 'has space'], overwrite: true);

        $this->assertSame(['BROADCAST_CONNECTION' => 'updated', 'CATTLEMAN_HOST' => 'added', 'CATTLEMAN_APP_SECRET' => 'added'], $result);
        $this->assertSame(
            "# App\nAPP_NAME=\"My App\"\nBROADCAST_CONNECTION=cattleman\n\nVITE_APP_NAME=\"\${APP_NAME}\"\n\nCATTLEMAN_HOST=x.workers.dev\nCATTLEMAN_APP_SECRET=\"has space\"\n",
            file_get_contents($this->file),
        );
    }

    public function testExistingValuesAreKeptUnlessOverwriteAllows(): void
    {
        $env = new EnvFile($this->file);

        $this->assertSame(['APP_NAME' => 'kept'], $env->write(['APP_NAME' => 'Other']));
        $this->assertSame(['APP_NAME' => 'unchanged'], $env->write(['APP_NAME' => 'My App']));
        $this->assertSame(['APP_NAME' => 'updated'], $env->write(['APP_NAME' => 'Other'], fn (string $key) => $key === 'APP_NAME'));
        $this->assertSame('Other', $env->get('APP_NAME'));
    }

    public function testAnUnchangedFileIsNotRewritten(): void
    {
        $before = file_get_contents($this->file);
        clearstatcache();
        $mtime = filemtime($this->file);

        (new EnvFile($this->file))->write(['APP_NAME' => 'My App']);

        $this->assertSame($before, file_get_contents($this->file));
        $this->assertSame($mtime, filemtime($this->file));
    }
}
