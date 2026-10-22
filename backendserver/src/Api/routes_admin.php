<?php
declare(strict_types=1);

use LifeOS\Auth;
use LifeOS\DB;
use LifeOS\Http;
use LifeOS\HttpError;
use LifeOS\Engine\Commission;

/** Admin API. */

$router->post('/api/admin/login', function () {
    $b = Http::body();
    $a = DB::one('SELECT * FROM admin_users WHERE email = ?', [$b['email'] ?? '']);
    if (!$a || !password_verify((string) ($b['password'] ?? ''), (string) ($a['password'] ?? ''))) {
        throw new HttpError('Invalid credentials', 401);
    }
    Http::ok(['token' => Auth::issue('a', (int) $a['id']), 'admin' => ['id' => (int) $a['id'], 'name' => $a['name'], 'email' => $a['email']]]);
});

$router->get('/api/admin/overview', function () {
    Auth::admin();
    Http::ok([
        'members' => (int) DB::val('SELECT COUNT(*) FROM users'),
        'active_members' => (int) DB::val('SELECT COUNT(*) FROM lo_tree WHERE is_active = 1'),
        'activations_pending' => (int) DB::val("SELECT COUNT(*) FROM lo_activations WHERE payment_status = 'PENDING'"),
        'matching_paid_total' => (float) DB::val("SELECT COALESCE(SUM(amount),0) FROM lo_ledger WHERE ledger_type = 'MATCHING' AND status = 'PAID'"),
        'pools' => DB::all('SELECT * FROM lo_pools'),
        'withdrawals_pending' => (int) DB::val("SELECT COUNT(*) FROM lo_withdrawals WHERE status = 'PENDING'"),
    ]);
});

$router->get('/api/admin/members', function () {
    Auth::admin();
    $q = '%' . ($_GET['q'] ?? '') . '%';
    Http::ok(DB::all(
        'SELECT u.id, u.life_id, u.phone, u.email, u.status, u.created_at, t.is_active, t.lifetime_matchings
         FROM users u LEFT JOIN lo_tree t ON t.user_id = u.id
         WHERE u.life_id LIKE ? OR u.phone LIKE ? ORDER BY u.id DESC LIMIT 200',
        [$q, $q]
    ));
});

$router->post('/api/admin/members/{id}/status', function ($p) {
    Auth::admin();
    $b = Http::body();
    if (!in_array($b['status'] ?? '', ['active', 'suspended', 'pending'], true)) throw new HttpError('Invalid status');
    DB::run('UPDATE users SET status = ? WHERE id = ?', [$b['status'], (int) $p['id']]);
    Http::ok();
});

// Payment gateway / manual confirmation: only SUCCESS triggers commissions.
$router->post('/api/admin/activations/{id}/confirm', function ($p) {
    Auth::admin();
    $b = Http::body();
    $status = $b['status'] ?? '';
    if (!in_array($status, ['SUCCESS', 'FAILED', 'CANCELLED', 'REFUNDED'], true)) throw new HttpError('Invalid status');
    $ref = $b['payment_ref'] ?? null;
    DB::tx(function () use ($p, $status, $ref) {
        $act = DB::one('SELECT * FROM lo_activations WHERE id = ? FOR UPDATE', [(int) $p['id']]);
        if (!$act) throw new HttpError('Activation not found', 404);
        if (in_array($act['payment_status'], ['CANCELLED', 'REFUNDED'], true)) return; // final states
        if ($act['payment_status'] === 'SUCCESS') {
            // Refund/chargeback after success: audited reversal, never deletion.
            if (in_array($status, ['CANCELLED', 'REFUNDED'], true)) Commission::reverseActivation((int) $p['id'], $status);
            return; // duplicate SUCCESS notification ignored
        }
        if ($ref) {
            // Unique payment_ref: same gateway trx can never confirm twice.
            DB::run('UPDATE lo_activations SET payment_ref = ? WHERE id = ?', [$ref, (int) $p['id']]);
        }
        DB::run('UPDATE lo_activations SET payment_status = ? WHERE id = ?', [$status, (int) $p['id']]);
        if ($status === 'SUCCESS') Commission::processActivation((int) $p['id']);
    });
    Http::ok();
});

$router->get('/api/admin/withdrawals', function () {
    Auth::admin();
    Http::ok(DB::all(
        'SELECT w.*, u.life_id, u.phone FROM lo_withdrawals w JOIN users u ON u.id = w.user_id ORDER BY w.id DESC LIMIT 200'
    ));
});

