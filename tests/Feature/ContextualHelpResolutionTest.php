<?php

use App\Actions\ContextualHelp\PublishHelpTopic;
use App\Actions\ContextualHelp\SaveHelpTopicDraft;
use App\Actions\ContextualHelp\WithdrawHelpTopic;
use App\Data\HelpContentInput;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\User;
use App\Services\ContextualHelp\HelpTopicResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('falls back as a complete topic only when the published translation is stale', function () {
    $topic = HelpTopic::factory()->create(['key' => 'soap.water_mode']);
    $en = HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $fr = HelpTopicLocale::factory()->for($topic, 'topic')->translated()->create();
    $admin = User::factory()->admin()->create();
    $save = app(SaveHelpTopicDraft::class);
    $publish = app(PublishHelpTopic::class);
    $resolver = app(HelpTopicResolver::class);
    $first = $save->handle($admin, $en, new HelpContentInput('Water mode', 'Published English.', null), 0);
    $publish->handle($admin, $en->refresh(), $first->id, 1);
    $translated = $save->handle($admin, $fr, new HelpContentInput('Mode eau', 'Texte traduit.', null), 0, $first->id);
    $publish->handle($admin, $fr->refresh(), $translated->id, 1);
    $second = $save->handle($admin, $en->refresh(), new HelpContentInput('New water mode', 'New English.', null), 2);
    expect($resolver->resolve([$topic->key], 'fr')[$topic->key]['summary'])->toBe('Texte traduit.');
    $publish->handle($admin, $en->refresh(), $second->id, 3);
    $result = $resolver->resolve([$topic->key], 'fr')[$topic->key];
    expect($result['locale'])->toBe('en')->and($result['title'])->toBe('New water mode')->and($result['summary'])->toBe('New English.');
    app(WithdrawHelpTopic::class)->handle($admin, $en->refresh(), 4);
    expect($resolver->resolve([$topic->key], 'fr'))->toBe([]);
});

it('omits unknown unpublished and archived topics', function () {
    $topic = HelpTopic::factory()->create(['key' => 'soap.water_mode']);
    $locale = HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $admin = User::factory()->admin()->create();
    $revision = app(SaveHelpTopicDraft::class)->handle($admin, $locale, new HelpContentInput('Water', 'Private draft.', null), 0);
    $resolver = app(HelpTopicResolver::class);
    expect($resolver->resolve([$topic->key, 'unregistered.topic'], 'en'))->toBe([]);
    app(PublishHelpTopic::class)->handle($admin, $locale->refresh(), $revision->id, 1);
    $topic->update(['archived_at' => now()]);
    expect($resolver->resolve([$topic->key], 'en'))->toBe([]);
});
