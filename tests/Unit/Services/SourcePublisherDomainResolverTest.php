<?php

use App\Services\IngredientEnrichment\SourcePublisherDomainResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

it('collapses sibling subdomains to one publisher domain', function (): void {
    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve('https://docs.supplier.com/path'))->toBe('supplier.com')
        ->and($resolver->resolve('https://shop.supplier.com/other'))->toBe('supplier.com');
});

it('handles multi-label public suffixes without collapsing unrelated publishers', function (): void {
    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve('https://docs.supplier-a.co.uk/path'))->toBe('supplier-a.co.uk')
        ->and($resolver->resolve('https://shop.supplier-b.co.uk/path'))->toBe('supplier-b.co.uk');
});

it('keeps private-suffix sites as separate publisher domains', function (): void {
    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve('https://docs.project-a.github.io/path'))->toBe('project-a.github.io')
        ->and($resolver->resolve('https://shop.project-b.github.io/other'))->toBe('project-b.github.io');
});

it('canonicalizes uppercase hosts and trailing root labels', function (): void {
    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve('HTTPS://Docs.Supplier.COM./path'))->toBe('supplier.com');
});

it('normalizes internationalized publisher domains to IDNA ASCII', function (): void {
    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve('https://docs.münchen.de/path'))->toBe('xn--mnchen-3ya.de');
});

it('fails closed for malformed urls and unknown suffixes', function (): void {
    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve('not-a-url'))->toBeNull()
        ->and($resolver->resolve('https://localhost/path'))->toBeNull()
        ->and($resolver->resolve('https://github.io/path'))->toBeNull();
});

it('handles URL authority variants without accepting network or numeric hosts', function (string $url, ?string $expected): void {
    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve($url))->toBe($expected);
})->with([
    'userinfo and explicit port' => ['https://user:secret@docs.supplier.com:8443/path', 'supplier.com'],
    'ordinary HTTPS port' => ['https://docs.supplier.com:443/path', 'supplier.com'],
    'schemeless hostname' => ['docs.supplier.com/path', null],
    'network path reference' => ['//docs.supplier.com/path', null],
    'IPv4 address' => ['https://192.0.2.1/path', null],
    'IPv6 address' => ['https://[2001:db8::1]/path', null],
    'decimal IPv4 form' => ['https://2130706433/path', null],
    'hexadecimal IPv4 form' => ['https://0x7f000001/path', null],
    'octal IPv4 form' => ['https://017700000001/path', null],
]);

it('does not log ordinary malformed or untrusted URL inputs', function (): void {
    Log::spy();

    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve('not-a-url'))->toBeNull()
        ->and($resolver->resolve('//docs.supplier.com/path'))->toBeNull()
        ->and($resolver->resolve('https://localhost/path'))->toBeNull();

    Log::shouldNotHaveReceived('warning');
});

it('fails closed when the local public suffix data is missing or corrupt', function (): void {
    $missingPath = tempnam(sys_get_temp_dir(), 'missing-psl-');
    if ($missingPath === false) {
        throw new RuntimeException('Could not create a temporary path.');
    }

    unlink($missingPath);

    $corruptPath = tempnam(sys_get_temp_dir(), 'corrupt-psl-');
    if ($corruptPath === false) {
        throw new RuntimeException('Could not create a temporary path.');
    }

    file_put_contents($corruptPath, 'not a public suffix list');

    try {
        $missingResolver = app()->makeWith(SourcePublisherDomainResolver::class, [
            'rulesPath' => $missingPath,
        ]);
        $corruptResolver = app()->makeWith(SourcePublisherDomainResolver::class, [
            'rulesPath' => $corruptPath,
        ]);

        expect($missingResolver->resolve('https://docs.supplier.com/path'))->toBeNull()
            ->and($corruptResolver->resolve('https://docs.supplier.com/path'))->toBeNull();
    } finally {
        unlink($corruptPath);
    }
});

it('fails closed for a structurally valid but truncated public suffix snapshot', function (): void {
    $truncatedPath = tempnam(sys_get_temp_dir(), 'truncated-psl-');
    if ($truncatedPath === false) {
        throw new RuntimeException('Could not create a temporary path.');
    }

    file_put_contents($truncatedPath, <<<'PSL'
// ===BEGIN ICANN DOMAINS===
com
io
// ===END ICANN DOMAINS===
PSL
    );

    try {
        $resolver = app()->makeWith(SourcePublisherDomainResolver::class, [
            'rulesPath' => $truncatedPath,
        ]);

        expect($resolver->resolve('https://docs.supplier.com/path'))->toBeNull()
            ->and($resolver->resolve('https://docs.project-a.github.io/path'))->toBeNull()
            ->and($resolver->resolve('https://docs.project-b.github.io/path'))->toBeNull();
    } finally {
        unlink($truncatedPath);
    }
});

it('fails closed when the committed public suffix snapshot is modified', function (): void {
    $snapshot = file_get_contents(resource_path('data/public-suffix-list.dat'));
    if (! is_string($snapshot)) {
        throw new RuntimeException('Could not read the public suffix snapshot.');
    }

    $modifiedPath = tempnam(sys_get_temp_dir(), 'modified-psl-');
    if ($modifiedPath === false) {
        throw new RuntimeException('Could not create a temporary path.');
    }

    file_put_contents($modifiedPath, $snapshot."\n// modified snapshot\n");

    try {
        $resolver = app()->makeWith(SourcePublisherDomainResolver::class, [
            'rulesPath' => $modifiedPath,
        ]);

        expect($resolver->resolve('https://docs.supplier.com/path'))->toBeNull()
            ->and($resolver->resolve('https://docs.project-a.github.io/path'))->toBeNull();
    } finally {
        unlink($modifiedPath);
    }
});

it('resolves against the local snapshot without making network requests', function (): void {
    Http::preventStrayRequests();

    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve('https://docs.supplier.com/path'))->toBe('supplier.com');

    Http::assertNothingSent();
});
