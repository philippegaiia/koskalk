<?php

use App\Enums\WorkspaceMemberRole;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Notifications\WorkspaceMemberInvitation;
use App\Services\WorkspaceInvitationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

it('reserves only one final seat when two real database sessions invite concurrently', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires an explicitly permitted disposable PostgreSQL database and pcntl.');
    }
    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    config(['workspaces.collaboration_enabled' => true]);
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($owner)->for($plan)->create();
    PlanLimit::factory()->for($plan)->create(['key' => 'workspace_members', 'value' => 2]);
    DB::disconnect();
    [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the competing invitation session.');
    }
    if ($pid === 0) {
        fclose($parentSocket);
        stream_set_timeout($childSocket, 15);
        try {
            DB::reconnect();
            DB::statement("SET statement_timeout = '12s'");
            fwrite($childSocket, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n");
            if (trim((string) fgets($childSocket)) !== 'go') {
                throw new RuntimeException('Parent did not release the competing session.');
            }
            app(WorkspaceInvitationService::class)->issue($owner, $workspace, 'loser@example.test', WorkspaceMemberRole::Editor);
            $result = ['status' => 'saved'];
        } catch (ValidationException $exception) {
            $result = ['status' => 'validation', 'fields' => array_keys($exception->errors())];
        } catch (Throwable $exception) {
            $result = ['status' => 'error', 'message' => $exception->getMessage()];
        }
        fwrite($childSocket, json_encode($result, JSON_THROW_ON_ERROR)."\n");
        fclose($childSocket);
        DB::disconnect();
        exit(0);
    }
    fclose($childSocket);
    stream_set_timeout($parentSocket, 15);
    try {
        $backendPid = (int) fgets($parentSocket);
        DB::reconnect();
        DB::transaction(function () use ($workspace, $owner, $parentSocket, $backendPid): void {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            fwrite($parentSocket, "go\n");
            $deadline = microtime(true) + 5;
            do {
                $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendPid]);
                if ($waiting?->wait_event_type === 'Lock') {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($waiting?->wait_event_type !== 'Lock') {
                throw new RuntimeException('Competing invitation did not wait on the workspace lock.');
            }
            app(WorkspaceInvitationService::class)->issue($owner, $workspace, 'winner@example.test', WorkspaceMemberRole::Viewer);
        });
        $result = json_decode((string) fgets($parentSocket), true, flags: JSON_THROW_ON_ERROR);
        expect($result['status'])->toBe('validation');
        expect($result['fields'])->toContain('invitation');
        expect(WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->pending()->pluck('email')->all())->toBe(['winner@example.test']);
        Notification::assertSentOnDemandTimes(WorkspaceMemberInvitation::class, 1);
    } finally {
        fclose($parentSocket);
        pcntl_waitpid($pid, $status);
    }
});

it('accepts simultaneous replays once without duplicate membership or accounts', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires an explicitly permitted disposable PostgreSQL database and pcntl.');
    }
    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    config(['workspaces.collaboration_enabled' => true]);
    Notification::fake();
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($owner)->for($plan)->create();
    PlanLimit::factory()->for($plan)->create(['key' => 'workspace_members', 'value' => 2]);
    $recipient = User::factory()->create();
    $token = bin2hex(random_bytes(32));
    $invitation = WorkspaceInvitation::factory()->for($workspace)->create([
        'invited_by_user_id' => $owner->id,
        'email' => $recipient->email,
        'token_hash' => hash('sha256', $token),
    ]);
    $userCount = User::query()->count();
    DB::disconnect();
    [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the competing acceptance session.');
    }
    if ($pid === 0) {
        fclose($parentSocket);
        stream_set_timeout($childSocket, 15);
        try {
            DB::reconnect();
            DB::statement("SET statement_timeout = '12s'");
            fwrite($childSocket, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n");
            if (trim((string) fgets($childSocket)) !== 'go') {
                throw new RuntimeException('Parent did not release the competing session.');
            }
            $user = app(WorkspaceInvitationService::class)->accept($token, $recipient);
            $result = ['status' => 'accepted', 'user_id' => $user->id];
        } catch (Throwable $exception) {
            $result = ['status' => 'error', 'message' => $exception->getMessage()];
        }
        fwrite($childSocket, json_encode($result, JSON_THROW_ON_ERROR)."\n");
        fclose($childSocket);
        DB::disconnect();
        exit(0);
    }
    fclose($childSocket);
    stream_set_timeout($parentSocket, 15);
    try {
        $backendPid = (int) fgets($parentSocket);
        DB::reconnect();
        DB::transaction(function () use ($workspace, $recipient, $token, $parentSocket, $backendPid): void {
            User::query()->whereKey($recipient->id)->lockForUpdate()->firstOrFail();
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            fwrite($parentSocket, "go\n");
            $deadline = microtime(true) + 5;
            do {
                $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendPid]);
                if ($waiting?->wait_event_type === 'Lock') {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($waiting?->wait_event_type !== 'Lock') {
                throw new RuntimeException('Competing acceptance did not wait on a database lock.');
            }
            expect(app(WorkspaceInvitationService::class)->accept($token, $recipient)->id)->toBe($recipient->id);
        });
        $result = json_decode((string) fgets($parentSocket), true, flags: JSON_THROW_ON_ERROR);
        expect($result['status'])->toBe('accepted');
        expect($result['user_id'])->toBe($recipient->id);
        expect(WorkspaceMember::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('user_id', $recipient->id)->count())->toBe(1);
        expect(User::query()->count())->toBe($userCount);
        expect($invitation->fresh()->accepted_by_user_id)->toBe($recipient->id);
        Notification::assertNothingSent();
    } finally {
        fclose($parentSocket);
        pcntl_waitpid($pid, $status);
    }
});
