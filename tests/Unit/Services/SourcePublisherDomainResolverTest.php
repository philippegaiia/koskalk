<?php

use App\Services\IngredientEnrichment\SourcePublisherDomainResolver;
use Illuminate\Support\Facades\Http;
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

it('resolves against the local snapshot without making network requests', function (): void {
    Http::preventStrayRequests();

    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve('https://docs.supplier.com/path'))->toBe('supplier.com');

    Http::assertNothingSent();
});
