<?php

declare(strict_types=1);

// Pages: list returns one page as the API sends it, and listAll walks every page by
// starting_after while has_more (api-docs-and-sdks.md section 1.4).

namespace Nowgate\Tests;

use Nowgate\Exception\NotFoundException;
use Nowgate\Transport\Request;
use Nowgate\Transport\Response;

/** @param list<int> $ids */
function page(array $ids, bool $hasMore): Response
{
    return Fake::json(200, ['object' => 'list', 'data' => array_map(static fn (int $n): array => ['id' => "pay_{$n}", 'object' => 'payment'], $ids), 'has_more' => $hasMore]);
}

/** @return list<string> */
function queries(Fake $fake): array
{
    return array_map(static fn (Request $r): string => (string) parse_url($r->url, PHP_URL_QUERY), $fake->sent);
}

test('list returns one page exactly as the API sends it', function (): void {
    $fake = new Fake([page([5, 4], true)]);
    $list = $fake->nowgate->payments->list(['limit' => 2, 'starting_after' => 'pay_6']);
    same(['object' => 'list', 'data' => [['id' => 'pay_5', 'object' => 'payment'], ['id' => 'pay_4', 'object' => 'payment']], 'has_more' => true], $list);
    same('https://sandbox-api.nowgate.io/v1/payments?limit=2&starting_after=pay_6', $fake->sent[0]->url);
});

test("listAll fetches pages of 100 as it's iterated, following starting_after", function (): void {
    $fake = new Fake([page([5, 4], true), page([3, 2], true), page([1], false)]);
    $walk = $fake->nowgate->payments->listAll(['status' => 'finished']);
    same([], $fake->sent, 'nothing is fetched before the walk starts');
    $ids = [];
    foreach ($walk as $payment) {
        $ids[] = $payment['id'];
    }
    same(['pay_5', 'pay_4', 'pay_3', 'pay_2', 'pay_1'], $ids);
    same(['status=finished&limit=100', 'status=finished&limit=100&starting_after=pay_4', 'status=finished&limit=100&starting_after=pay_2'], queries($fake));
});

test("listAll keeps the caller's limit and starting point, and stops early when told", function (): void {
    $fake = new Fake([page([5, 4], true), page([3, 2], true)]);
    $ids = [];
    foreach ($fake->nowgate->refunds->listAll(['limit' => 2, 'starting_after' => 'pay_6']) as $refund) {
        $ids[] = $refund['id'];
        if (count($ids) === 3) {
            break;
        }
    }
    same(['pay_5', 'pay_4', 'pay_3'], $ids);
    same(['limit=2&starting_after=pay_6', 'limit=2&starting_after=pay_4'], queries($fake));
});

test('listAll stops on an empty page, and refuses ending_before', function (): void {
    $fake = new Fake([page([], true)]);
    same([], iterator_to_array($fake->nowgate->payouts->listAll()));
    same(1, count($fake->sent));
    throws(\InvalidArgumentException::class, fn () => iterator_to_array($fake->nowgate->payouts->listAll(['ending_before' => 'po_1'])), '/not ending_before/');
});

test('each page is retried as any GET, and a failure ends the walk', function (): void {
    $fake = new Fake([page([2], true), Fake::problem(503, 'pool_exhausted'), page([1], false), Fake::problem(404, 'not_found')]);
    $ids = [];
    foreach ($fake->nowgate->paymentLinks->listAll() as $link) {
        $ids[] = $link['id'];
    }
    same(['pay_2', 'pay_1'], $ids);
    same(3, count($fake->sent));
    throws(NotFoundException::class, fn () => iterator_to_array($fake->nowgate->payoutBatches->listAll()));
});
