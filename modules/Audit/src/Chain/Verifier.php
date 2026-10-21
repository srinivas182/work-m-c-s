<?php

declare(strict_types=1);

namespace Wurk\Audit\Chain;

use Illuminate\Database\ConnectionInterface;

/**
 * Re-computes a chain from the first event: every event must follow the one
 * before it (seq + 1, prev_hash = previous hash) and hash to its stored value,
 * and the chain head must point at the last event. Any edit, insertion or
 * deletion breaks one of these.
 */
final class Verifier
{
    /** @return array{ok: bool, events: int, problem: string|null} */
    public function verify(ConnectionInterface $connection, string $scope): array
    {
        $expectedSeq = 1;
        $previousHash = Hasher::GENESIS;
        $count = 0;

        foreach ($connection->table('audit_events')->where('scope', $scope)->orderBy('seq')->lazy(500) as $event) {
            $row = (array) $event;

            if ((int) $row['seq'] !== $expectedSeq) {
                return ['ok' => false, 'events' => $count, 'problem' => "Event {$expectedSeq} is missing (found {$row['seq']})."];
            }
            if ($row['prev_hash'] !== $previousHash) {
                return ['ok' => false, 'events' => $count, 'problem' => "Event {$row['seq']} does not follow event ".($expectedSeq - 1).'.'];
            }
            if (! hash_equals((string) $row['hash'], Hasher::hash($row))) {
                return ['ok' => false, 'events' => $count, 'problem' => "Event {$row['seq']} was altered after it was written."];
            }

            $previousHash = (string) $row['hash'];
            $expectedSeq++;
            $count++;
        }

        $head = $connection->table('audit_chain_heads')->where('scope', $scope)->first();
        if ($head !== null && ((int) $head->seq !== $count || $head->hash !== $previousHash)) {
            return ['ok' => false, 'events' => $count, 'problem' => 'The newest events were removed (chain head is ahead of the log).'];
        }

        return ['ok' => true, 'events' => $count, 'problem' => null];
    }
}