$router->post('/api/admin/withdrawals/{id}/process', function ($p) {
    Auth::admin();
    $b = Http::body();
    if (!in_array($b['status'] ?? '', ['APPROVED', 'REJECTED'], true)) throw new HttpError('Invalid status');
    DB::run('UPDATE lo_withdrawals SET status = ?, admin_note = ?, processed_at = NOW() WHERE id = ? AND status = "PENDING"',
        [$b['status'], $b['note'] ?? null, (int) $p['id']]);
    Http::ok();
});

$router->post('/api/admin/settings', function ($p) {
    Auth::admin();
    $b = Http::body();
    foreach (['matching_paused', 'daily_matching_cap', 'withdraw_min', 'withdraw_charge_pct'] as $k) {
        if (!isset($b[$k])) continue;
        // Plan rule: daily matching cap can be lowered but never raised above ৳5,000.
        if ($k === 'daily_matching_cap') {
            $v = (float) $b[$k];
            if (!is_finite($v) || $v < 0 || $v > \LifeOS\Engine\Plan::DAILY_MATCHING_CAP) throw new HttpError('daily_matching_cap must be between 0 and 5000', 422);
        }
        if ($k === 'matching_paused' && !in_array((string) $b[$k], ['0', '1'], true)) throw new HttpError('matching_paused must be 0 or 1', 422);
        DB::run('INSERT INTO lo_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, (string) $b[$k]]);
    }
    Http::ok();
});

// Rank & Reward fulfillment: a reached member's gift is paid from the RANK pool only,
// idempotent on the reference, and never borrows from another category.
$router->post('/api/admin/ranks/{id}/reward', function ($p) {
    Auth::admin();
    $b = Http::body();
    $userId = (int) $p['id'];
    $amount = (float) ($b['amount'] ?? 0);
    $reference = trim((string) ($b['reference'] ?? ''));
    if (!is_finite($amount) || $amount <= 0) throw new HttpError('amount must be positive', 422);
    if ($reference === '' || strlen($reference) > 60) throw new HttpError('reference required (max 60 chars)', 422);
    $rank = DB::one('SELECT r.name FROM lo_user_ranks ur JOIN lo_ranks r ON r.id = ur.rank_id WHERE ur.user_id = ? AND r.required_matchings > 0 ORDER BY r.required_matchings DESC LIMIT 1', [$userId]);
    if (!$rank) throw new HttpError('Member has no achieved rank', 422);
    Commission::poolSpend('RANK', $amount, 'RR-U' . $userId . '-' . $reference, 'Rank reward (' . $rank['name'] . ') for user ' . $userId);
    Http::ok(['user_id' => $userId, 'rank' => $rank['name'], 'amount' => $amount, 'reference' => $reference]);
});

$router->get('/api/admin/matching-events', function () {
    Auth::admin();
    Http::ok(DB::all('SELECT * FROM lo_matching_events ORDER BY id DESC LIMIT 300'));
});

$router->post('/api/admin/adjustments', function () {
    Auth::admin();
    $b = Http::body();
    if (empty($b['user_id']) || !isset($b['amount'])) throw new HttpError('user_id and amount required');
    DB::run(
        'INSERT INTO lo_ledger (payout_code, user_id, ledger_type, amount, note) VALUES (?,?,?,?,?)',
        ['ADJ-' . strtoupper(bin2hex(random_bytes(8))), (int) $b['user_id'], 'ADJUSTMENT', (float) $b['amount'], $b['note'] ?? 'Admin adjustment']
    );
    Http::ok();
});

// Daily statement closing: once per member per past day; a closed statement is never recalculated.
$router->post('/api/admin/statements/close', function () {
    Auth::admin();
    $b = Http::body();
    $date = (string) ($b['date'] ?? date('Y-m-d', strtotime('-1 day')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date >= date('Y-m-d')) throw new HttpError('Only a finished past day can be closed', 422);
    $closed = DB::tx(function () use ($date) {
        $rows = DB::all("SELECT beneficiary_id AS uid, COALESCE(SUM(CASE WHEN payout_status = 'PAID' THEN actual_payout ELSE 0 END),0) AS paid, COUNT(*) AS n
                         FROM lo_matching_events WHERE DATE(created_at) = ? GROUP BY beneficiary_id", [$date]);
        $n = 0;
        foreach ($rows as $r) {
            $n += DB::run('INSERT IGNORE INTO lo_statements (statement_code, user_id, statement_date, matching_paid, event_count) VALUES (?,?,?,?,?)',
                ['ST-' . str_replace('-', '', $date) . '-' . $r['uid'], (int) $r['uid'], $date, $r['paid'], (int) $r['n']]);
        }
        return $n;
    });
    Http::ok(['date' => $date, 'statements_closed' => $closed]);
});
