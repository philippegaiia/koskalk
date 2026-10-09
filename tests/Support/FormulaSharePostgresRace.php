<?php

namespace Tests\Support;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class FormulaSharePostgresRace
{
    /** @return array<string, mixed> */
    public static function run(Closure $childOperation, Closure $parentLocks, Closure $parentOperation, bool $repeatableParent = true, bool $pauseAfterActor = false): array
    {
        DB::disconnect();
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the competing Formula session.');
        }
        if ($pid === 0) {
            fclose($parentSocket);
            stream_set_timeout($childSocket, 20);
            try {
                DB::reconnect();
                DB::statement("SET statement_timeout = '15s'");
                if ($pauseAfterActor) {
                    $paused = false;
                    DB::listen(function (QueryExecuted $query) use ($childSocket, &$paused): void {
                        if (! $paused && str_contains($query->sql, '"users"') && str_contains($query->sql, 'for update')) {
                            $paused = true;
                            fwrite($childSocket, "snapshot\n");
                            if (trim((string) fgets($childSocket)) !== 'resume') {
                                throw new RuntimeException('Parent did not release the frozen actor snapshot.');
                            }
                        }
                    });
                }
                fwrite($childSocket, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n");
                if (trim((string) fgets($childSocket)) !== 'go') {
                    throw new RuntimeException('Parent did not start the competing Formula session.');
                }
                $result = ['status' => 'ok', 'value' => $childOperation()];
            } catch (ValidationException $exception) {
                $result = ['status' => 'validation', 'fields' => array_keys($exception->errors())];
            } catch (AuthorizationException) {
                $result = ['status' => 'authorization'];
            } catch (Throwable $exception) {
                $result = ['status' => 'error', 'message' => $exception->getMessage()];
            }
            fwrite($childSocket, json_encode($result, JSON_THROW_ON_ERROR)."\n");
            fclose($childSocket);
            DB::disconnect();
            exit(0);
        }
        fclose($childSocket);
        stream_set_timeout($parentSocket, 20);
        $finished = false;
        try {
            $backendPid = (int) fgets($parentSocket);
            DB::reconnect();
            DB::statement("SET statement_timeout = '15s'");
            DB::transaction(function () use ($parentLocks, $parentOperation, $parentSocket, $backendPid, $repeatableParent, $pauseAfterActor): void {
                if ($repeatableParent) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }
                $parentLocks();
                fwrite($parentSocket, "go\n");
                if ($pauseAfterActor) {
                    if (trim((string) fgets($parentSocket)) !== 'snapshot') {
                        throw new RuntimeException('Child did not freeze its snapshot after the actor query.');
                    }
                } else {
                    $deadline = microtime(true) + 5;
                    do {
                        $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendPid]);
                        if ($waiting?->wait_event_type === 'Lock') {
                            break;
                        }
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                    if ($waiting?->wait_event_type !== 'Lock') {
                        throw new RuntimeException('Competing Formula operation did not wait on the intended row lock.');
                    }
                }
                $parentOperation();
            });
            if ($pauseAfterActor) {
                fwrite($parentSocket, "resume\n");
            }
            $result = json_decode((string) fgets($parentSocket), true, flags: JSON_THROW_ON_ERROR);
            $finished = true;

            return $result;
        } finally {
            if (! $finished) {
                posix_kill($pid, SIGTERM);
            }
            fclose($parentSocket);
            pcntl_waitpid($pid, $status);
            DB::statement('SET statement_timeout = 0');
        }
    }
}
