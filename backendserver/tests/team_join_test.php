<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use LifeOS\DB;
use LifeOS\Engine\Placement;

// Disposable local database only; run after run_tests.php.
$failures = 0; $count = 0;
function checkTeam(string $name, bool $ok): void {
    global $failures, $count; $count++; if (!$ok) $failures++;
    echo ($ok ? 'ok    ' : 'FAIL  ') . $name . "\n";
}
function joinRequest(array $body): array {
    $c = curl_init('http://127.0.0.1:8090/api/member/register');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body)]);
    $raw = curl_exec($c); $status = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return ['status' => $status, 'data' => json_decode((string) $raw, true)['data'] ?? []];
}
$existing = DB::one('SELECT referral_code FROM users ORDER BY id LIMIT 1');
$s = joinRequest(['name' => 'Team Sponsor', 'phone' => '01788000000', 'password' => 'testpass', 'referral_code' => $existing['referral_code']]);
checkTeam('create isolated sponsor', $s['status'] === 200);
$sid = (int) ($s['data']['user_id'] ?? 0);
$code = DB::val('SELECT referral_code FROM users WHERE id = ?', [$sid]);
$base = ['name' => 'Team Member', 'email' => 'team@example.test', 'blood_group' => 'O+', 'district' => 'Dhaka', 'post_code' => '1207', 'address_current' => 'Test address', 'nid_number' => 'TEST123', 'password' => 'testpass', 'confirm_password' => 'testpass', 'accepted_terms' => 1, 'referral_code' => $code];
function joinTeam(string $name, string $team, int $n): int {
    global $base;
    $r = joinRequest($base + ['username' => $name, 'team' => $team, 'phone' => '0178800000' . $n]);
    checkTeam("$name registers", $r['status'] === 200);
    return (int) ($r['data']['user_id'] ?? 0);
}
$left = joinTeam('leftone', 'L', 1);
$right = joinTeam('rightone', 'R', 2);
$ll = joinTeam('lefttwo', 'L', 3);
$lr = joinTeam('leftthree', 'L', 4);
$lll = joinTeam('leftfour', 'L', 5);
foreach ([[$left,$sid,'L'],[$right,$sid,'R'],[$ll,$left,'L'],[$lr,$left,'R'],[$lll,$ll,'L']] as [$id,$parent,$side]) {
    $row = DB::one('SELECT placement_parent_id, placement_side, sponsor_id FROM lo_tree WHERE user_id = ?', [$id]);
    checkTeam("placement $id stays in chosen subtree", (int) $row['placement_parent_id'] === $parent && $row['placement_side'] === $side);
    checkTeam("sponsor $id remains original referrer", (int) $row['sponsor_id'] === $sid);
}
$slot = Placement::preview($sid, 'R');
checkTeam('right preview ignores vacancies on left', $slot['parent_id'] === $right && $slot['side'] === 'L');
$profile = DB::one('SELECT * FROM user_profiles WHERE user_id = ?', [$left]);
checkTeam('registration fields persist', $profile['district'] === 'Dhaka' && $profile['post_code'] === '1207' && $profile['blood_group'] === 'O+' && $profile['nid_number'] === 'TEST123');
$bad = $base + ['username' => 'invalidteam', 'team' => 'X', 'phone' => '01788000006'];
checkTeam('invalid side rejected', joinRequest($bad)['status'] === 400);
$bad['team'] = 'L'; $bad['accepted_terms'] = 0;
checkTeam('terms required', joinRequest($bad)['status'] === 400);
$bad['accepted_terms'] = 1; $bad['confirm_password'] = 'different';
checkTeam('password confirmation required', joinRequest($bad)['status'] === 400);
$bad['confirm_password'] = 'testpass'; $bad['username'] = 'leftone';
checkTeam('duplicate username rejected', joinRequest($bad)['status'] === 409);
$bad['username'] = 'invalidphoto'; $bad['photo_base64'] = 'data:image/png;base64,YWJj';
checkTeam('non-image photo rejected', joinRequest($bad)['status'] === 400);
echo "$count checks, $failures failures\n";
exit($failures ? 1 : 0);